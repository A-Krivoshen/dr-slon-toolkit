#!/usr/bin/env bash
# Install the latest Dr.Slon Toolkit GitHub Release with WP-CLI.
# The asset name is dr-slon-toolkit-<version>.zip from /releases/latest.
# Self-contained: a buyer agent may run this file from a temporary copy.
set -euo pipefail

API_URL="https://api.github.com/repos/A-Krivoshen/dr-slon-toolkit/releases/latest"
RELEASE_BASE="https://github.com/A-Krivoshen/dr-slon-toolkit/releases/download"
SLUG="dr-slon-toolkit"
MAX_BYTES=52428800
WORKDIR=""

WP_PATH=""
NETWORK=0
CHECK_ONLY=0

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
Usage: install.sh [--path DIR] [--network] [--check]

  --path DIR   WordPress root. Default: WP-CLI's current directory.
  --network    Network-activate on multisite.
  --check      Print the latest release asset and exit. No download, no install.
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
    --network)
      NETWORK=1
      shift
      ;;
    --check)
      CHECK_ONLY=1
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

require_php() {
  if ! command -v php >/dev/null 2>&1; then
    echo "PHP CLI is required." >&2
    exit 1
  fi
  if ! php -r 'exit(version_compare(PHP_VERSION, "8.1", ">=") ? 0 : 1);'; then
    echo "PHP CLI must be 8.1 or newer." >&2
    exit 1
  fi
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

resolve_release() {
  local workdir="$1"
  local json="$workdir/release.json"
  local parser="$workdir/parse.php"
  local http_code
  local fields

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

  VERSION="$(printf '%s\n' "$fields" | sed -n '1p')"
  PACKAGE_URL="$(printf '%s\n' "$fields" | sed -n '2p')"
  PACKAGE_SHA="$(printf '%s\n' "$fields" | sed -n '3p')"
  PACKAGE_SIZE="$(printf '%s\n' "$fields" | sed -n '4p')"
  PACKAGE_NAME="$(printf '%s\n' "$fields" | sed -n '5p')"

  local semver='^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$'
  local expected_url="${RELEASE_BASE}/v${VERSION}/${SLUG}-${VERSION}.zip"
  local expected_name="${SLUG}-${VERSION}.zip"

  if [[ ! "$VERSION" =~ $semver ]] \
    || [[ "$PACKAGE_URL" != "$expected_url" ]] \
    || [[ "$PACKAGE_NAME" != "$expected_name" ]] \
    || [[ ! "$PACKAGE_SHA" =~ ^[a-f0-9]{64}$ ]] \
    || [[ ! "$PACKAGE_SIZE" =~ ^[0-9]+$ ]] \
    || [[ "$PACKAGE_SIZE" -lt 1 ]] \
    || [[ "$PACKAGE_SIZE" -gt "$MAX_BYTES" ]]; then
    echo "Resolved release failed the local asset checks." >&2
    return 1
  fi
}

assert_zip_layout() {
  local zip="$1"
  local entries
  local needle

  if ! unzip -t -q "$zip" >/dev/null; then
    echo "Release ZIP failed the integrity test." >&2
    return 1
  fi

  if ! entries="$(unzip -Z1 "$zip")"; then
    echo "Info-ZIP unzip is required to list the release archive." >&2
    return 1
  fi

  if printf '%s\n' "$entries" | grep -q '\.\.'; then
    echo "Release ZIP has an unsafe path." >&2
    return 1
  fi

  if printf '%s\n' "$entries" | grep -qv "^${SLUG}/"; then
    echo "Release ZIP root is not ${SLUG}/." >&2
    return 1
  fi

  for needle in \
    "${SLUG}/${SLUG}.php" \
    "${SLUG}/src/Core/Plugin.php" \
    "${SLUG}/vendor/autoload.php"
  do
    if ! printf '%s\n' "$entries" | grep -Fxq "$needle"; then
      echo "Release ZIP is missing ${needle}." >&2
      return 1
    fi
  done

  if printf '%s\n' "$entries" | grep -Eq "^(${SLUG}/composer\\.json|${SLUG}/tests(/|$)|${SLUG}/\\.git(/|$))"; then
    echo "Release ZIP looks like a source archive." >&2
    return 1
  fi
}

download_zip() {
  local zip="$1"
  local http_code
  local actual_size
  local actual_sha

  if ! http_code="$(curl -sS -L -o "$zip" -w '%{http_code}' \
    -H 'Accept: application/octet-stream' \
    -H 'User-Agent: dr-slon-toolkit-skill' \
    "$PACKAGE_URL")"; then
    echo "Could not download the release asset." >&2
    return 1
  fi

  if [[ "$http_code" != "200" ]]; then
    echo "Release download returned HTTP ${http_code}." >&2
    return 1
  fi

  actual_size="$(wc -c <"$zip" | tr -d '[:space:]')"
  if [[ "$actual_size" != "$PACKAGE_SIZE" ]]; then
    echo "Release download size does not match the GitHub asset." >&2
    return 1
  fi

  actual_sha="$(php -r "echo hash_file(\"sha256\", \$argv[1]);" "$zip")"
  if [[ "$actual_sha" != "$PACKAGE_SHA" ]]; then
    echo "Release download SHA-256 does not match the GitHub asset." >&2
    return 1
  fi

  assert_zip_layout "$zip"
}

require_site() {
  if ! command -v wp >/dev/null 2>&1; then
    echo "WP-CLI is required." >&2
    exit 1
  fi
  if ! command -v unzip >/dev/null 2>&1; then
    echo "Info-ZIP unzip is required." >&2
    exit 1
  fi
  if [[ -n "$WP_PATH" && ! -d "$WP_PATH" ]]; then
    echo "WordPress path is not a directory." >&2
    exit 1
  fi
  if [[ "$(id -u)" -eq 0 && "${WP_CLI_ALLOW_ROOT:-}" != "1" ]]; then
    echo "WP-CLI will not run as root. Re-run as the site user, or set WP_CLI_ALLOW_ROOT=1 after the user agrees." >&2
    exit 1
  fi
  if ! wp_cmd core is-installed >/dev/null; then
    echo "WP-CLI did not find an installed WordPress." >&2
    exit 1
  fi

  local wp_version
  local site_php
  wp_version="$(wp_cmd core version | tail -n 1)"
  site_php="$(wp_cmd eval 'echo PHP_VERSION;')"

  if ! php -r "exit(version_compare(\$argv[1], \"6.6\", \">=\") ? 0 : 1);" "$wp_version"; then
    echo "WordPress must be 6.6 or newer. Found ${wp_version}." >&2
    exit 1
  fi
  if ! php -r "exit(version_compare(\$argv[1], \"8.1\", \">=\") ? 0 : 1);" "$site_php"; then
    echo "Site PHP must be 8.1 or newer. Found ${site_php}." >&2
    exit 1
  fi

  if [[ "$NETWORK" -eq 1 ]]; then
    if ! wp_cmd core is-installed --network >/dev/null; then
      echo "This WordPress is not multisite. Omit --network." >&2
      exit 1
    fi
  fi
}

plugin_is_current() {
  local installed

  if ! wp_cmd plugin is-installed "$SLUG" >/dev/null; then
    return 1
  fi
  if [[ "$NETWORK" -eq 1 ]]; then
    if ! wp_cmd plugin is-active "$SLUG" --network >/dev/null; then
      return 1
    fi
  elif ! wp_cmd plugin is-active "$SLUG" >/dev/null; then
    return 1
  fi

  installed="$(wp_cmd plugin get "$SLUG" --field=version)"
  php -r "exit(version_compare(\$argv[1], \$argv[2], \">=\") ? 0 : 1);" "$installed" "$VERSION"
}

activate_plugin() {
  local zip="$1"
  local -a install_args

  install_args=(--force)
  if [[ "$NETWORK" -eq 1 ]]; then
    install_args+=(--activate-network)
  else
    install_args+=(--activate)
  fi

  wp_cmd plugin install "$zip" "${install_args[@]}"
}

main() {
  local workdir
  local zip

  require_php
  if ! command -v curl >/dev/null 2>&1; then
    echo "curl is required." >&2
    exit 1
  fi

  umask 077
  WORKDIR="$(mktemp -d)"
  workdir="$WORKDIR"

  if [[ "$CHECK_ONLY" -eq 1 ]]; then
    resolve_release "$workdir"
    printf 'version=%s\nasset=%s\nurl=%s\nsize=%s\n' \
      "$VERSION" "$PACKAGE_NAME" "$PACKAGE_URL" "$PACKAGE_SIZE"
    exit 0
  fi

  require_site
  resolve_release "$workdir"

  if plugin_is_current; then
    echo "${SLUG} ${VERSION} is already active."
    exit 0
  fi

  zip="$workdir/${PACKAGE_NAME}"
  download_zip "$zip"
  activate_plugin "$zip"

  if ! plugin_is_current; then
    echo "Install finished, but ${SLUG} ${VERSION} is not the active release." >&2
    exit 1
  fi

  echo "Installed and activated ${SLUG} ${VERSION}."
}

main "$@"
