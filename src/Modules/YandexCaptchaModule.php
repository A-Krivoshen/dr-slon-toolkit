<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Modules;

use DrSlon\Toolkit\Core\ModuleInterface;
use DrSlon\Toolkit\Core\Settings;
use DrSlon\Toolkit\Integrations\YandexSmartCaptcha;
use WP_Error;
use WP_User;

final class YandexCaptchaModule implements ModuleInterface
{
    private YandexSmartCaptcha $captcha;

    public function __construct(?YandexSmartCaptcha $captcha = null)
    {
        $this->captcha = $captcha ?? new YandexSmartCaptcha();
    }

    public function register(): void
    {
        if (! $this->is_runtime_enabled()) {
            if (is_admin()) {
                add_action('admin_notices', [$this, 'render_incomplete_notice']);
            }

            return;
        }

        add_action('login_enqueue_scripts', [$this, 'enqueue_script']);
        add_action('login_form', [$this, 'render_widget']);
        add_filter('authenticate', [$this, 'block_without_captcha'], 5, 3);
    }

    public function enqueue_script(): void
    {
        wp_enqueue_script(
            'dstk-yandex-smartcaptcha',
            YandexSmartCaptcha::SCRIPT_URL,
            [],
            false,
            true
        );
        add_filter('script_loader_tag', [$this, 'defer_script'], 10, 2);
    }

    public function defer_script(string $tag, string $handle): string
    {
        if ($handle !== 'dstk-yandex-smartcaptcha' || str_contains($tag, ' defer')) {
            return $tag;
        }

        return str_replace(' src=', ' defer src=', $tag);
    }

    public function render_widget(): void
    {
        $key = $this->client_key();
        $language = $this->language();

        echo '<div class="smart-captcha" data-sitekey="' . esc_attr($key) . '" data-hl="' . esc_attr($language) . '" style="height:100px;margin:12px 0;"></div>';
    }

    /**
     * @param null|WP_User|WP_Error $user
     * @return null|WP_User|WP_Error
     */
    public function block_without_captcha($user, string $username, string $password)
    {
        if (! $this->is_login_form_post($username, $password) || ! $this->is_runtime_enabled()) {
            return $user;
        }

        $result = $this->captcha->verify(
            $this->server_key(),
            $this->captcha->posted_token(),
            $this->request_ip()
        );

        if (! empty($result['ok'])) {
            return $user;
        }

        return new WP_Error(
            'dstk_yandex_captcha',
            __('Подтвердите, что вы не робот.', 'dr-slon-toolkit')
        );
    }

    public function render_incomplete_notice(): void
    {
        if (! current_user_can('manage_options') || ! Settings::module_enabled('yandex_captcha')) {
            return;
        }

        if ($this->is_runtime_enabled()) {
            return;
        }

        echo '<div class="notice notice-warning"><p>';
        echo esc_html__('Yandex SmartCaptcha включена, но не работает: укажите клиентский и серверный ключи.', 'dr-slon-toolkit');
        echo '</p></div>';
    }

    public function is_runtime_enabled(): bool
    {
        return $this->client_key() !== '' && $this->server_key() !== '';
    }

    public function is_login_form_post(string $username, string $password): bool
    {
        if ($username === '' && $password === '') {
            return false;
        }

        if (! isset($_POST['log']) && ! isset($_POST['pwd'])) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- detects native login POST, not a plugin form.
            return false;
        }

        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            return false;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return false;
        }

        $action = isset($_REQUEST['action']) ? sanitize_key((string) wp_unslash($_REQUEST['action'])) : 'login'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- login action query, read-only.

        return $action === '' || $action === 'login';
    }

    public function request_ip(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : '';

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    private function client_key(): string
    {
        $settings = Settings::all();
        $captcha = isset($settings['yandex_captcha']) && is_array($settings['yandex_captcha']) ? $settings['yandex_captcha'] : [];

        return isset($captcha['client_key']) ? (string) $captcha['client_key'] : '';
    }

    private function server_key(): string
    {
        $settings = Settings::all();
        $captcha = isset($settings['yandex_captcha']) && is_array($settings['yandex_captcha']) ? $settings['yandex_captcha'] : [];

        return isset($captcha['server_key']) ? (string) $captcha['server_key'] : '';
    }

    private function language(): string
    {
        $settings = Settings::all();
        $captcha = isset($settings['yandex_captcha']) && is_array($settings['yandex_captcha']) ? $settings['yandex_captcha'] : [];
        $language = isset($captcha['language']) ? (string) $captcha['language'] : 'ru';

        return in_array($language, ['ru', 'en'], true) ? $language : 'ru';
    }
}
