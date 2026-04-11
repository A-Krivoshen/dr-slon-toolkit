<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Modules;

use DrSlon\Toolkit\Core\ModuleInterface;
use DrSlon\Toolkit\Core\Settings;

final class HideLoginModule implements ModuleInterface
{
    private string $slug;

    public function __construct()
    {
        $settings = Settings::all();
        $hide_login = isset($settings['hide_login']) && is_array($settings['hide_login']) ? $settings['hide_login'] : [];

        $this->slug = isset($hide_login['slug']) ? sanitize_title_with_dashes((string) $hide_login['slug']) : 'login';

        if ($this->slug === '' || in_array($this->slug, ['wp-admin', 'wp-login', 'wp-loginphp'], true)) {
            $this->slug = 'login';
        }
    }

    public function register(): void
    {
        if ($this->is_bypassed()) {
            return;
        }

        add_action('init', [$this, 'register_rewrite_rule']);
        add_filter('query_vars', [$this, 'register_query_var']);
        add_action('template_redirect', [$this, 'handle_custom_login_request'], 0);
        add_action('login_init', [$this, 'handle_direct_wp_login_access'], 0);
        add_filter('login_url', [$this, 'filter_login_url'], 10, 3);
        add_filter('lostpassword_url', [$this, 'filter_lostpassword_url'], 10, 2);
    }

    public function register_rewrite_rule(): void
    {
        add_rewrite_rule('^' . preg_quote($this->slug, '/') . '/?$', 'index.php?dstk_custom_login=1', 'top');
    }

    /**
     * @param string[] $vars
     * @return string[]
     */
    public function register_query_var(array $vars): array
    {
        $vars[] = 'dstk_custom_login';

        return $vars;
    }

    public function handle_custom_login_request(): void
    {
        if ((int) get_query_var('dstk_custom_login') !== 1) {
            return;
        }

        $this->forward_to_wp_login();
    }

    public function handle_direct_wp_login_access(): void
    {
        if ($this->is_custom_request() || is_user_logged_in()) {
            return;
        }

        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            return;
        }

        $this->redirect_to_custom_login();
    }

    public function filter_login_url(string $login_url, string $redirect, bool $force_reauth): string
    {
        $custom_url = $this->custom_login_url();

        if ($redirect !== '') {
            $custom_url = add_query_arg('redirect_to', esc_url_raw($redirect), $custom_url);
        }

        if ($force_reauth) {
            $custom_url = add_query_arg('reauth', '1', $custom_url);
        }

        return $custom_url;
    }

    public function filter_lostpassword_url(string $lostpassword_url, string $redirect): string
    {
        $url = add_query_arg('action', 'lostpassword', $this->custom_login_url());

        if ($redirect !== '') {
            $url = add_query_arg('redirect_to', esc_url_raw($redirect), $url);
        }

        return $url;
    }

    private function redirect_to_custom_login(): void
    {
        $query_keys = [
            'action',
            'checkemail',
            'interim-login',
            'key',
            'login',
            'redirect_to',
            'reauth',
        ];

        $args = [];

        foreach ($query_keys as $key) {
            if (! isset($_GET[$key])) {
                continue;
            }

            $value = wp_unslash((string) $_GET[$key]);

            if ($key === 'redirect_to') {
                $args[$key] = esc_url_raw($value);
                continue;
            }

            if ($key === 'key') {
                $args[$key] = sanitize_text_field($value);
                continue;
            }

            $args[$key] = sanitize_text_field($value);
        }

        $url = add_query_arg($args, $this->custom_login_url());

        wp_safe_redirect($url, 302);
        exit;
    }

    private function forward_to_wp_login(): void
    {
        $_GET['dstk_custom_login'] = '1';
        $_REQUEST['dstk_custom_login'] = '1';
        $_SERVER['SCRIPT_NAME'] = '/wp-login.php';
        $_SERVER['PHP_SELF'] = '/wp-login.php';

        require ABSPATH . 'wp-login.php';
        exit;
    }

    private function custom_login_url(): string
    {
        if ((string) get_option('permalink_structure') === '') {
            return add_query_arg('dstk_custom_login', '1', home_url('/'));
        }

        return home_url('/' . trim($this->slug, '/') . '/');
    }

    private function is_custom_request(): bool
    {
        return isset($_REQUEST['dstk_custom_login']) && (string) $_REQUEST['dstk_custom_login'] === '1';
    }

    private function is_bypassed(): bool
    {
        return defined('KRV_DSTK_DISABLE_HIDE_LOGIN') && KRV_DSTK_DISABLE_HIDE_LOGIN;
    }
}
