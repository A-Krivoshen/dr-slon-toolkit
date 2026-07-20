<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Core;

final class Settings
{
    public const OPTION_KEY = 'dstk_settings';
    public const REWRITE_FLUSH_PENDING_OPTION = 'dstk_rewrite_flush_pending';

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'modules' => [
                'transliteration'  => false,
                'disable_comments' => false,
                'cleanup'          => false,
                'hide_login'       => false,
                'rest_api_control' => false,
                'indexnow'         => false,
                'sitemap'          => false,
                'update_controls'  => false,
            ],
            'cleanup' => [
                'disable_emojis'   => true,
                'disable_wp_embed' => true,
                'disable_xmlrpc'   => false,
                'clean_head'       => true,
            ],
            'hide_login' => [
                'slug' => 'login',
            ],
            'rest_api' => [
                'mode'                 => 'allow_all',
                'whitelist_routes'     => '',
                'whitelist_namespaces' => '',
                'trusted_capability'   => 'edit_posts',
                'system_routes'        => '',
            ],
            'indexnow' => [
                'key'        => '',
                'endpoint'   => 'https://api.indexnow.org/indexnow',
                'post_types' => ['post', 'page'],
            ],
            'sitemap' => [
                'enabled'    => true,
                'post_types' => ['post', 'page'],
                'taxonomies' => ['category', 'post_tag'],
            ],
            'update_controls' => [
                'core_mode'           => 'minor',
                'plugins'             => true,
                'themes'              => true,
                'translations'        => true,
                'email_notifications' => true,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $saved = get_option(self::OPTION_KEY, []);

        if (! is_array($saved)) {
            $saved = [];
        }

        return self::merge_with_defaults($saved);
    }

    public static function module_enabled(string $module): bool
    {
        $settings = self::all();

        return ! empty($settings['modules'][$module]);
    }

    public static function sanitize_hide_login_slug(string $value): string
    {
        $slug = sanitize_title_with_dashes($value);
        $reserved = [
            'author',
            'category',
            'comment-page',
            'comments',
            'embed',
            'favicon-ico',
            'feed',
            'index',
            'index-php',
            'page',
            'robots-txt',
            'search',
            'sitemap',
            'sitemap-xml',
            'tag',
            'trackback',
            'well-known',
            'wp',
            'wp-admin',
            'wp-content',
            'wp-includes',
            'wp-json',
            'xmlrpc',
            'xmlrpc-php',
        ];

        if (
            $slug === ''
            || strlen($slug) > 80
            || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $slug) !== 1
            || str_starts_with($slug, 'wp-')
            || in_array($slug, $reserved, true)
        ) {
            return (string) self::defaults()['hide_login']['slug'];
        }

        return $slug;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function merge_with_defaults(array $input, bool $validate_entities = false): array
    {
        $defaults = self::defaults();

        $modules = isset($input['modules']) && is_array($input['modules']) ? $input['modules'] : [];
        $cleanup = isset($input['cleanup']) && is_array($input['cleanup']) ? $input['cleanup'] : [];
        $hide_login = isset($input['hide_login']) && is_array($input['hide_login']) ? $input['hide_login'] : [];
        $rest_api = isset($input['rest_api']) && is_array($input['rest_api']) ? $input['rest_api'] : [];
        $indexnow = isset($input['indexnow']) && is_array($input['indexnow']) ? $input['indexnow'] : [];
        $sitemap = isset($input['sitemap']) && is_array($input['sitemap']) ? $input['sitemap'] : [];
        $update_controls = isset($input['update_controls']) && is_array($input['update_controls']) ? $input['update_controls'] : [];
        $cleanup_submitted = ! empty($cleanup['_submitted']);
        $indexnow_submitted = ! empty($indexnow['_submitted']);
        $sitemap_submitted = ! empty($sitemap['_submitted']);
        $update_controls_submitted = ! empty($update_controls['_submitted']);

        $slug = self::sanitize_hide_login_slug(isset($hide_login['slug']) ? (string) $hide_login['slug'] : '');

        $mode = isset($rest_api['mode']) ? sanitize_key((string) $rest_api['mode']) : $defaults['rest_api']['mode'];

        if (! in_array($mode, ['allow_all', 'authenticated_only', 'whitelist'], true)) {
            $mode = $defaults['rest_api']['mode'];
        }

        $trusted_capability = self::sanitize_trusted_capability(
            isset($rest_api['trusted_capability']) ? (string) $rest_api['trusted_capability'] : $defaults['rest_api']['trusted_capability']
        );

        $indexnow_key = isset($indexnow['key']) ? self::sanitize_indexnow_key((string) $indexnow['key']) : '';
        $indexnow_endpoint = isset($indexnow['endpoint']) ? esc_url_raw((string) $indexnow['endpoint']) : $defaults['indexnow']['endpoint'];
        $allowed_endpoints = [
            'https://api.indexnow.org/indexnow',
            'https://www.bing.com/indexnow',
            'https://yandex.com/indexnow',
        ];

        if (! in_array($indexnow_endpoint, $allowed_endpoints, true)) {
            $indexnow_endpoint = $defaults['indexnow']['endpoint'];
        }

        $viewable_post_types = [];
        $viewable_taxonomies = [];

        if ($validate_entities) {
            foreach (get_post_types([], 'objects') as $post_type => $object) {
                if ($post_type !== 'attachment' && is_post_type_viewable($object)) {
                    $viewable_post_types[] = $post_type;
                }
            }

            foreach (get_taxonomies([], 'objects') as $taxonomy => $object) {
                if (is_taxonomy_viewable($object)) {
                    $viewable_taxonomies[] = $taxonomy;
                }
            }
        }

        $selected_post_types = isset($indexnow['post_types']) && is_array($indexnow['post_types'])
            ? $indexnow['post_types']
            : ($indexnow_submitted ? [] : $defaults['indexnow']['post_types']);
        $sanitized_post_types = [];

        foreach ($selected_post_types as $post_type) {
            $post_type = sanitize_key((string) $post_type);

            if ($post_type === '' || ($validate_entities && ! in_array($post_type, $viewable_post_types, true))) {
                continue;
            }

            $sanitized_post_types[] = $post_type;
        }

        $selected_sitemap_post_types = isset($sitemap['post_types']) && is_array($sitemap['post_types'])
            ? $sitemap['post_types']
            : ($sitemap_submitted ? [] : $defaults['sitemap']['post_types']);
        $sanitized_sitemap_post_types = [];

        foreach ($selected_sitemap_post_types as $post_type) {
            $post_type = sanitize_key((string) $post_type);

            if ($post_type === '' || ($validate_entities && ! in_array($post_type, $viewable_post_types, true))) {
                continue;
            }

            $sanitized_sitemap_post_types[] = $post_type;
        }

        $selected_sitemap_taxonomies = isset($sitemap['taxonomies']) && is_array($sitemap['taxonomies'])
            ? $sitemap['taxonomies']
            : ($sitemap_submitted ? [] : $defaults['sitemap']['taxonomies']);
        $sanitized_sitemap_taxonomies = [];

        foreach ($selected_sitemap_taxonomies as $taxonomy) {
            $taxonomy = sanitize_key((string) $taxonomy);

            if ($taxonomy === '' || ($validate_entities && ! in_array($taxonomy, $viewable_taxonomies, true))) {
                continue;
            }

            $sanitized_sitemap_taxonomies[] = $taxonomy;
        }

        $core_mode = isset($update_controls['core_mode']) ? sanitize_key((string) $update_controls['core_mode']) : $defaults['update_controls']['core_mode'];

        // Legacy "security" was only a minor-channel approximation — map to minor.
        if ($core_mode === 'security') {
            $core_mode = 'minor';
        }

        if (! in_array($core_mode, ['all', 'minor', 'off'], true)) {
            $core_mode = $defaults['update_controls']['core_mode'];
        }

        return [
            'modules' => [
                'transliteration'  => ! empty($modules['transliteration']),
                'disable_comments' => ! empty($modules['disable_comments']),
                'cleanup'          => ! empty($modules['cleanup']),
                'hide_login'       => ! empty($modules['hide_login']),
                'rest_api_control' => ! empty($modules['rest_api_control']),
                'indexnow'         => ! empty($modules['indexnow']),
                'sitemap'          => ! empty($modules['sitemap']),
                'update_controls'  => ! empty($modules['update_controls']),
            ],
            'cleanup' => [
                'disable_emojis'   => array_key_exists('disable_emojis', $cleanup) ? ! empty($cleanup['disable_emojis']) : ($cleanup_submitted ? false : $defaults['cleanup']['disable_emojis']),
                'disable_wp_embed' => array_key_exists('disable_wp_embed', $cleanup) ? ! empty($cleanup['disable_wp_embed']) : ($cleanup_submitted ? false : $defaults['cleanup']['disable_wp_embed']),
                'disable_xmlrpc'   => array_key_exists('disable_xmlrpc', $cleanup) ? ! empty($cleanup['disable_xmlrpc']) : ($cleanup_submitted ? false : $defaults['cleanup']['disable_xmlrpc']),
                'clean_head'       => array_key_exists('clean_head', $cleanup) ? ! empty($cleanup['clean_head']) : ($cleanup_submitted ? false : $defaults['cleanup']['clean_head']),
            ],
            'hide_login' => [
                'slug' => $slug,
            ],
            'rest_api' => [
                'mode'                 => $mode,
                'whitelist_routes'     => self::sanitize_multiline_routes(isset($rest_api['whitelist_routes']) ? (string) $rest_api['whitelist_routes'] : ''),
                'whitelist_namespaces' => self::sanitize_multiline_namespaces(isset($rest_api['whitelist_namespaces']) ? (string) $rest_api['whitelist_namespaces'] : ''),
                'trusted_capability'   => $trusted_capability,
                'system_routes'        => self::sanitize_multiline_routes(isset($rest_api['system_routes']) ? (string) $rest_api['system_routes'] : $defaults['rest_api']['system_routes']),
            ],
            'indexnow' => [
                'key'        => $indexnow_key,
                'endpoint'   => $indexnow_endpoint,
                'post_types' => array_values(array_unique($sanitized_post_types)),
            ],
            'sitemap' => [
                'enabled'    => array_key_exists('enabled', $sitemap) ? ! empty($sitemap['enabled']) : ($sitemap_submitted ? false : $defaults['sitemap']['enabled']),
                'post_types' => array_values(array_unique($sanitized_sitemap_post_types)),
                'taxonomies' => array_values(array_unique($sanitized_sitemap_taxonomies)),
            ],
            'update_controls' => [
                'core_mode'           => $core_mode,
                'plugins'             => array_key_exists('plugins', $update_controls) ? ! empty($update_controls['plugins']) : ($update_controls_submitted ? false : $defaults['update_controls']['plugins']),
                'themes'              => array_key_exists('themes', $update_controls) ? ! empty($update_controls['themes']) : ($update_controls_submitted ? false : $defaults['update_controls']['themes']),
                'translations'        => array_key_exists('translations', $update_controls) ? ! empty($update_controls['translations']) : ($update_controls_submitted ? false : $defaults['update_controls']['translations']),
                'email_notifications' => array_key_exists('email_notifications', $update_controls) ? ! empty($update_controls['email_notifications']) : ($update_controls_submitted ? false : $defaults['update_controls']['email_notifications']),
            ],
        ];
    }

    private static function sanitize_multiline_routes(string $raw): string
    {
        $parts = preg_split('/[\r\n]+/', $raw) ?: [];
        $routes = [];

        foreach ($parts as $part) {
            $part = trim((string) $part);

            if ($part === '') {
                continue;
            }

            $route = (string) wp_parse_url($part, PHP_URL_PATH);

            if ($route === '') {
                continue;
            }

            $route = '/' . ltrim($route, '/');

            if ($route !== '/') {
                $route = rtrim($route, '/');
            }

            if (str_contains($route, '..')) {
                continue;
            }

            $routes[] = $route;
        }

        return implode("\n", array_values(array_unique($routes)));
    }

    private static function sanitize_multiline_namespaces(string $raw): string
    {
        $parts = preg_split('/[\r\n]+/', $raw) ?: [];
        $namespaces = [];

        foreach ($parts as $part) {
            $part = trim((string) $part, " \t\n\r\0\x0B/");

            if ($part === '') {
                continue;
            }

            $part = preg_replace('/[^a-z0-9_\\/-]/i', '', $part);

            if (! is_string($part) || $part === '') {
                continue;
            }

            $namespaces[] = $part;
        }

        return implode("\n", array_values(array_unique($namespaces)));
    }

    private static function sanitize_indexnow_key(string $key): string
    {
        $key = trim($key);

        if ($key === '') {
            return '';
        }

        $key = preg_replace('/[^a-zA-Z0-9\\-]/', '', $key);

        if (! is_string($key)) {
            return '';
        }

        if (strlen($key) < 8 || strlen($key) > 128) {
            return '';
        }

        return $key;
    }

    /**
     * Capabilities that may fully bypass REST whitelist restrictions.
     *
     * @return list<string>
     */
    public static function trusted_capabilities(): array
    {
        return [
            'edit_posts',
            'edit_pages',
            'publish_posts',
            'publish_pages',
            'edit_others_posts',
            'upload_files',
            'manage_options',
        ];
    }

    public static function sanitize_trusted_capability(string $capability): string
    {
        $capability = sanitize_key($capability);
        $default = (string) self::defaults()['rest_api']['trusted_capability'];

        if ($capability === '' || ! in_array($capability, self::trusted_capabilities(), true)) {
            return $default;
        }

        return $capability;
    }
}
