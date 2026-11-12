#!/usr/bin/env bash
# Confirm Dr.Slon Toolkit is an active release install on this WordPress.
# Self-contained: a buyer agent may run this file from a temporary copy.
set -euo pipefail

API_URL="https://api.github.com/repos/A-Krivoshen/dr-slon-toolkit/releases/latest"
RELEASE_BASE="https://github.com/A-Krivoshen/dr-slon-toolkit/releases/download"
SLUG="dr-slon-toolkit"
MAX_BYTES=52428800
WORKDIR=""

WP_PATH=""

cleanup() {
  case "$WORKDIR" in
    /tmp/*|/var/tmp/*)
      rm -rf "$WORKDIR"
      ;;
  esac
}
trap cleanup EXIT

usage() {
  cat <<'EOF'
Usage: verify.sh [--path DIR]

  --path DIR   WordPress root. Default: WP-CLI's current directory.

Exit 0 when WordPress, PHP, an active release layout, and HTTP 200 from the site home URL all pass.
update_available=yes means a newer GitHub Release exists. The plugin is still healthy.
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --path)
      if [[ $# -lt 2 ]]; then
        echo "Missing value for --path." >&2
        exit 2
      fi
      WP_PATH="$2"
      shift 2
      ;;
    --path=*)
      WP_PATH="${1#*=}"
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "Unknown argument: $1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

wp_cmd() {
  if [[ -n "$WP_PATH" ]]; then
    wp --path="$WP_PATH" "$@"
  else
    wp "$@"
  fi
}

fail() {
  local reason="$1"
  echo "status=failed"
  echo "reason=${reason}"
  exit 1
}

write_parser() {
  local dest="$1"
  cat >"$dest" <<'PHP'
<?php
declare(strict_types=1);

$path = $argv[1] ?? '';
$slug = $argv[2] ?? '';
$releaseBase = $argv[3] ?? '';
$maxBytes = (int) ($argv[4] ?? '0');
$raw = file_get_contents($path);
$payload = json_decode(is_string($raw) ? $raw : '', true);

if (!is_array($payload)) {
    fwrite(STDERR, "GitHub release response is not JSON.\n");
    exit(1);
}

$tag = isset($payload['tag_name']) && is_string($payload['tag_name']) ? trim($payload['tag_name']) : '';
$version = str_starts_with($tag, 'v') ? substr($tag, 1) : '';

if (
    preg_match('/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)\z/D', $version) !== 1
    || $tag !== 'v' . $version
    || !empty($payload['draft'])
    || !empty($payload['prerelease'])
    || !isset($payload['assets'])
    || !is_array($payload['assets'])
) {
    fwrite(STDERR, "Latest GitHub release is not a stable vX.Y.Z release.\n");
    exit(1);
}

$assetName = $slug . '-' . $version . '.zip';
$expectedUrl = $releaseBase . '/' . $tag . '/' . $assetName;
$matches = [];

foreach ($payload['assets'] as $asset) {
    if (is_array($asset) && ($asset['name'] ?? '') === $assetName) {
        $matches[] = $asset;
    }
}

if (count($matches) !== 1) {
    fwrite(STDERR, "Release is missing a single {$assetName} asset.\n");
    exit(1);
}

$asset = $matches[0];
$url = isset($asset['browser_download_url']) && is_string($asset['browser_download_url'])
    ? $asset['browser_download_url']
    : '';
$digest = isset($asset['digest']) && is_string($asset['digest']) ? strtolower($asset['digest']) : '';
$size = $asset['size'] ?? null;

if (
    ($asset['state'] ?? '') !== 'uploaded'
    || ($asset['content_type'] ?? '') !== 'application/zip'
    || $url !== $expectedUrl
    || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $digest) !== 1
    || !is_int($size)
    || $size < 1
    || $size > $maxBytes
) {
    fwrite(STDERR, "Release asset failed name, URL, type, size, or SHA-256 checks.\n");
    exit(1);
}

echo $version, "\n", $url, "\n", substr($digest, 7), "\n", $size, "\n", $assetName, "\n";
PHP
}

latest_version() {
  local workdir="$1"
  local json="$workdir/release.json"
  local parser="$workdir/parse.php"
  local http_code
  local fields
  local version
  local url
  local name
  local semver='^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$'

  write_parser "$parser"

  if ! http_code="$(curl -sS -o "$json" -w '%{http_code}' \
    -H 'Accept: application/vnd.github+json' \
    -H 'User-Agent: dr-slon-toolkit-skill' \
    "$API_URL")"; then
    echo "Could not reach the GitHub releases API." >&2
    return 1
  fi

  if [[ "$http_code" != "200" ]]; then
    echo "GitHub releases API returned HTTP ${http_code}." >&2
    return 1
  fi

  if ! fields="$(php "$parser" "$json" "$SLUG" "$RELEASE_BASE" "$MAX_BYTES")"; then
    return 1
  fi

  version="$(printf '%s\n' "$fields" | sed -n '1p')"
  url="$(printf '%s\n' "$fields" | sed -n '2p')"
  name="$(printf '%s\n' "$fields" | sed -n '5p')"

  if [[ ! "$version" =~ $semver ]] \
    || [[ "$url" != "${RELEASE_BASE}/v${version}/${SLUG}-${version}.zip" ]] \
    || [[ "$name" != "${SLUG}-${version}.zip" ]]; then
    echo "Resolved release failed the local asset checks." >&2
    return 1
  fi

  printf '%s\n' "$version"
}

main() {
  local wp_version
  local site_php
  local main_file
  local plugin_dir
  local installed
  local workdir
  local latest
  local update_available
  local site_url
  local http_code

  if ! command -v php >/dev/null 2>&1; then
    fail "php_cli_missing"
  fi
  if ! php -r 'exit(version_compare(PHP_VERSION, "8.1", ">=") ? 0 : 1);'; then
    fail "php_cli_too_old"
  fi
  if ! command -v curl >/dev/null 2>&1; then
    fail "curl_missing"
  fi
  if ! command -v wp >/dev/null 2>&1; then
    fail "wp_cli_missing"
  fi
  if [[ -n "$WP_PATH" && ! -d "$WP_PATH" ]]; then
    fail "wordpress_path_missing"
  fi
  if [[ "$(id -u)" -eq 0 && "${WP_CLI_ALLOW_ROOT:-}" != "1" ]]; then
    fail "refusing_root"
  fi
  if ! wp_cmd core is-installed >/dev/null; then
    fail "wordpress_not_installed"
  fi

  wp_version="$(wp_cmd core version | tail -n 1)"
  site_php="$(wp_cmd eval 'echo PHP_VERSION;')"

  if ! php -r "exit(version_compare(\$argv[1], \"6.6\", \">=\") ? 0 : 1);" "$wp_version"; then
    echo "status=failed"
    echo "reason=wordpress_too_old"
    echo "wp=${wp_version}"
    exit 1
  fi
  if ! php -r "exit(version_compare(\$argv[1], \"8.1\", \">=\") ? 0 : 1);" "$site_php"; then
    echo "status=failed"
    echo "reason=php_too_old"
    echo "php=${site_php}"
    exit 1
  fi
  if ! wp_cmd plugin is-installed "$SLUG" >/dev/null; then
    fail "plugin_not_installed"
  fi
  if ! wp_cmd plugin is-active "$SLUG" >/dev/null; then
    fail "plugin_inactive"
  fi

  site_url="$(wp_cmd option get home | tail -n 1)"
  if [[ ! "$site_url" =~ ^https?://[^[:space:]@]+$ ]]; then
    fail "site_url_invalid"
  fi

  if ! http_code="$(curl -sS -o /dev/null -w '%{http_code}' \
    --max-time 30 \
    --max-redirs 5 \
    -L \
    -A 'dr-slon-toolkit-skill' \
    "$site_url")"; then
    fail "site_unreachable"
  fi
  if [[ ! "$http_code" =~ ^[0-9]{3}$ ]] || [[ "$http_code" != "200" ]]; then
    echo "status=failed"
    echo "reason=site_http"
    echo "http=${http_code}"
    exit 1
  fi

  installed="$(wp_cmd plugin get "$SLUG" --field=version)"
  if [[ ! "$installed" =~ ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$ ]]; then
    fail "plugin_version_unreadable"
  fi

  main_file="$(wp_cmd plugin path "$SLUG")"
  plugin_dir="$(dirname "$main_file")"

  if [[ ! -f "$main_file" ]] \
    || ! grep -q 'Plugin Name: Dr.Slon Toolkit' "$main_file" \
    || ! grep -F -q 'DrSlon\Toolkit' "$main_file"; then
    fail "unexpected_plugin_header"
  fi
  if [[ ! -f "${plugin_dir}/src/Core/Plugin.php" ]] \
    || [[ ! -f "${plugin_dir}/vendor/autoload.php" ]]; then
    fail "release_files_missing"
  fi
  if [[ -e "${plugin_dir}/composer.json" ]] \
    || [[ -d "${plugin_dir}/tests" ]] \
    || [[ -d "${plugin_dir}/.git" ]]; then
    fail "source_archive_layout"
  fi

  umask 077
  WORKDIR="$(mktemp -d)"
  workdir="$WORKDIR"

  latest="unavailable"
  update_available="unknown"
  if latest="$(latest_version "$workdir")"; then
    if php -r "exit(version_compare(\$argv[1], \$argv[2], \">=\") ? 0 : 1);" "$installed" "$latest"; then
      update_available="no"
    else
      update_available="yes"
    fi
  else
    latest="unavailable"
    update_available="unknown"
  fi

  echo "status=ok"
  echo "wp=${wp_version}"
  echo "php=${site_php}"
  echo "plugin=${SLUG}"
  echo "installed=${installed}"
  echo "active=yes"
  echo "http=200"
  echo "release_layout=yes"
  echo "latest=${latest}"
  echo "update_available=${update_available}"
}

main "$@"
