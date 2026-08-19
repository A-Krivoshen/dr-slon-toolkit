<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Tests\Unit;

use DrSlon\Toolkit\Core\Settings;
use DrSlon\Toolkit\Integrations\YandexSmartCaptcha;
use DrSlon\Toolkit\Modules\YandexCaptchaModule;
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
}
