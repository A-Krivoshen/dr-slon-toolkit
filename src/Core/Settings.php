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
                'system_routes'        => "/oembed/1.0/embed\n/wp/v2/types\n/wp/v2/taxonomies\n/wp/v2/statuses\n/wp/v2/search",
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

        return [
            'modules' => [
                'transliteration'  => ! empty($modules['transliteration']),
                'disable_comments' => ! empty($modules['disable_comments']),
                'cleanup'          => ! empty($modules['cleanup']),
                'hide_login'       => ! empty($modules['hide_login']),
                'rest_api_control' => ! empty($modules['rest_api_control']),
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
}
