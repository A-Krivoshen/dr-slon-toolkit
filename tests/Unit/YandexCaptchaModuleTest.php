<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Tests\Unit;

use DrSlon\Toolkit\Core\Settings;
use DrSlon\Toolkit\Integrations\YandexSmartCaptcha;
use DrSlon\Toolkit\Modules\YandexCaptchaModule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WP_Error;

final class YandexCaptchaModuleTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['dstk_test_options'] = [
            'dstk_settings' => [
                'modules'        => ['yandex_captcha' => true],
                'yandex_captcha' => [
                    'client_key' => 'clientkey123',
                    'server_key' => 'serverkey123',
                    'language'   => 'ru',
                ],
            ],
        ];
        $GLOBALS['dstk_test_remote_posts'] = [];
        unset($GLOBALS['dstk_test_remote_post_response']);
        $_POST = ['log' => 'admin', 'pwd' => 'secret'];
        $_REQUEST = ['action' => 'login'];
        $_SERVER['REMOTE_ADDR'] = '203.0.113.10';
        $GLOBALS['dstk_test_styles'] = [];
        $GLOBALS['dstk_test_scripts'] = [];
        $GLOBALS['dstk_test_filters'] = [];
        $GLOBALS['dstk_test_actions'] = [];
        $GLOBALS['dstk_test_is_admin'] = false;
        unset($GLOBALS['dstk_test_user_capabilities']);
    }

    public function test_widget_fits_login_form_wrapper(): void
    {
        ob_start();
        (new YandexCaptchaModule())->render_widget();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('dstk-login-captcha', $html);
        self::assertStringContainsString('smart-captcha', $html);
        self::assertStringNotContainsString('height:100px', $html);
    }

    public function test_login_assets_include_layout_css(): void
    {
        (new YandexCaptchaModule())->enqueue_assets();

        $handles = array_column($GLOBALS['dstk_test_styles'], 'handle');
        self::assertContains('dstk-yandex-smartcaptcha', $handles);
        self::assertStringContainsString('login-captcha.css', (string) $GLOBALS['dstk_test_styles'][0]['src']);
        self::assertSame(['dstk-has-yandex-captcha'], (new YandexCaptchaModule())->body_class([]));
    }

    public function test_login_captcha_css_keeps_submit_inside_form(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/admin/login-captcha.css');

        self::assertMatchesRegularExpression('/#loginform\s*\{[^}]*display:\s*flow-root/s', $css);
        self::assertMatchesRegularExpression('/#loginform\s*\{[^}]*overflow:\s*visible/s', $css);
        self::assertStringContainsString('width: 360px', $css);
        self::assertStringContainsString('clear: both', $css);
        self::assertStringContainsString('p.submit', $css);
        self::assertStringContainsString('min-height: 36px', $css);
        self::assertDoesNotMatchRegularExpression('/body\.login\.dstk-has-yandex-captcha form\s*\{/', $css);
    }

    public function test_register_adds_login_hooks_when_keys_present(): void
    {
        (new YandexCaptchaModule())->register();

        self::assertTrue(has_filter('login_enqueue_scripts'));
        self::assertTrue(has_filter('login_form'));
        self::assertTrue(has_filter('login_body_class'));
        self::assertTrue(has_filter('authenticate'));
        self::assertFalse(has_filter('admin_notices'));
    }

    public function test_register_skips_login_hooks_without_keys(): void
    {
        $GLOBALS['dstk_test_options']['dstk_settings']['yandex_captcha']['server_key'] = '';
        $GLOBALS['dstk_test_is_admin'] = true;

        (new YandexCaptchaModule())->register();

        self::assertFalse(has_filter('login_form'));
        self::assertFalse(has_filter('authenticate'));
        self::assertTrue(has_filter('admin_notices'));
    }

    public function test_widget_uses_sanitized_sitekey_and_language(): void
    {
        $GLOBALS['dstk_test_options']['dstk_settings']['yandex_captcha']['client_key'] = 'abc"onclick=alert(1)';
        $GLOBALS['dstk_test_options']['dstk_settings']['yandex_captcha']['language'] = 'en';

        ob_start();
        (new YandexCaptchaModule())->render_widget();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('data-sitekey="abconclickalert1"', $html);
        self::assertStringNotContainsString('alert(1)', $html);
        self::assertStringNotContainsString('data-sitekey="abc"', $html);
        self::assertStringContainsString('data-hl="en"', $html);
    }

    public function test_widget_language_falls_back_to_ru(): void
    {
        $GLOBALS['dstk_test_options']['dstk_settings']['yandex_captcha']['language'] = 'kk';

        ob_start();
        (new YandexCaptchaModule())->render_widget();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('data-hl="ru"', $html);
    }

    public function test_login_script_is_deferred_yandex_widget(): void
    {
        (new YandexCaptchaModule())->enqueue_assets();

        self::assertSame(YandexSmartCaptcha::SCRIPT_URL, $GLOBALS['dstk_test_scripts'][0]['src']);
        self::assertTrue($GLOBALS['dstk_test_scripts'][0]['in_footer']);
        self::assertSame(DSTK_VERSION, $GLOBALS['dstk_test_styles'][0]['ver']);

        $module = new YandexCaptchaModule();
        $tag = '<script src="https://smartcaptcha.cloud.yandex.ru/captcha.js"></script>';

        self::assertStringContainsString(' defer src=', $module->defer_script($tag, 'dstk-yandex-smartcaptcha'));
        self::assertSame($tag, $module->defer_script($tag, 'other-script'));
        self::assertSame(
            '<script defer src="https://smartcaptcha.cloud.yandex.ru/captcha.js"></script>',
            $module->defer_script('<script defer src="https://smartcaptcha.cloud.yandex.ru/captcha.js"></script>', 'dstk-yandex-smartcaptcha')
        );
    }

    public function test_login_without_token_is_rejected(): void
    {
        $result = (new YandexCaptchaModule())->block_without_captcha(null, 'admin', 'secret');

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('dstk_yandex_captcha', $result->get_error_code());
    }

    public function test_valid_yandex_status_allows_login(): void
    {
        $_POST[YandexSmartCaptcha::TOKEN_FIELD] = 'token-value';
        $GLOBALS['dstk_test_remote_post_response'] = [
            'response' => ['code' => 200],
            'body'     => '{"status":"ok","host":"example.test"}',
        ];

        $result = (new YandexCaptchaModule())->block_without_captcha(null, 'admin', 'secret');

        self::assertNull($result);
        self::assertSame(YandexSmartCaptcha::VALIDATE_URL, $GLOBALS['dstk_test_remote_posts'][0][0]);
    }

    public function test_failed_status_rejects_login(): void
    {
        $_POST[YandexSmartCaptcha::TOKEN_FIELD] = 'bad-token';
        $GLOBALS['dstk_test_remote_post_response'] = [
            'response' => ['code' => 200],
            'body'     => '{"status":"failed","message":"Invalid or expired Token"}',
        ];

        $result = (new YandexCaptchaModule())->block_without_captcha(null, 'admin', 'secret');

        self::assertInstanceOf(WP_Error::class, $result);
    }

    public function test_yandex_outage_fail_open(): void
    {
        $_POST[YandexSmartCaptcha::TOKEN_FIELD] = 'token-value';
        $GLOBALS['dstk_test_remote_post_response'] = new WP_Error('http_request_failed', 'timeout');

        $result = (new YandexCaptchaModule())->block_without_captcha(null, 'admin', 'secret');

        self::assertNull($result);
    }

    public function test_non_form_requests_skip_captcha(): void
    {
        $module = new YandexCaptchaModule();
        $_POST = [];

        self::assertFalse($module->is_login_form_post('admin', 'secret'));
        self::assertNull($module->block_without_captcha(null, 'admin', 'secret'));
    }

    public function test_incomplete_keys_disable_runtime(): void
    {
        $GLOBALS['dstk_test_options']['dstk_settings']['yandex_captcha']['server_key'] = '';

        self::assertFalse((new YandexCaptchaModule())->is_runtime_enabled());
    }

    public function test_settings_keep_server_key_when_blank(): void
    {
        $GLOBALS['dstk_test_options'][Settings::OPTION_KEY] = [
            'yandex_captcha' => [
                'client_key' => 'clientkey123',
                'server_key' => 'serverkey123',
            ],
        ];

        $settings = Settings::merge_with_defaults(
            [
                'yandex_captcha' => [
                    'client_key' => 'clientkey123',
                    'server_key' => '',
                    'language'   => 'en',
                ],
            ],
            true
        );

        self::assertSame('serverkey123', $settings['yandex_captcha']['server_key']);
        self::assertSame('en', $settings['yandex_captcha']['language']);
    }

    public function test_settings_reject_invalid_captcha_language(): void
    {
        $settings = Settings::merge_with_defaults(
            [
                'yandex_captcha' => [
                    'client_key' => 'clientkey123',
                    'server_key' => 'serverkey123',
                    'language'   => 'zz',
                ],
            ],
            true
        );

        self::assertSame('ru', $settings['yandex_captcha']['language']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function non_login_actions(): iterable
    {
        yield 'lostpassword' => ['lostpassword'];
        yield 'retrievepassword' => ['retrievepassword'];
        yield 'resetpass' => ['resetpass'];
        yield 'rp' => ['rp'];
        yield 'register' => ['register'];
        yield 'postpass' => ['postpass'];
    }

    #[DataProvider('non_login_actions')]
    public function test_password_and_register_actions_skip_captcha(string $action): void
    {
        $_REQUEST['action'] = $action;
        $module = new YandexCaptchaModule();

        self::assertFalse($module->is_login_form_post('admin', 'secret'));
        self::assertNull($module->block_without_captcha(null, 'admin', 'secret'));
        self::assertSame([], $GLOBALS['dstk_test_remote_posts']);
    }

    public function test_empty_credentials_skip_captcha(): void
    {
        $module = new YandexCaptchaModule();

        self::assertFalse($module->is_login_form_post('', ''));
        self::assertNull($module->block_without_captcha(null, '', ''));
    }

    public function test_http_error_fail_open(): void
    {
        $_POST[YandexSmartCaptcha::TOKEN_FIELD] = 'token-value';
        $GLOBALS['dstk_test_remote_post_response'] = [
            'response' => ['code' => 503],
            'body'     => '',
        ];

        $result = (new YandexCaptchaModule())->block_without_captcha(null, 'admin', 'secret');

        self::assertNull($result);
    }

    public function test_invalid_json_rejects_login(): void
    {
        $_POST[YandexSmartCaptcha::TOKEN_FIELD] = 'token-value';
        $GLOBALS['dstk_test_remote_post_response'] = [
            'response' => ['code' => 200],
            'body'     => 'not-json',
        ];

        $result = (new YandexCaptchaModule())->block_without_captcha(null, 'admin', 'secret');

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('dstk_yandex_captcha', $result->get_error_code());
    }

    public function test_posted_token_ignores_non_string(): void
    {
        $_POST[YandexSmartCaptcha::TOKEN_FIELD] = ['evil'];

        self::assertSame('', (new YandexSmartCaptcha())->posted_token());
    }

    public function test_request_ip_rejects_invalid_and_keeps_ipv6(): void
    {
        $module = new YandexCaptchaModule();

        $_SERVER['REMOTE_ADDR'] = 'not-an-ip';
        self::assertSame('', $module->request_ip());

        $_SERVER['REMOTE_ADDR'] = '2001:db8::1';
        self::assertSame('2001:db8::1', $module->request_ip());
    }

    public function test_incomplete_notice_for_admins_without_keys(): void
    {
        $GLOBALS['dstk_test_options']['dstk_settings']['yandex_captcha']['server_key'] = '';
        $GLOBALS['dstk_test_user_capabilities'] = ['manage_options'];

        ob_start();
        (new YandexCaptchaModule())->render_incomplete_notice();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('notice-warning', $html);
        self::assertStringContainsString('клиентский и серверный ключи', $html);
    }

    public function test_incomplete_notice_silent_when_runtime_enabled(): void
    {
        ob_start();
        (new YandexCaptchaModule())->render_incomplete_notice();

        self::assertSame('', (string) ob_get_clean());
    }

    public function test_incomplete_notice_requires_manage_options(): void
    {
        $GLOBALS['dstk_test_options']['dstk_settings']['yandex_captcha']['server_key'] = '';
        $GLOBALS['dstk_test_user_capabilities'] = ['read'];

        ob_start();
        (new YandexCaptchaModule())->render_incomplete_notice();

        self::assertSame('', (string) ob_get_clean());
    }
}
