<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Integrations;

final class GitHubReleaseUpdater
{
    public const CACHE_KEY = 'dstk_github_release_v1';

    private const CACHE_SCHEMA = 2;
    private const API_URL = 'https://api.github.com/repos/A-Krivoshen/dr-slon-toolkit/releases/latest';
    private const UPDATE_URI = 'https://github.com/A-Krivoshen/dr-slon-toolkit';
    private const SLUG = 'dr-slon-toolkit';
    private const SUCCESS_TTL = 12 * HOUR_IN_SECONDS;
    private const FAILURE_TTL = HOUR_IN_SECONDS;
    private const MAX_STALE_AGE = 7 * DAY_IN_SECONDS;
    private const MAX_ASSET_SIZE = 52428800;

    private string $plugin_basename;
    private string $current_version;

    /** @var array<string,mixed>|null */
    private ?array $memory_cache = null;

    public function __construct(string $plugin_file, string $current_version)
    {
        $this->plugin_basename = plugin_basename($plugin_file);
        $this->current_version = $current_version;
    }

    public function register(): void
    {
        add_filter('update_plugins_github.com', [$this, 'filter_update'], 10, 4);
        add_filter('plugins_api', [$this, 'filter_plugin_information'], 20, 3);
        add_filter('upgrader_pre_download', [$this, 'verify_download'], PHP_INT_MAX, 4);
        add_filter('upgrader_source_selection', [$this, 'validate_package_source'], PHP_INT_MAX, 4);
        add_action('upgrader_process_complete', [$this, 'clear_after_upgrade'], 10, 2);
    }

    public function filter_update(mixed $update, mixed $plugin_data, mixed $plugin_file, mixed $locales): mixed
    {
        unset($locales);

        if (
            ! is_array($plugin_data)
            || ! is_string($plugin_file)
            || $plugin_file !== $this->plugin_basename
            || ($plugin_data['UpdateURI'] ?? '') !== self::UPDATE_URI
        ) {
            return $update;
        }

        $release = $this->release();

        if ($release === null) {
            return $update;
        }

        return [
            'slug'         => self::SLUG,
            'version'      => $release['version'],
            'url'          => $release['details_url'],
            'package'      => $release['package_url'],
            'requires'     => '6.6',
            'tested'       => '7.0',
            'requires_php' => '8.1',
            'icons'        => [],
            'banners'      => [],
            'banners_rtl'  => [],
            'dstk_release_tag'    => $release['tag'],
            'dstk_release_sha256' => $release['sha256'],
            'dstk_release_size'   => $release['asset_size'],
        ];
    }

    public function filter_plugin_information(mixed $result, mixed $action, mixed $args): mixed
    {
        if (
            $action !== 'plugin_information'
            || ! is_object($args)
            || ! isset($args->slug)
            || $args->slug !== self::SLUG
        ) {
            return $result;
        }

        $release = $this->release();
        $version = $release['version'] ?? $this->current_version;
        $notes = isset($release['notes']) && is_string($release['notes'])
            ? $release['notes']
            : __('Информация о выпуске временно недоступна.', 'dr-slon-toolkit');

        return (object) [
            'name'          => 'Dr.Slon Toolkit',
            'slug'          => self::SLUG,
            'version'       => $version,
            'author'        => '<a href="https://krivoshein.site">Dr.Slon</a>',
            'homepage'      => self::UPDATE_URI,
            'requires'      => '6.6',
            'tested'        => '7.0',
            'requires_php'  => '8.1',
            'last_updated'  => $release['published_at'] ?? '',
            'download_link' => $release['package_url'] ?? '',
            'sections'      => [
                'description' => esc_html__('Модульный набор инструментов для обслуживания WordPress-сайтов.', 'dr-slon-toolkit'),
                'changelog'   => wpautop(esc_html($notes)),
            ],
        ];
    }

    public function verify_download(mixed $reply, mixed $package, mixed $upgrader, mixed $hook_extra): mixed
    {
        unset($upgrader);

        if (! $this->is_target_upgrade($hook_extra)) {
            return $reply;
        }

        if (is_wp_error($reply)) {
            return $reply;
        }

        if (! is_string($package)) {
            return new \WP_Error('dstk_update_package', __('Не удалось определить пакет обновления Dr.Slon Toolkit.', 'dr-slon-toolkit'));
        }

        $release = $this->offered_release();

        if ($release === null || ! hash_equals($release['package_url'], $package)) {
            return new \WP_Error('dstk_update_package', __('Пакет обновления Dr.Slon Toolkit не соответствует сохранённому предложению обновления.', 'dr-slon-toolkit'));
        }

        $downloaded_here = false;

        if ($reply === false) {
            if (! function_exists('download_url')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }

            $file = download_url($package, 300, false);
            $downloaded_here = true;
        } elseif (is_string($reply)) {
            $file = $reply;
        } else {
            return new \WP_Error('dstk_update_file', __('Не удалось определить локальный файл обновления Dr.Slon Toolkit.', 'dr-slon-toolkit'));
        }

        if (is_wp_error($file)) {
            return $file;
        }

        if (! is_string($file)) {
            return new \WP_Error('dstk_update_file', __('Не удалось определить локальный файл обновления Dr.Slon Toolkit.', 'dr-slon-toolkit'));
        }

        clearstatcache(true, $file);
        $size = is_file($file) && is_readable($file) ? filesize($file) : false;

        if (
            ! is_int($size)
            || $size < 1
            || $size > self::MAX_ASSET_SIZE
            || $size !== $release['asset_size']
        ) {
            $this->delete_downloaded_file($file, $downloaded_here);
            return new \WP_Error('dstk_update_size', __('Размер пакета обновления Dr.Slon Toolkit не совпал.', 'dr-slon-toolkit'));
        }

        $digest = hash_file('sha256', $file);

        if (! is_string($digest) || ! hash_equals($release['sha256'], $digest)) {
            $this->delete_downloaded_file($file, $downloaded_here);
            return new \WP_Error('dstk_update_digest', __('Контрольная сумма обновления Dr.Slon Toolkit не совпала.', 'dr-slon-toolkit'));
        }

        return $file;
    }

    public function validate_package_source(mixed $source, mixed $remote_source, mixed $upgrader, mixed $hook_extra): mixed
    {
        unset($remote_source, $upgrader);

        if (! $this->is_target_upgrade($hook_extra) || is_wp_error($source) || ! is_string($source)) {
            return $source;
        }

        $release = $this->offered_release();

        if ($release === null) {
            return new \WP_Error('dstk_update_offer', __('Не удалось проверить сохранённое предложение обновления Dr.Slon Toolkit.', 'dr-slon-toolkit'));
        }

        $filesystem = $this->filesystem();

        if ($filesystem === null) {
            return new \WP_Error('dstk_update_filesystem', __('Не удалось подключить файловую систему WordPress для проверки обновления.', 'dr-slon-toolkit'));
        }

        $root = untrailingslashit($source);
        $main_file = $root . '/dr-slon-toolkit.php';
        $readme_file = $root . '/readme.txt';
        $autoloader = $root . '/vendor/autoload.php';
        $root_name = basename(str_replace('\\', '/', $root));

        if (
            $root_name !== self::SLUG
            || ! $filesystem->is_file($main_file)
            || ! $filesystem->is_file($readme_file)
            || ! $filesystem->is_file($autoloader)
        ) {
            return new \WP_Error('dstk_update_structure', __('Архив обновления Dr.Slon Toolkit имеет неверную структуру.', 'dr-slon-toolkit'));
        }

        $contents = $filesystem->get_contents($main_file);
        $readme = $filesystem->get_contents($readme_file);

        if (! is_string($contents) || ! is_string($readme)) {
            return new \WP_Error('dstk_update_header', __('Не удалось прочитать метаданные обновления Dr.Slon Toolkit.', 'dr-slon-toolkit'));
        }

        preg_match('/^[ \t]*\* Version:\s*([^\r\n]+)/m', $contents, $version_match);
        preg_match('/^[ \t]*\* Update URI:\s*([^\r\n]+)/m', $contents, $uri_match);
        preg_match('/^[ \t]*const[ \t]+DSTK_VERSION[ \t]*=[ \t]*[\'\"]([^\'\"]+)[\'\"][ \t]*;[ \t]*$/m', $contents, $constant_match);
        preg_match('/^Stable tag:\s*([^\r\n]+)/mi', $readme, $stable_match);

        if (
            trim((string) ($version_match[1] ?? '')) !== $release['version']
            || trim((string) ($constant_match[1] ?? '')) !== $release['version']
            || trim((string) ($stable_match[1] ?? '')) !== $release['version']
            || trim((string) ($uri_match[1] ?? '')) !== self::UPDATE_URI
        ) {
            return new \WP_Error('dstk_update_header', __('Версия или Update URI в архиве обновления не прошли проверку.', 'dr-slon-toolkit'));
        }

        return $source;
    }

    public function clear_after_upgrade(mixed $upgrader, mixed $hook_extra): void
    {
        unset($upgrader);

        if (! $this->is_target_upgrade($hook_extra)) {
            return;
        }

        $this->clear_cache();
        delete_site_transient('update_plugins');
    }

    public function clear_cache(): void
    {
        $this->memory_cache = null;
        delete_site_transient(self::CACHE_KEY);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    public static function parse_release_payload(array $payload): ?array
    {
        $tag = isset($payload['tag_name']) && is_string($payload['tag_name']) ? trim($payload['tag_name']) : '';
        $version = str_starts_with($tag, 'v') ? substr($tag, 1) : '';

        if (
            preg_match('/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)\z/D', $version) !== 1
            || $tag !== 'v' . $version
            || ! empty($payload['draft'])
            || ! empty($payload['prerelease'])
            || ! isset($payload['assets'])
            || ! is_array($payload['assets'])
        ) {
            return null;
        }

        $asset_name = self::SLUG . '-' . $version . '.zip';
        $expected_url = self::UPDATE_URI . '/releases/download/' . $tag . '/' . $asset_name;
        $matches = [];

        foreach ($payload['assets'] as $asset) {
            if (is_array($asset) && ($asset['name'] ?? '') === $asset_name) {
                $matches[] = $asset;
            }
        }

        if (count($matches) !== 1) {
            return null;
        }

        $asset = $matches[0];
        $package_url = isset($asset['browser_download_url']) && is_string($asset['browser_download_url'])
            ? $asset['browser_download_url']
            : '';
        $digest = isset($asset['digest']) && is_string($asset['digest']) ? strtolower($asset['digest']) : '';
        $size = $asset['size'] ?? null;

        if (
            ($asset['state'] ?? '') !== 'uploaded'
            || ($asset['content_type'] ?? '') !== 'application/zip'
            || $package_url !== $expected_url
            || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $digest) !== 1
            || ! is_int($size)
            || $size < 1
            || $size > self::MAX_ASSET_SIZE
        ) {
            return null;
        }

        $notes = isset($payload['body']) && is_string($payload['body']) ? $payload['body'] : '';

        return [
            'version'      => $version,
            'tag'          => $tag,
            'details_url'  => self::UPDATE_URI . '/releases/tag/' . $tag,
            'package_url'  => $package_url,
            'published_at' => isset($payload['published_at']) && is_string($payload['published_at']) ? $payload['published_at'] : '',
            'notes'        => substr($notes, 0, 100000),
            'asset_name'   => $asset_name,
            'asset_size'   => $size,
            'sha256'       => substr($digest, 7),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function release(): ?array
    {
        if ($this->memory_cache !== null) {
            return isset($this->memory_cache['release']) && is_array($this->memory_cache['release'])
                ? $this->memory_cache['release']
                : null;
        }

        $cached = get_site_transient(self::CACHE_KEY);
        $cached = is_array($cached) ? $cached : [];
        $now = time();
        $release = $this->cached_release($cached, $now);

        if ((int) ($cached['schema'] ?? 0) === self::CACHE_SCHEMA && (int) ($cached['next_check'] ?? 0) > $now) {
            $this->memory_cache = [
                'release' => $release,
            ];
            return $release;
        }

        $response = wp_safe_remote_get(
            self::API_URL,
            [
                'timeout'             => 5,
                'redirection'         => 2,
                'sslverify'           => true,
                'limit_response_size' => 524288,
                'headers'             => [
                    'Accept'               => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                    'User-Agent'           => 'Dr-Slon-Toolkit/' . $this->current_version,
                ],
            ]
        );

        $fetched_at = $release !== null ? (int) $cached['fetched_at'] : 0;
        $next_check = $now + self::FAILURE_TTL;

        if (! is_wp_error($response)) {
            $status = (int) wp_remote_retrieve_response_code($response);

            if ($status === 200) {
                $payload = json_decode((string) wp_remote_retrieve_body($response), true);
                $validated = is_array($payload) ? self::parse_release_payload($payload) : null;

                if ($validated !== null) {
                    $release = $validated;
                    $fetched_at = $now;
                    $next_check = $now + self::SUCCESS_TTL;
                }
            } elseif (in_array($status, [403, 429], true)) {
                $next_check = $now + $this->rate_limit_delay($response, $now);
            }
        }

        $this->memory_cache = [
            'schema'     => self::CACHE_SCHEMA,
            'next_check' => $next_check,
            'fetched_at' => $fetched_at,
            'release'    => $release,
        ];
        $cache_ttl = max(1, $next_check - $now);

        if ($release !== null) {
            $cache_ttl = max($cache_ttl, max(1, ($fetched_at + self::MAX_STALE_AGE) - $now));
        }

        set_site_transient(self::CACHE_KEY, $this->memory_cache, $cache_ttl);

        return $release;
    }

    /**
     * @param array<string,mixed> $cached
     * @return array<string,mixed>|null
     */
    private function cached_release(array $cached, int $now): ?array
    {
        $fetched_at = $cached['fetched_at'] ?? null;

        if (
            (int) ($cached['schema'] ?? 0) !== self::CACHE_SCHEMA
            || ! is_int($fetched_at)
            || $fetched_at < 1
            || $fetched_at > $now
            || ($now - $fetched_at) > self::MAX_STALE_AGE
            || ! isset($cached['release'])
            || ! is_array($cached['release'])
        ) {
            return null;
        }

        return self::normalize_release_metadata($cached['release']);
    }

    private function rate_limit_delay(mixed $response, int $now): int
    {
        $retry_after = wp_remote_retrieve_header($response, 'retry-after');

        if (is_numeric($retry_after)) {
            return min(DAY_IN_SECONDS, max(15 * MINUTE_IN_SECONDS, (int) $retry_after));
        }

        $reset = wp_remote_retrieve_header($response, 'x-ratelimit-reset');

        if (is_numeric($reset)) {
            return min(DAY_IN_SECONDS, max(15 * MINUTE_IN_SECONDS, ((int) $reset - $now) + 60));
        }

        return self::FAILURE_TTL;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function offered_release(): ?array
    {
        $updates = get_site_transient('update_plugins');

        if (is_object($updates)) {
            $responses = $updates->response ?? null;
        } elseif (is_array($updates)) {
            $responses = $updates['response'] ?? null;
        } else {
            return null;
        }

        if (! is_array($responses) || ! array_key_exists($this->plugin_basename, $responses)) {
            return null;
        }

        $offer = $responses[$this->plugin_basename];

        if (is_object($offer)) {
            $offer = get_object_vars($offer);
        }

        if (! is_array($offer)) {
            return null;
        }

        return self::normalize_release_metadata(
            [
                'version'     => $offer['version'] ?? null,
                'tag'         => $offer['dstk_release_tag'] ?? null,
                'details_url' => $offer['url'] ?? null,
                'package_url' => $offer['package'] ?? null,
                'asset_size'  => $offer['dstk_release_size'] ?? null,
                'sha256'      => $offer['dstk_release_sha256'] ?? null,
            ]
        );
    }

    /**
     * @param array<string,mixed> $release
     * @return array<string,mixed>|null
     */
    private static function normalize_release_metadata(array $release): ?array
    {
        $version = $release['version'] ?? null;
        $tag = $release['tag'] ?? null;
        $details_url = $release['details_url'] ?? null;
        $package_url = $release['package_url'] ?? null;
        $asset_size = $release['asset_size'] ?? null;
        $sha256 = $release['sha256'] ?? null;

        if (
            ! is_string($version)
            || preg_match('/\A(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)\z/D', $version) !== 1
            || ! is_string($tag)
            || $tag !== 'v' . $version
            || ! is_string($details_url)
            || $details_url !== self::UPDATE_URI . '/releases/tag/' . $tag
            || ! is_string($package_url)
            || $package_url !== self::UPDATE_URI . '/releases/download/' . $tag . '/' . self::SLUG . '-' . $version . '.zip'
            || ! is_int($asset_size)
            || $asset_size < 1
            || $asset_size > self::MAX_ASSET_SIZE
            || ! is_string($sha256)
            || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1
        ) {
            return null;
        }

        $notes = isset($release['notes']) && is_string($release['notes']) ? $release['notes'] : '';

        return [
            'version'      => $version,
            'tag'          => $tag,
            'details_url'  => $details_url,
            'package_url'  => $package_url,
            'published_at' => isset($release['published_at']) && is_string($release['published_at']) ? $release['published_at'] : '',
            'notes'        => substr($notes, 0, 100000),
            'asset_name'   => self::SLUG . '-' . $version . '.zip',
            'asset_size'   => $asset_size,
            'sha256'       => $sha256,
        ];
    }

    private function filesystem(): ?object
    {
        global $wp_filesystem;

        if (! is_object($wp_filesystem)) {
            if (! function_exists('WP_Filesystem')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }

            if (! WP_Filesystem() || ! is_object($wp_filesystem)) {
                return null;
            }
        }

        if (! method_exists($wp_filesystem, 'is_file') || ! method_exists($wp_filesystem, 'get_contents')) {
            return null;
        }

        return $wp_filesystem;
    }

    private function delete_downloaded_file(string $file, bool $downloaded_here): void
    {
        if ($downloaded_here) {
            wp_delete_file($file);
        }
    }

    private function is_target_upgrade(mixed $hook_extra): bool
    {
        if (! is_array($hook_extra)) {
            return false;
        }

        if (array_key_exists('type', $hook_extra) && $hook_extra['type'] !== 'plugin') {
            return false;
        }

        if (array_key_exists('plugin', $hook_extra)) {
            return is_string($hook_extra['plugin']) && $hook_extra['plugin'] === $this->plugin_basename;
        }

        return isset($hook_extra['plugins'])
            && is_array($hook_extra['plugins'])
            && in_array($this->plugin_basename, $hook_extra['plugins'], true);
    }
}
