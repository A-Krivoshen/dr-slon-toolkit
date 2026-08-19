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
                'ai_agents'        => false,
                'yandex_captcha'   => false,
                'login_attempts'   => false,
                'redirects'        => false,
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
            'ai_agents' => [
                'ai_txt'           => true,
                'llms_txt'         => true,
                'llms_full'        => true,
                'agents_md'        => true,
                'pulse_md'         => false,
                'html_links'       => true,
                'robots'           => true,
                'site_blurb'       => '',
                'contacts'         => '',
                'facts'            => '',
                'ai_policy'        => '',
                'do_not_invent'    => '',
                'post_types'       => ['post', 'page'],
                'pulse_limit'      => 20,
                'full_posts_limit' => 30,
                'exclude_noindex'  => true,
            ],
            'yandex_captcha' => [
                'client_key' => '',
                'server_key' => '',
                'language'   => 'ru',
            ],
            'login_attempts' => [
                'max_attempts'    => 5,
                'window_minutes'  => 15,
                'lockout_minutes' => 15,
            ],
            'redirects' => [
                'rules' => [],
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
            'ai',
            'llms',
            'llms-full',
            'agents',
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
        $ai_agents = isset($input['ai_agents']) && is_array($input['ai_agents']) ? $input['ai_agents'] : [];
        $yandex_captcha = isset($input['yandex_captcha']) && is_array($input['yandex_captcha']) ? $input['yandex_captcha'] : [];
        $login_attempts = isset($input['login_attempts']) && is_array($input['login_attempts']) ? $input['login_attempts'] : [];
        $redirects = isset($input['redirects']) && is_array($input['redirects']) ? $input['redirects'] : [];
        $cleanup_submitted = ! empty($cleanup['_submitted']);
        $indexnow_submitted = ! empty($indexnow['_submitted']);
        $sitemap_submitted = ! empty($sitemap['_submitted']);
        $update_controls_submitted = ! empty($update_controls['_submitted']);
        $ai_submitted = ! empty($ai_agents['_submitted']);
        $redirects_submitted = ! empty($redirects['_submitted']);

        $saved_settings = get_option(self::OPTION_KEY, []);
        $saved_settings = is_array($saved_settings) ? $saved_settings : [];

        if ($ai_agents === [] && ! $ai_submitted) {
            $ai_agents = isset($saved_settings['ai_agents']) && is_array($saved_settings['ai_agents'])
                ? $saved_settings['ai_agents']
                : $defaults['ai_agents'];
        }

        if ($yandex_captcha === []) {
            $yandex_captcha = isset($saved_settings['yandex_captcha']) && is_array($saved_settings['yandex_captcha'])
                ? $saved_settings['yandex_captcha']
                : $defaults['yandex_captcha'];
        }

        if ($login_attempts === []) {
            $login_attempts = isset($saved_settings['login_attempts']) && is_array($saved_settings['login_attempts'])
                ? $saved_settings['login_attempts']
                : $defaults['login_attempts'];
        }

        if ($redirects === [] && ! $redirects_submitted) {
            $redirects = isset($saved_settings['redirects']) && is_array($saved_settings['redirects'])
                ? $saved_settings['redirects']
                : $defaults['redirects'];
        }

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

        $selected_ai_post_types = isset($ai_agents['post_types']) && is_array($ai_agents['post_types'])
            ? $ai_agents['post_types']
            : ($ai_submitted ? [] : $defaults['ai_agents']['post_types']);
        $sanitized_ai_post_types = [];

        foreach ($selected_ai_post_types as $post_type) {
            $post_type = sanitize_key((string) $post_type);

            if ($post_type === '' || ($validate_entities && ! in_array($post_type, $viewable_post_types, true))) {
                continue;
            }

            $sanitized_ai_post_types[] = $post_type;
        }

        $pulse_limit = isset($ai_agents['pulse_limit']) ? (int) $ai_agents['pulse_limit'] : (int) $defaults['ai_agents']['pulse_limit'];
        $full_posts_limit = isset($ai_agents['full_posts_limit']) ? (int) $ai_agents['full_posts_limit'] : (int) $defaults['ai_agents']['full_posts_limit'];
        $pulse_limit = max(1, min(50, $pulse_limit));
        $full_posts_limit = max(1, min(100, $full_posts_limit));

        $client_key = self::sanitize_captcha_key(isset($yandex_captcha['client_key']) ? (string) $yandex_captcha['client_key'] : '');
        $server_key = self::sanitize_captcha_key(isset($yandex_captcha['server_key']) ? (string) $yandex_captcha['server_key'] : '');

        if ($server_key === '' && isset($saved_settings['yandex_captcha']) && is_array($saved_settings['yandex_captcha'])) {
            $server_key = self::sanitize_captcha_key((string) ($saved_settings['yandex_captcha']['server_key'] ?? ''));
        }

        $captcha_language = isset($yandex_captcha['language']) ? sanitize_key((string) $yandex_captcha['language']) : 'ru';

        if (! in_array($captcha_language, ['ru', 'en'], true)) {
            $captcha_language = 'ru';
        }

        $max_attempts = isset($login_attempts['max_attempts']) ? (int) $login_attempts['max_attempts'] : (int) $defaults['login_attempts']['max_attempts'];
        $window_minutes = isset($login_attempts['window_minutes']) ? (int) $login_attempts['window_minutes'] : (int) $defaults['login_attempts']['window_minutes'];
        $lockout_minutes = isset($login_attempts['lockout_minutes']) ? (int) $login_attempts['lockout_minutes'] : (int) $defaults['login_attempts']['lockout_minutes'];

        $redirect_rules = [];

        if (isset($redirects['rules_text'])) {
            $redirect_rules = \DrSlon\Toolkit\Modules\RedirectManagerModule::parse_rules_text((string) $redirects['rules_text']);
        } elseif (isset($redirects['rules']) && is_array($redirects['rules'])) {
            foreach ($redirects['rules'] as $rule) {
                if (! is_array($rule)) {
                    continue;
                }

                $parsed = \DrSlon\Toolkit\Modules\RedirectManagerModule::sanitize_rule(
                    (string) ($rule['from'] ?? ''),
                    (string) ($rule['to'] ?? ''),
                    (int) ($rule['status'] ?? 301)
                );

                if ($parsed !== null) {
                    $redirect_rules[] = $parsed;
                }
            }
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
                'ai_agents'        => ! empty($modules['ai_agents']),
                'yandex_captcha'   => ! empty($modules['yandex_captcha']),
                'login_attempts'   => ! empty($modules['login_attempts']),
                'redirects'        => ! empty($modules['redirects']),
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
            'ai_agents' => [
                'ai_txt'           => array_key_exists('ai_txt', $ai_agents) ? ! empty($ai_agents['ai_txt']) : ($ai_submitted ? false : (bool) $defaults['ai_agents']['ai_txt']),
                'llms_txt'         => array_key_exists('llms_txt', $ai_agents) ? ! empty($ai_agents['llms_txt']) : ($ai_submitted ? false : (bool) $defaults['ai_agents']['llms_txt']),
                'llms_full'        => array_key_exists('llms_full', $ai_agents) ? ! empty($ai_agents['llms_full']) : ($ai_submitted ? false : (bool) $defaults['ai_agents']['llms_full']),
                'agents_md'        => array_key_exists('agents_md', $ai_agents) ? ! empty($ai_agents['agents_md']) : ($ai_submitted ? false : (bool) $defaults['ai_agents']['agents_md']),
                'pulse_md'         => array_key_exists('pulse_md', $ai_agents) ? ! empty($ai_agents['pulse_md']) : ($ai_submitted ? false : (bool) $defaults['ai_agents']['pulse_md']),
                'html_links'       => array_key_exists('html_links', $ai_agents) ? ! empty($ai_agents['html_links']) : ($ai_submitted ? false : (bool) $defaults['ai_agents']['html_links']),
                'robots'           => array_key_exists('robots', $ai_agents) ? ! empty($ai_agents['robots']) : ($ai_submitted ? false : (bool) $defaults['ai_agents']['robots']),
                'site_blurb'       => self::sanitize_plain_textarea(isset($ai_agents['site_blurb']) ? (string) $ai_agents['site_blurb'] : ''),
                'contacts'         => self::sanitize_plain_textarea(isset($ai_agents['contacts']) ? (string) $ai_agents['contacts'] : ''),
                'facts'            => self::sanitize_plain_textarea(isset($ai_agents['facts']) ? (string) $ai_agents['facts'] : ''),
                'ai_policy'        => self::sanitize_plain_textarea(isset($ai_agents['ai_policy']) ? (string) $ai_agents['ai_policy'] : ''),
                'do_not_invent'    => self::sanitize_plain_textarea(isset($ai_agents['do_not_invent']) ? (string) $ai_agents['do_not_invent'] : ''),
                'post_types'       => array_values(array_unique($sanitized_ai_post_types)),
                'pulse_limit'      => $pulse_limit,
                'full_posts_limit' => $full_posts_limit,
                'exclude_noindex'  => array_key_exists('exclude_noindex', $ai_agents) ? ! empty($ai_agents['exclude_noindex']) : ($ai_submitted ? false : (bool) $defaults['ai_agents']['exclude_noindex']),
            ],
            'yandex_captcha' => [
                'client_key' => $client_key,
                'server_key' => $server_key,
                'language'   => $captcha_language,
            ],
            'login_attempts' => [
                'max_attempts'    => max(1, min(20, $max_attempts)),
                'window_minutes'  => max(1, min(1440, $window_minutes)),
                'lockout_minutes' => max(1, min(1440, $lockout_minutes)),
            ],
            'redirects' => [
                'rules' => $redirect_rules,
            ],
        ];
    }

    public static function sanitize_captcha_key(string $key): string
    {
        $key = trim($key);

        if ($key === '') {
            return '';
        }

        $key = preg_replace('/[^A-Za-z0-9_-]/', '', $key);

        if (! is_string($key) || strlen($key) < 8 || strlen($key) > 200) {
            return '';
        }

        return $key;
    }

    private static function sanitize_plain_textarea(string $value): string
    {
        $value = function_exists('sanitize_textarea_field')
            ? sanitize_textarea_field($value)
            : trim(strip_tags($value));

        if (strlen($value) > 8000) {
            $value = substr($value, 0, 8000);
        }

        return $value;
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
