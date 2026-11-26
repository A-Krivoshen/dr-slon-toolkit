<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Modules;

use DrSlon\Toolkit\Core\ModuleInterface;
use DrSlon\Toolkit\Core\Settings;
use DrSlon\Toolkit\Integrations\GoogleRecaptcha;
use DrSlon\Toolkit\Integrations\YandexSmartCaptcha;
use WP_Error;
use WP_User;

final class YandexCaptchaModule implements ModuleInterface
{
    private YandexSmartCaptcha $captcha;

    private GoogleRecaptcha $google;

    public function __construct(?YandexSmartCaptcha $captcha = null, ?GoogleRecaptcha $google = null)
    {
        $this->captcha = $captcha ?? new YandexSmartCaptcha();
        $this->google = $google ?? new GoogleRecaptcha();
    }

    public function register(): void
    {
        if (! $this->is_runtime_enabled()) {
            if (is_admin()) {
                add_action('admin_notices', [$this, 'render_incomplete_notice']);
            }

            return;
        }

        add_action('login_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('login_form', [$this, 'render_widget']);
        add_filter('login_body_class', [$this, 'body_class']);
        // Core password checks run at 20 and drop an earlier WP_Error when
        // both fields are filled, so a priority-5 captcha rejection never sticks.
        add_filter('authenticate', [$this, 'block_without_captcha'], 100, 3);
    }

    public function enqueue_assets(): void
    {
        wp_enqueue_style(
            'dstk-yandex-smartcaptcha',
            plugins_url('assets/admin/login-captcha.css', DSTK_PLUGIN_FILE),
            [],
            DSTK_VERSION
        );

        if ($this->provider() === 'google') {
            wp_enqueue_script(
                'dstk-google-recaptcha',
                add_query_arg(['hl' => $this->language()], GoogleRecaptcha::SCRIPT_URL),
                [],
                false,
                true
            );
        } else {
            wp_enqueue_script(
                'dstk-yandex-smartcaptcha',
                YandexSmartCaptcha::SCRIPT_URL,
                [],
                false,
                true
            );
        }

        add_filter('script_loader_tag', [$this, 'defer_script'], 10, 2);
    }

    /**
     * @param list<string> $classes
     * @return list<string>
     */
    public function body_class(array $classes): array
    {
        $classes[] = $this->provider() === 'google' ? 'dstk-has-google-captcha' : 'dstk-has-yandex-captcha';

        return $classes;
    }

    public function defer_script(string $tag, string $handle): string
    {
        $captcha_handles = ['dstk-yandex-smartcaptcha', 'dstk-google-recaptcha'];

        if (! in_array($handle, $captcha_handles, true) || str_contains($tag, ' defer')) {
            return $tag;
        }

        return str_replace(' src=', ' defer src=', $tag);
    }

    public function render_widget(): void
    {
        echo '<div class="dstk-login-captcha">';

        if ($this->provider() === 'google') {
            echo '<div class="g-recaptcha" data-sitekey="' . esc_attr($this->google_site_key()) . '"></div>';
            echo '</div>';

            return;
        }

        $key = $this->client_key();
        $language = $this->language();

        echo '<div class="smart-captcha" data-sitekey="' . esc_attr($key) . '" data-hl="' . esc_attr($language) . '"></div>';
        echo '</div>';
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

        if ($this->provider() === 'google') {
            $result = $this->google->verify(
                $this->google_secret_key(),
                $this->google->posted_token(),
                $this->request_ip()
            );
            $error_code = 'dstk_google_captcha';
        } else {
            $result = $this->captcha->verify(
                $this->server_key(),
                $this->captcha->posted_token(),
                $this->request_ip()
            );
            $error_code = 'dstk_yandex_captcha';
        }

        if (! empty($result['ok'])) {
            return $user;
        }

        return new WP_Error(
            $error_code,
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

        if ($this->provider() === 'google') {
            echo esc_html__('Google reCAPTCHA включена, но не работает: укажите ключ сайта и секретный ключ.', 'dr-slon-toolkit');
        } else {
            echo esc_html__('Yandex SmartCaptcha включена, но не работает: укажите клиентский и серверный ключи.', 'dr-slon-toolkit');
        }

        echo '</p></div>';
    }

    public function is_runtime_enabled(): bool
    {
        if ($this->provider() === 'google') {
            return $this->google_site_key() !== '' && $this->google_secret_key() !== '';
        }

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

    private function provider(): string
    {
        $provider = (string) ($this->captcha_settings()['provider'] ?? 'yandex');

        return $provider === 'google' ? 'google' : 'yandex';
    }

    private function client_key(): string
    {
        return (string) ($this->captcha_settings()['client_key'] ?? '');
    }

    private function server_key(): string
    {
        return (string) ($this->captcha_settings()['server_key'] ?? '');
    }

    private function google_site_key(): string
    {
        return (string) ($this->captcha_settings()['google_site_key'] ?? '');
    }

    private function google_secret_key(): string
    {
        return (string) ($this->captcha_settings()['google_secret_key'] ?? '');
    }

    private function language(): string
    {
        $language = (string) ($this->captcha_settings()['language'] ?? 'ru');

        return in_array($language, ['ru', 'en'], true) ? $language : 'ru';
    }

    /**
     * @return array<string, mixed>
     */
    private function captcha_settings(): array
    {
        $settings = Settings::all();
        $captcha = isset($settings['yandex_captcha']) && is_array($settings['yandex_captcha']) ? $settings['yandex_captcha'] : [];

        return $captcha;
    }
}
