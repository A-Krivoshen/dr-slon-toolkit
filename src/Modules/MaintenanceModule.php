<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Modules;

use DrSlon\Toolkit\Core\ModuleInterface;
use DrSlon\Toolkit\Core\Settings;
use DrSlon\Toolkit\Modules\Maintenance\Screen;
use WP_Error;

final class MaintenanceModule implements ModuleInterface
{
    public const DEFAULT_HEADING = 'Сайт на реконструкции';
    public const DEFAULT_MESSAGE = 'Страница временно закрыта. Скоро здесь снова будет сайт.';
    public const CREDIT_URL = 'https://krivoshein.site';
    public const CREDIT_LABEL = 'Плагин разработан ИП Кривошеин';
    public const ROBOTS = 'noindex, nofollow, noarchive';
    public const RETRY_AFTER = 86400;
    public const PREVIEW_ACTION = 'dstk_maintenance_preview';

    public function register(): void
    {
        add_action('template_redirect', [$this, 'maybe_block_front'], -5);
        add_action('init', [$this, 'maybe_block_xmlrpc'], 0);
        add_action('admin_init', [$this, 'maybe_block_ajax'], 0);
        add_filter('rest_pre_dispatch', [$this, 'maybe_block_rest'], 0, 1);
        add_action('admin_notices', [$this, 'render_admin_notice']);
    }

    /**
     * @param array{
     *     enabled?:bool,
     *     cron?:bool,
     *     cli?:bool,
     *     login?:bool,
     *     admin?:bool,
     *     ajax?:bool,
     *     rest?:bool,
     *     xmlrpc?:bool,
     *     can_manage?:bool,
     *     can_edit?:bool,
     *     preview?:bool
     * } $context
     */
    public static function should_block(array $context): bool
    {
        if (empty($context['enabled'])) {
            return false;
        }

        if (! empty($context['cron']) || ! empty($context['cli']) || ! empty($context['login'])) {
            return false;
        }

        $can_manage = ! empty($context['can_manage']);
        $is_admin_screen = ! empty($context['admin'])
            && empty($context['ajax'])
            && empty($context['rest'])
            && empty($context['xmlrpc']);

        if (! empty($context['preview']) && $can_manage && ! $is_admin_screen && empty($context['rest']) && empty($context['ajax']) && empty($context['xmlrpc'])) {
            return true;
        }

        if ($is_admin_screen) {
            return false;
        }

        if ($can_manage && empty($context['rest']) && empty($context['ajax']) && empty($context['xmlrpc'])) {
            return false;
        }

        if ((! empty($context['rest']) || ! empty($context['ajax'])) && ($can_manage || ! empty($context['can_edit']))) {
            return false;
        }

        return true;
    }

    public function maybe_block_front(): void
    {
        if (! self::should_block($this->request_context())) {
            return;
        }

        $this->send_block_headers(true);
        echo $this->page_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Screen::markup() escapes dynamic values.
        exit;
    }

    public function maybe_block_xmlrpc(): void
    {
        if (! defined('XMLRPC_REQUEST') || ! XMLRPC_REQUEST) {
            return;
        }

        if (! self::should_block($this->request_context())) {
            return;
        }

        $this->send_block_headers(false);
        exit;
    }

    public function maybe_block_ajax(): void
    {
        if (! wp_doing_ajax()) {
            return;
        }

        if (! self::should_block($this->request_context())) {
            return;
        }

        $this->send_block_headers(false);
        exit;
    }

    /**
     * @param mixed $result
     * @return mixed
     */
    public function maybe_block_rest($result)
    {
        if ($result instanceof WP_Error) {
            return $result;
        }

        $context = $this->request_context();
        $context['rest'] = true;

        if (! self::should_block($context)) {
            return $result;
        }

        $this->send_block_headers(false);

        return new WP_Error(
            'dstk_maintenance',
            __('Сайт на реконструкции.', 'dr-slon-toolkit'),
            ['status' => 503]
        );
    }

    public function render_admin_notice(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $settings_url = admin_url('admin.php?page=dr-slon-toolkit');

        echo '<div class="notice notice-warning"><p>';
        echo esc_html__('Сайт закрыт для посетителей: заглушка «На реконструкции», ответ 503 и запрет индексации. Администратор по-прежнему видит сайт и страницу входа.', 'dr-slon-toolkit');
        echo ' <a href="' . esc_url($settings_url) . '">';
        echo esc_html__('Открыть настройки', 'dr-slon-toolkit');
        echo '</a></p></div>';
    }

    public function page_markup(): string
    {
        $settings = Settings::all();
        $maintenance = isset($settings['maintenance']) && is_array($settings['maintenance']) ? $settings['maintenance'] : [];
        $heading = isset($maintenance['heading']) ? (string) $maintenance['heading'] : self::DEFAULT_HEADING;
        $message = isset($maintenance['message']) ? (string) $maintenance['message'] : self::DEFAULT_MESSAGE;

        return Screen::markup(
            get_bloginfo('name'),
            $heading,
            $message,
            get_bloginfo('language'),
            DSTK_VERSION
        );
    }

    public function is_login_request(): bool
    {
        $script = isset($_SERVER['SCRIPT_NAME'])
            ? sanitize_text_field(wp_unslash((string) $_SERVER['SCRIPT_NAME']))
            : '';

        if ($script !== '' && str_ends_with(strtolower($script), 'wp-login.php')) {
            return true;
        }

        $custom = get_query_var('dstk_custom_login', '');

        return is_string($custom) && $custom !== '';
    }

    public function is_preview_request(): bool
    {
        if (! current_user_can('manage_options')) {
            return false;
        }

        // The preview flag is checked together with the nonce below.
        $flag = isset($_GET['dstk_maintenance_preview']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ? sanitize_key(wp_unslash((string) $_GET['dstk_maintenance_preview']))
            : '';

        if ($flag !== '1') {
            return false;
        }

        $nonce = isset($_GET['_wpnonce']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ? sanitize_text_field(wp_unslash((string) $_GET['_wpnonce']))
            : '';

        return wp_verify_nonce($nonce, self::PREVIEW_ACTION) !== false;
    }

    /**
     * @return array{
     *     enabled:bool,
     *     cron:bool,
     *     cli:bool,
     *     login:bool,
     *     admin:bool,
     *     ajax:bool,
     *     rest:bool,
     *     xmlrpc:bool,
     *     can_manage:bool,
     *     can_edit:bool,
     *     preview:bool
     * }
     */
    public function request_context(): array
    {
        return [
            'enabled'    => Settings::module_enabled('maintenance'),
            'cron'       => wp_doing_cron(),
            'cli'        => defined('WP_CLI') && WP_CLI,
            'login'      => $this->is_login_request(),
            'admin'      => is_admin(),
            'ajax'       => wp_doing_ajax(),
            'rest'       => defined('REST_REQUEST') && REST_REQUEST,
            'xmlrpc'     => defined('XMLRPC_REQUEST') && XMLRPC_REQUEST,
            'can_manage' => current_user_can('manage_options'),
            'can_edit'   => current_user_can('edit_posts'),
            'preview'    => $this->is_preview_request(),
        ];
    }

    public function send_block_headers(bool $html): void
    {
        nocache_headers();
        status_header(503);

        $lines = [
            'Retry-After: ' . (string) self::RETRY_AFTER,
            'X-Robots-Tag: ' . self::ROBOTS,
        ];

        if ($html) {
            $lines[] = 'Content-Type: text/html; charset=UTF-8';
        }

        if (! isset($GLOBALS['dstk_test_sent_headers']) || ! is_array($GLOBALS['dstk_test_sent_headers'])) {
            $GLOBALS['dstk_test_sent_headers'] = [];
        }

        foreach ($lines as $line) {
            $GLOBALS['dstk_test_sent_headers'][] = $line;

            if (PHP_SAPI !== 'cli' && ! headers_sent()) {
                header($line);
            }
        }
    }
}
