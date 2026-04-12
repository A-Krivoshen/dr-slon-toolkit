<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Core;

final class Settings
{
    public const OPTION_KEY = 'dstk_settings';

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

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function merge_with_defaults(array $input): array
    {
        $defaults = self::defaults();

        $modules = isset($input['modules']) && is_array($input['modules']) ? $input['modules'] : [];
        $cleanup = isset($input['cleanup']) && is_array($input['cleanup']) ? $input['cleanup'] : [];
        $hide_login = isset($input['hide_login']) && is_array($input['hide_login']) ? $input['hide_login'] : [];
        $rest_api = isset($input['rest_api']) && is_array($input['rest_api']) ? $input['rest_api'] : [];
        $indexnow = isset($input['indexnow']) && is_array($input['indexnow']) ? $input['indexnow'] : [];
        $sitemap = isset($input['sitemap']) && is_array($input['sitemap']) ? $input['sitemap'] : [];

        $slug = '';

        if (array_key_exists('slug', $hide_login)) {
            $slug = sanitize_title_with_dashes((string) $hide_login['slug']);
        }

        $reserved_slugs = [
            'wp-admin',
            'wp-login',
            'wp-loginphp',
        ];

        if ($slug === '' || in_array($slug, $reserved_slugs, true)) {
            $slug = $defaults['hide_login']['slug'];
        }

        $mode = isset($rest_api['mode']) ? sanitize_key((string) $rest_api['mode']) : $defaults['rest_api']['mode'];

        if (! in_array($mode, ['allow_all', 'authenticated_only', 'whitelist'], true)) {
            $mode = $defaults['rest_api']['mode'];
        }

        $trusted_capability = isset($rest_api['trusted_capability']) ? sanitize_key((string) $rest_api['trusted_capability']) : $defaults['rest_api']['trusted_capability'];
        $trusted_capability = $trusted_capability !== '' ? $trusted_capability : $defaults['rest_api']['trusted_capability'];

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

        $public_post_types = get_post_types(['public' => true], 'names');
        $selected_post_types = isset($indexnow['post_types']) && is_array($indexnow['post_types']) ? $indexnow['post_types'] : $defaults['indexnow']['post_types'];
        $sanitized_post_types = [];

        foreach ($selected_post_types as $post_type) {
            $post_type = sanitize_key((string) $post_type);

            if ($post_type === '' || ! in_array($post_type, $public_post_types, true)) {
                continue;
            }

            $sanitized_post_types[] = $post_type;
        }

        if ($sanitized_post_types === []) {
            $sanitized_post_types = $defaults['indexnow']['post_types'];
        }

        $public_sitemap_post_types = get_post_types(
            [
                'public'             => true,
                'publicly_queryable' => true,
            ],
            'names'
        );
        $selected_sitemap_post_types = isset($sitemap['post_types']) && is_array($sitemap['post_types']) ? $sitemap['post_types'] : $defaults['sitemap']['post_types'];
        $sanitized_sitemap_post_types = [];

        foreach ($selected_sitemap_post_types as $post_type) {
            $post_type = sanitize_key((string) $post_type);

            if ($post_type === '' || ! in_array($post_type, $public_sitemap_post_types, true)) {
                continue;
            }

            $sanitized_sitemap_post_types[] = $post_type;
        }

        if ($sanitized_sitemap_post_types === []) {
            $sanitized_sitemap_post_types = $defaults['sitemap']['post_types'];
        }

        $public_sitemap_taxonomies = get_taxonomies(
            [
                'public' => true,
            ],
            'names'
        );
        $selected_sitemap_taxonomies = isset($sitemap['taxonomies']) && is_array($sitemap['taxonomies']) ? $sitemap['taxonomies'] : $defaults['sitemap']['taxonomies'];
        $sanitized_sitemap_taxonomies = [];

        foreach ($selected_sitemap_taxonomies as $taxonomy) {
            $taxonomy = sanitize_key((string) $taxonomy);

            if ($taxonomy === '' || ! in_array($taxonomy, $public_sitemap_taxonomies, true)) {
                continue;
            }

            $sanitized_sitemap_taxonomies[] = $taxonomy;
        }

        if ($sanitized_sitemap_taxonomies === []) {
            $sanitized_sitemap_taxonomies = $defaults['sitemap']['taxonomies'];
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
            ],
            'cleanup' => [
                'disable_emojis'   => array_key_exists('disable_emojis', $cleanup) ? ! empty($cleanup['disable_emojis']) : $defaults['cleanup']['disable_emojis'],
                'disable_wp_embed' => array_key_exists('disable_wp_embed', $cleanup) ? ! empty($cleanup['disable_wp_embed']) : $defaults['cleanup']['disable_wp_embed'],
                'disable_xmlrpc'   => array_key_exists('disable_xmlrpc', $cleanup) ? ! empty($cleanup['disable_xmlrpc']) : $defaults['cleanup']['disable_xmlrpc'],
                'clean_head'       => array_key_exists('clean_head', $cleanup) ? ! empty($cleanup['clean_head']) : $defaults['cleanup']['clean_head'],
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
                'enabled'    => array_key_exists('enabled', $sitemap) ? ! empty($sitemap['enabled']) : $defaults['sitemap']['enabled'],
                'post_types' => array_values(array_unique($sanitized_sitemap_post_types)),
                'taxonomies' => array_values(array_unique($sanitized_sitemap_taxonomies)),
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
}
