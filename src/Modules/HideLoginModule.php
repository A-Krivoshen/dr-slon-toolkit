<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Modules;

use DrSlon\Toolkit\Core\ModuleInterface;
use DrSlon\Toolkit\Core\RewriteManager;
use DrSlon\Toolkit\Core\Settings;

final class HideLoginModule implements ModuleInterface
{
    private const DEFAULT_SLUG = 'login';
    private const ROUTE_QUERY_VAR = 'dstk_custom_login';

    private string $slug;
    private bool $route_available = true;
    private bool $serving_custom_login = false;
    private bool $building_native_url = false;
    private bool $url_filters_registered = false;
    private ?string $collision_reason = null;

    public function __construct()
    {
        $settings = Settings::all();
        $hide_login = isset($settings['hide_login']) && is_array($settings['hide_login']) ? $settings['hide_login'] : [];
        $slug = isset($hide_login['slug']) ? (string) $hide_login['slug'] : self::DEFAULT_SLUG;
        $this->slug = Settings::sanitize_hide_login_slug($slug);
    }

    public function register(): void
    {
        if ($this->is_bypassed()) {
            return;
        }

        add_action('init', [$this, 'register_rewrite_rule'], 99);
        add_filter('query_vars', [$this, 'register_query_var']);
        add_action('template_redirect', [$this, 'handle_custom_login_request'], 0);
        add_action('login_init', [$this, 'handle_direct_wp_login_access'], 0);
        add_action('login_footer', [$this, 'render_plain_route_field'], 0);
        add_action('wp_after_insert_post', [$this, 'maybe_schedule_collision_flush'], 10, 4);
        add_action('before_delete_post', [$this, 'maybe_schedule_deleted_collision_flush'], 10, 2);
        add_action('admin_notices', [$this, 'render_collision_notice']);
    }

    public function register_rewrite_rule(): void
    {
        $rules = $this->rewrite_rules();
        $query = 'index.php?' . self::ROUTE_QUERY_VAR . '=' . rawurlencode($this->slug);

        if ($this->uses_pretty_permalinks()) {
            if ($this->has_registered_route_base_collision()) {
                $this->mark_route_unavailable('reserved_route_base');

                return;
            }

            if ($this->has_page_route_collision()) {
                $this->mark_route_unavailable('content_path');

                return;
            }

            global $wp_rewrite;

            foreach ($rules as $rule) {
                if (
                    isset($wp_rewrite->extra_rules_top[$rule])
                    && $wp_rewrite->extra_rules_top[$rule] !== $query
                ) {
                    $this->mark_route_unavailable('rewrite_rule');

                    return;
                }
            }
        }

        foreach ($rules as $rule) {
            add_rewrite_rule($rule, $query, 'top');
            do_action('dstk_hide_login_rewrite_rule_registered', $this->slug, $rule, $query);
        }

        $this->register_url_filters();
    }

    private function register_url_filters(): void
    {
        if ($this->url_filters_registered) {
            return;
        }

        $this->url_filters_registered = true;
        add_filter('login_url', [$this, 'filter_login_url'], 10, 3);
        add_filter('lostpassword_url', [$this, 'filter_lostpassword_url'], 10, 2);
        add_filter('recovery_mode_begin_url', [$this, 'filter_recovery_mode_begin_url'], PHP_INT_MAX, 3);
        add_filter('site_url', [$this, 'filter_site_login_url'], 10, 4);
        add_filter('network_site_url', [$this, 'filter_network_login_url'], 10, 3);
    }

    /**
     * @param string[] $vars
     * @return string[]
     */
    public function register_query_var(array $vars): array
    {
        if (! in_array(self::ROUTE_QUERY_VAR, $vars, true)) {
            $vars[] = self::ROUTE_QUERY_VAR;
        }

        return $vars;
    }

    public function handle_custom_login_request(): void
    {
        if (! $this->route_available || ! $this->is_custom_route_request()) {
            return;
        }

        if ($this->request_requires_native_login()) {
            $this->redirect_to_native_login();
        }

        $this->serving_custom_login = true;
        $this->forward_to_wp_login();
    }

    public function handle_direct_wp_login_access(): void
    {
        if ($this->serving_custom_login || ! $this->route_available || $this->request_requires_native_login()) {
            return;
        }

        status_header(404);
        nocache_headers();
        wp_die(
            esc_html__('Страница не найдена.', 'dr-slon-toolkit'),
            esc_html__('Страница не найдена', 'dr-slon-toolkit'),
            ['response' => 404]
        );
    }

    public function render_plain_route_field(): void
    {
        if (! $this->serving_custom_login || $this->uses_pretty_permalinks()) {
            return;
        }

        printf(
            '<input type="hidden" name="%s" value="%s" form="language-switcher" />',
            esc_attr(self::ROUTE_QUERY_VAR),
            esc_attr($this->slug)
        );
    }

    public function filter_login_url(string $login_url, string $redirect, bool $force_reauth): string
    {
        if (! $this->is_native_login_url($login_url)) {
            return $login_url;
        }

        $args = $this->safe_query_args_from_url($login_url);

        if ($redirect !== '') {
            unset($args['redirect_to']);
            $redirect = wp_validate_redirect($redirect, '');

            if ($redirect !== '') {
                $args['redirect_to'] = $redirect;
            }
        }

        if ($force_reauth) {
            $args['reauth'] = '1';
        }

        if (! $this->route_available || $this->is_recovery_mode() || $this->args_require_native_login($args)) {
            return $this->native_login_url($args);
        }

        return $this->add_query_args($this->custom_login_url(), $args);
    }

    public function filter_lostpassword_url(string $lostpassword_url, string $redirect): string
    {
        if (! $this->is_native_login_url($lostpassword_url)) {
            return $lostpassword_url;
        }

        $args = $this->safe_query_args_from_url($lostpassword_url);
        $args['action'] = 'lostpassword';

        if ($redirect !== '') {
            unset($args['redirect_to']);
            $redirect = wp_validate_redirect($redirect, '');

            if ($redirect !== '') {
                $args['redirect_to'] = $redirect;
            }
        }

        $base_url = (! $this->route_available || $this->is_recovery_mode())
            ? $this->native_login_url()
            : $this->custom_login_url();

        return $this->add_query_args($base_url, $args);
    }

    public function filter_recovery_mode_begin_url(string $url, string $token, string $key): string
    {
        $args = $this->safe_query_args_from_url($url);
        $args['action'] = 'enter_recovery_mode';

        $safe_token = $this->safe_scalar_value($token, false);
        $safe_key = $this->safe_scalar_value($key, false);

        if ($safe_token !== null) {
            $args['rm_token'] = $safe_token;
        }

        if ($safe_key !== null) {
            $args['rm_key'] = $safe_key;
        }

        return $this->native_login_url($args);
    }

    public function filter_site_login_url(mixed $url, mixed $path, mixed $scheme, mixed $blog_id): mixed
    {
        unset($path, $scheme, $blog_id);

        return $this->rewrite_rendered_login_endpoint($url);
    }

    public function filter_network_login_url(mixed $url, mixed $path, mixed $scheme): mixed
    {
        unset($scheme);

        return $this->rewrite_rendered_login_endpoint($url, $this->is_native_login_path($path));
    }

    public function maybe_schedule_collision_flush(
        int $post_id,
        \WP_Post $post,
        bool $update,
        ?\WP_Post $post_before = null
    ): void
    {
        unset($post_id, $update);

        if (
            $post->post_name === $this->slug
            || ($post_before instanceof \WP_Post && $post_before->post_name === $this->slug)
        ) {
            RewriteManager::schedule();
        }
    }

    public function maybe_schedule_deleted_collision_flush(int $post_id, ?\WP_Post $post = null): void
    {
        $post = $post instanceof \WP_Post ? $post : get_post($post_id);

        if ($post instanceof \WP_Post && $post->post_name === $this->slug) {
            RewriteManager::schedule();
        }
    }

    public function render_collision_notice(): void
    {
        if ($this->collision_reason === null || ! current_user_can('manage_options')) {
            return;
        }

        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Скрытый вход временно отключён: выбранный slug конфликтует с существующим маршрутом или материалом. Измените slug в настройках Dr.Slon Toolkit.', 'dr-slon-toolkit');
        echo '</p></div>';
    }

    private function redirect_to_native_login(): void
    {
        $url = $this->native_login_url($this->safe_query_args($_GET)); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public login routing.
        $method = isset($_SERVER['REQUEST_METHOD'])
            ? strtoupper(sanitize_key(wp_unslash((string) $_SERVER['REQUEST_METHOD'])))
            : 'GET';
        $status = in_array($method, ['GET', 'HEAD'], true) ? 302 : 307;

        nocache_headers();
        wp_safe_redirect($url, $status, 'Dr.Slon Toolkit');
        exit;
    }

    private function forward_to_wp_login(): void
    {
        global $action, $error, $interim_login, $user_login;

        $action = 'login';
        $error = '';
        $interim_login = false;
        $user_login = '';

        unset($_GET[self::ROUTE_QUERY_VAR], $_POST[self::ROUTE_QUERY_VAR], $_REQUEST[self::ROUTE_QUERY_VAR]);

        $login_path = wp_parse_url($this->native_login_url(), PHP_URL_PATH);

        if (! is_string($login_path) || $login_path === '') {
            $login_path = '/wp-login.php';
        }

        $GLOBALS['pagenow'] = 'wp-login.php';
        $_SERVER['SCRIPT_NAME'] = $login_path;
        $_SERVER['PHP_SELF'] = $login_path;
        $_SERVER['SCRIPT_FILENAME'] = ABSPATH . 'wp-login.php';

        status_header(200);
        nocache_headers();

        require ABSPATH . 'wp-login.php';
        exit;
    }

    private function rewrite_rendered_login_endpoint(mixed $url, bool $known_login_path = false): mixed
    {
        if (
            ! is_string($url)
            || $this->building_native_url
            || ! $this->route_available
            || (! $known_login_path && ! $this->is_native_login_url($url))
        ) {
            return $url;
        }

        $args = $this->safe_query_args_from_url($url);

        if ($this->args_require_native_login($args)) {
            return $url;
        }

        return $this->add_query_args($this->custom_login_url(), $args);
    }

    private function is_native_login_path(mixed $path): bool
    {
        if (! is_string($path)) {
            return false;
        }

        $path = wp_parse_url(html_entity_decode($path, ENT_QUOTES | ENT_HTML5, 'UTF-8'), PHP_URL_PATH);

        return is_string($path) && $this->normalize_path($path) === '/wp-login.php';
    }

    private function is_native_login_url(string $url): bool
    {
        $candidate = wp_parse_url(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $native = wp_parse_url($this->native_login_url());

        if (! is_array($candidate) || ! is_array($native)) {
            return false;
        }

        return strtolower((string) ($candidate['host'] ?? '')) === strtolower((string) ($native['host'] ?? ''))
            && (int) ($candidate['port'] ?? 0) === (int) ($native['port'] ?? 0)
            && $this->normalize_path((string) ($candidate['path'] ?? '')) === $this->normalize_path((string) ($native['path'] ?? ''));
    }

    private function custom_login_url(): string
    {
        if (! $this->uses_pretty_permalinks()) {
            return add_query_arg(self::ROUTE_QUERY_VAR, $this->slug, home_url('/'));
        }

        $root = $this->permalink_root();
        $path = '/' . ($root !== '' ? $root . '/' : '') . $this->slug;

        return home_url(user_trailingslashit($path));
    }

    private function native_login_url(array $args = []): string
    {
        $this->building_native_url = true;

        try {
            $url = site_url('wp-login.php', 'login');
        } finally {
            $this->building_native_url = false;
        }

        return $this->add_query_args($url, $args);
    }

    /**
     * @param array<string, string> $args
     */
    private function add_query_args(string $url, array $args): string
    {
        return $args === [] ? $url : add_query_arg($args, $url);
    }

    private function is_custom_route_request(): bool
    {
        $route = get_query_var(self::ROUTE_QUERY_VAR);

        if (! is_scalar($route) || ! in_array((string) $route, [$this->slug, '1'], true)) {
            return false;
        }

        if (! $this->uses_pretty_permalinks()) {
            return $this->request_path_matches_home();
        }

        $wp = $GLOBALS['wp'] ?? null;

        return is_object($wp)
            && isset($wp->matched_rule)
            && is_string($wp->matched_rule)
            && in_array($wp->matched_rule, $this->rewrite_rules(), true);
    }

    private function request_path_matches_home(): bool
    {
        $request_uri = isset($_SERVER['REQUEST_URI'])
            ? esc_url_raw(wp_unslash((string) $_SERVER['REQUEST_URI']))
            : '';
        $request_path = wp_parse_url($request_uri, PHP_URL_PATH);
        $home_path = wp_parse_url(home_url('/'), PHP_URL_PATH);

        if (! is_string($request_path) || ! is_string($home_path)) {
            return false;
        }

        return $this->normalize_path($request_path) === $this->normalize_path($home_path);
    }

    private function normalize_path(string $path): string
    {
        $path = '/' . ltrim($path, '/');

        return $path === '/' ? '/' : untrailingslashit($path);
    }

    private function request_requires_native_login(): bool
    {
        if ($this->is_recovery_mode()) {
            return true;
        }

        // WordPress login actions are public request routing, not state changes here.
        $action = isset($_REQUEST['action']) && is_scalar($_REQUEST['action']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ? sanitize_key(wp_unslash((string) $_REQUEST['action'])) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            : '';

        return $action === 'enter_recovery_mode'
            && isset($_GET['rm_token'], $_GET['rm_key']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            && is_scalar($_GET['rm_token']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            && is_scalar($_GET['rm_key']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    }

    /**
     * @param array<string, string> $args
     */
    private function args_require_native_login(array $args): bool
    {
        if (! isset($args['action'])) {
            return false;
        }

        return $args['action'] === 'enter_recovery_mode'
            && isset($args['rm_token'], $args['rm_key']);
    }

    private function is_recovery_mode(): bool
    {
        return function_exists('wp_is_recovery_mode') && wp_is_recovery_mode();
    }

    /**
     * @param array<mixed> $source
     * @return array<string, string>
     */
    private function safe_query_args(array $source, bool $unslash = true): array
    {
        $args = [];

        foreach ($source as $name => $value) {
            $name = (string) $name;

            if (
                $name === self::ROUTE_QUERY_VAR
                || strlen($name) > 128
                || preg_match('/\A[A-Za-z0-9_.:~-]+\z/D', $name) !== 1
            ) {
                continue;
            }

            $value = $this->safe_scalar_value($value, $unslash);

            if ($value === null) {
                continue;
            }

            if ($name === 'redirect_to') {
                $value = wp_validate_redirect($value, '');

                if ($value === '') {
                    continue;
                }
            }

            $args[$name] = $value;
        }

        return $args;
    }

    /**
     * @return array<string, string>
     */
    private function safe_query_args_from_url(string $url): array
    {
        $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $query = wp_parse_url($url, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return [];
        }

        $raw_args = [];
        wp_parse_str($query, $raw_args);

        return $this->safe_query_args($raw_args, false);
    }

    /**
     * @param mixed $value
     */
    private function safe_scalar_value($value, bool $unslash = true): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = (string) $value;

        if ($unslash) {
            $value = wp_unslash($value);
        }
        $checked_value = wp_check_invalid_utf8($value);

        if (($value !== '' && $checked_value === '') || preg_match('/[\x00-\x1F\x7F]/', $checked_value) === 1) {
            return null;
        }

        return $checked_value;
    }

    /**
     * @return string[]
     */
    private function rewrite_rules(): array
    {
        $slug = preg_quote($this->slug, '#');
        $rules = ['^' . $slug . '/?$'];
        $root = $this->permalink_root();

        if ($root !== '') {
            // WP::parse_request() may match PATHINFO with or without the index prefix.
            $rules[] = preg_quote($root, '#') . '/' . $slug . '/?$';
        }

        return $rules;
    }

    private function permalink_root(): string
    {
        global $wp_rewrite;

        if (! is_object($wp_rewrite) || ! isset($wp_rewrite->root) || ! is_string($wp_rewrite->root)) {
            return '';
        }

        return trim($wp_rewrite->root, '/');
    }

    private function uses_pretty_permalinks(): bool
    {
        return (string) get_option('permalink_structure') !== '';
    }

    private function has_registered_route_base_collision(): bool
    {
        $bases = [];

        global $wp_rewrite;

        if (is_object($wp_rewrite)) {
            foreach (['author_base', 'search_base', 'comments_base', 'pagination_base', 'comments_pagination_base', 'feed_base'] as $property) {
                if (isset($wp_rewrite->{$property}) && is_string($wp_rewrite->{$property})) {
                    $bases[] = $wp_rewrite->{$property};
                }
            }
        }

        $bases[] = (string) get_option('category_base', 'category');
        $bases[] = (string) get_option('tag_base', 'tag');
        $bases[] = (string) get_option('permalink_structure', '');

        if (function_exists('rest_get_url_prefix')) {
            $bases[] = rest_get_url_prefix();
        }

        foreach (get_post_types(['public' => true], 'objects') as $post_type) {
            if (is_array($post_type->rewrite) && isset($post_type->rewrite['slug'])) {
                $bases[] = (string) $post_type->rewrite['slug'];
            }

            if (is_string($post_type->has_archive)) {
                $bases[] = $post_type->has_archive;
            }
        }

        foreach (get_taxonomies(['public' => true], 'objects') as $taxonomy) {
            if (is_array($taxonomy->rewrite) && isset($taxonomy->rewrite['slug'])) {
                $bases[] = (string) $taxonomy->rewrite['slug'];
            }
        }

        foreach ($bases as $base) {
            if ($this->route_base($base) === $this->slug) {
                return true;
            }
        }

        return false;
    }

    private function has_page_route_collision(): bool
    {
        $post_types = [];

        foreach (get_post_types([], 'objects') as $post_type => $object) {
            if ($post_type !== 'attachment' && is_post_type_viewable($object)) {
                $post_types[] = $post_type;
            }
        }

        $page = get_page_by_path($this->slug, OBJECT, $post_types);

        return $page !== null
            && ! in_array((string) $page->post_status, ['auto-draft', 'trash'], true);
    }

    private function route_base(string $path): string
    {
        $path = trim($path, '/');
        $root = $this->permalink_root();

        if ($root !== '' && ($path === $root || str_starts_with($path, $root . '/'))) {
            $path = ltrim(substr($path, strlen($root)), '/');
        }

        if ($path === '') {
            return '';
        }

        $segment = explode('/', $path, 2)[0];

        if ($segment === '' || str_contains($segment, '%')) {
            return '';
        }

        return sanitize_title_with_dashes(rawurldecode($segment));
    }

    private function mark_route_unavailable(string $reason): void
    {
        $this->route_available = false;
        $this->collision_reason = $reason;

        do_action('dstk_hide_login_route_collision', $this->slug, $reason);
    }

    private function is_bypassed(): bool
    {
        return defined('KRV_DSTK_DISABLE_HIDE_LOGIN') && KRV_DSTK_DISABLE_HIDE_LOGIN;
    }
}
