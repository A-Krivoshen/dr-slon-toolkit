<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Tests\Unit;

use DrSlon\Toolkit\Core\Settings;
use DrSlon\Toolkit\Integrations\GoogleRecaptcha;
use DrSlon\Toolkit\Integrations\YandexSmartCaptcha;
use DrSlon\Toolkit\Modules\YandexCaptchaModule;
use PHPUnit\Framework\TestCase;
use WP_Error;

final class GoogleRecaptchaTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['dstk_test_options'] = [
            'dstk_settings' => [
                'modules'        => ['yandex_captcha' => true],
                'yandex_captcha' => [
                    'provider'          => 'google',
                    'client_key'        => 'clientkey123',
                    'server_key'        => 'serverkey123',
                    'language'          => 'ru',
                    'google_site_key'   => 'googlesitekey1',
                    'google_secret_key' => 'googlesecretkey1',
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

    public function test_widget_is_checkbox_not_yandex(): void
    {
        ob_start();
        (new YandexCaptchaModule())->render_widget();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('dstk-login-captcha', $html);
        self::assertStringContainsString('class="g-recaptcha"', $html);
        self::assertStringContainsString('data-sitekey="googlesitekey1"', $html);
        self::assertStringNotContainsString('smart-captcha', $html);
        self::assertStringNotContainsString('data-size="invisible"', $html);
    }

    public function test_login_script_is_deferred_v2_checkbox(): void
    {
        (new YandexCaptchaModule())->enqueue_assets();

        self::assertSame(
            GoogleRecaptcha::SCRIPT_URL . '?hl=ru',
            $GLOBALS['dstk_test_scripts'][0]['src']
        );
        self::assertSame('dstk-google-recaptcha', $GLOBALS['dstk_test_scripts'][0]['handle']);
        self::assertTrue($GLOBALS['dstk_test_scripts'][0]['in_footer']);
        self::assertSame(['dstk-has-google-captcha'], (new YandexCaptchaModule())->body_class([]));

        $module = new YandexCaptchaModule();
        $tag = '<script src="https://www.google.com/recaptcha/api.js?hl=ru"></script>';

        self::assertStringContainsString(' defer src=', $module->defer_script($tag, 'dstk-google-recaptcha'));
        self::assertSame($tag, $module->defer_script($tag, 'other-script'));
    }

    public function test_english_language_is_passed_to_google_script(): void
    {
        $GLOBALS['dstk_test_options']['dstk_settings']['yandex_captcha']['language'] = 'en';

        (new YandexCaptchaModule())->enqueue_assets();

        self::assertSame(GoogleRecaptcha::SCRIPT_URL . '?hl=en', $GLOBALS['dstk_test_scripts'][0]['src']);
    }

    public function test_yandex_provider_ignores_google_token(): void
    {
        $GLOBALS['dstk_test_options']['dstk_settings']['yandex_captcha']['provider'] = 'yandex';
        $_POST[GoogleRecaptcha::TOKEN_FIELD] = 'google-token';

        $result = (new YandexCaptchaModule())->block_without_captcha(null, 'admin', 'secret');

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('dstk_yandex_captcha', $result->get_error_code());
        self::assertSame([], $GLOBALS['dstk_test_remote_posts']);
    }

    public function test_google_provider_ignores_yandex_token(): void
    {
        $_POST[YandexSmartCaptcha::TOKEN_FIELD] = 'smart-token-value';

        $result = (new YandexCaptchaModule())->block_without_captcha(null, 'admin', 'secret');

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('dstk_google_captcha', $result->get_error_code());
        self::assertSame([], $GLOBALS['dstk_test_remote_posts']);
    }

    public function test_missing_token_is_rejected_without_http(): void
    {
        $result = (new YandexCaptchaModule())->block_without_captcha(null, 'admin', 'secret');

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('dstk_google_captcha', $result->get_error_code());
        self::assertSame([], $GLOBALS['dstk_test_remote_posts']);
    }

    public function test_success_allows_login_and_posts_siteverify(): void
    {
        $_POST[GoogleRecaptcha::TOKEN_FIELD] = 'token-value';
        $GLOBALS['dstk_test_remote_post_response'] = [
            'response' => ['code' => 200],
            'body'     => '{"success":true,"hostname":"example.test"}',
        ];

        $result = (new YandexCaptchaModule())->block_without_captcha(null, 'admin', 'secret');

        self::assertNull($result);
        self::assertSame(GoogleRecaptcha::VERIFY_URL, $GLOBALS['dstk_test_remote_posts'][0][0]);
        $body = $GLOBALS['dstk_test_remote_posts'][0][1]['body'];
        self::assertSame('googlesecretkey1', $body['secret']);
        self::assertSame('token-value', $body['response']);
        self::assertSame('203.0.113.10', $body['remoteip']);
        self::assertSame(3, $GLOBALS['dstk_test_remote_posts'][0][1]['timeout']);
    }

    public function test_failed_checkbox_rejects_login(): void
    {
        $_POST[GoogleRecaptcha::TOKEN_FIELD] = 'bad-token';
        $GLOBALS['dstk_test_remote_post_response'] = [
            'response' => ['code' => 200],
            'body'     => '{"success":false,"error-codes":["invalid-input-response"]}',
        ];

        $result = (new YandexCaptchaModule())->block_without_captcha(null, 'admin', 'secret');

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('dstk_google_captcha', $result->get_error_code());
    }

    public function test_google_outage_fail_open(): void
    {
        $_POST[GoogleRecaptcha::TOKEN_FIELD] = 'token-value';
        $GLOBALS['dstk_test_remote_post_response'] = new WP_Error('http_request_failed', 'timeout');

        self::assertNull((new YandexCaptchaModule())->block_without_captcha(null, 'admin', 'secret'));
    }

    public function test_http_error_fail_open(): void
    {
        $_POST[GoogleRecaptcha::TOKEN_FIELD] = 'token-value';
        $GLOBALS['dstk_test_remote_post_response'] = [
            'response' => ['code' => 503],
            'body'     => '',
        ];

        self::assertNull((new YandexCaptchaModule())->block_without_captcha(null, 'admin', 'secret'));
    }

    public function test_invalid_json_rejects_login(): void
    {
        $_POST[GoogleRecaptcha::TOKEN_FIELD] = 'token-value';
        $GLOBALS['dstk_test_remote_post_response'] = [
            'response' => ['code' => 200],
            'body'     => 'not-json',
        ];

        $result = (new YandexCaptchaModule())->block_without_captcha(null, 'admin', 'secret');

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('dstk_google_captcha', $result->get_error_code());
    }

    public function test_rejection_survives_core_password_check(): void
    {
        remove_all_filters('authenticate');
        add_filter(
            'authenticate',
            static function ($user, string $username, string $password) {
                unset($user);

                return ($username !== '' && $password !== '') ? 'authenticated-user' : null;
            },
            20,
            3
        );

        (new YandexCaptchaModule())->register();

        $result = apply_filters('authenticate', null, 'admin', 'secret');

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('dstk_google_captcha', $result->get_error_code());
    }

    public function test_lostpassword_skips_google(): void
    {
        $_REQUEST['action'] = 'lostpassword';
        $_POST[GoogleRecaptcha::TOKEN_FIELD] = 'token-value';

        self::assertNull((new YandexCaptchaModule())->block_without_captcha(null, 'admin', 'secret'));
        self::assertSame([], $GLOBALS['dstk_test_remote_posts']);
    }

    public function test_incomplete_google_keys_skip_login_hooks(): void
    {
        $GLOBALS['dstk_test_options']['dstk_settings']['yandex_captcha']['google_secret_key'] = '';
        $GLOBALS['dstk_test_is_admin'] = true;

        (new YandexCaptchaModule())->register();

        self::assertFalse((new YandexCaptchaModule())->is_runtime_enabled());
        self::assertFalse(has_filter('login_form'));
        self::assertFalse(has_filter('authenticate'));
        self::assertTrue(has_filter('admin_notices'));
    }

    public function test_incomplete_notice_names_google_keys(): void
    {
        $GLOBALS['dstk_test_options']['dstk_settings']['yandex_captcha']['google_site_key'] = '';
        $GLOBALS['dstk_test_user_capabilities'] = ['manage_options'];

        ob_start();
        (new YandexCaptchaModule())->render_incomplete_notice();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('ключ сайта и секретный ключ', $html);
        self::assertStringNotContainsString('клиентский и серверный', $html);
    }

    public function test_posted_token_ignores_non_string(): void
    {
        $_POST[GoogleRecaptcha::TOKEN_FIELD] = ['evil'];

        self::assertSame('', (new GoogleRecaptcha())->posted_token());
    }

    public function test_site_key_quotes_are_stripped_on_read(): void
    {
        $GLOBALS['dstk_test_options']['dstk_settings']['yandex_captcha']['google_site_key'] = 'abc"onclick=alert(1)';

        ob_start();
        (new YandexCaptchaModule())->render_widget();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('data-sitekey="abconclickalert1"', $html);
        self::assertStringNotContainsString('alert(1)', $html);
    }

    public function test_blank_google_secret_is_kept(): void
    {
        $GLOBALS['dstk_test_options'][Settings::OPTION_KEY] = [
            'yandex_captcha' => [
                'provider'          => 'google',
                'google_site_key'   => 'googlesitekey1',
                'google_secret_key' => 'googlesecretkey1',
                'server_key'        => 'serverkey123',
            ],
        ];

        $settings = Settings::merge_with_defaults(
            [
                'yandex_captcha' => [
                    'provider'          => 'google',
                    'google_site_key'   => 'googlesitekey1',
                    'google_secret_key' => '',
                    'server_key'        => '',
                    'language'          => 'en',
                ],
            ],
            true
        );

        self::assertSame('google', $settings['yandex_captcha']['provider']);
        self::assertSame('googlesecretkey1', $settings['yandex_captcha']['google_secret_key']);
        self::assertSame('serverkey123', $settings['yandex_captcha']['server_key']);
        self::assertSame('en', $settings['yandex_captcha']['language']);
    }

    public function test_missing_provider_stays_on_yandex(): void
    {
        $GLOBALS['dstk_test_options'][Settings::OPTION_KEY] = [
            'yandex_captcha' => [
                'client_key' => 'clientkey123',
                'server_key' => 'serverkey123',
                'language'   => 'ru',
            ],
        ];

        $settings = Settings::merge_with_defaults(
            [
                'yandex_captcha' => [
                    'client_key' => 'clientkey123',
                    'server_key' => 'serverkey123',
                    'language'   => 'ru',
                ],
            ],
            true
        );

        self::assertSame('yandex', $settings['yandex_captcha']['provider']);
        self::assertSame('clientkey123', $settings['yandex_captcha']['client_key']);
        self::assertSame('serverkey123', $settings['yandex_captcha']['server_key']);
        self::assertSame('', $settings['yandex_captcha']['google_site_key']);
        self::assertSame('', $settings['yandex_captcha']['google_secret_key']);
    }

    public function test_invalid_provider_falls_back_to_yandex(): void
    {
        $settings = Settings::merge_with_defaults(
            [
                'yandex_captcha' => [
                    'provider'   => 'score',
                    'client_key' => 'clientkey123',
                    'server_key' => 'serverkey123',
                ],
            ],
            true
        );

        self::assertSame('yandex', $settings['yandex_captcha']['provider']);
    }

    public function test_resave_keeps_both_secrets(): void
    {
        $GLOBALS['dstk_test_options'][Settings::OPTION_KEY] = [
            'modules'        => ['yandex_captcha' => true],
            'yandex_captcha' => [
                'provider'          => 'google',
                'client_key'        => 'clientkey123',
                'server_key'        => 'serverkey123',
                'language'          => 'ru',
                'google_site_key'   => 'googlesitekey1',
                'google_secret_key' => 'googlesecretkey1',
            ],
        ];

        $overlay = Settings::all();
        $overlay['ai_agents'] = ['site_blurb' => 'hello'];
        $settings = Settings::merge_with_defaults($overlay, true);

        self::assertSame('google', $settings['yandex_captcha']['provider']);
        self::assertSame('googlesecretkey1', $settings['yandex_captcha']['google_secret_key']);
        self::assertSame('serverkey123', $settings['yandex_captcha']['server_key']);
        self::assertSame('googlesitekey1', $settings['yandex_captcha']['google_site_key']);
    }

    public function test_css_keeps_submit_inside_form_for_google(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/admin/login-captcha.css');

        self::assertMatchesRegularExpression('/dstk-has-google-captcha #loginform\s*\{[^}]*display:\s*flow-root/s', $css);
        self::assertStringContainsString('.g-recaptcha', $css);
        self::assertStringContainsString('width: 304px', $css);
    }
}
