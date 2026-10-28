<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Tests\Unit;

use DrSlon\Toolkit\Modules\LoginAttemptsModule;
use PHPUnit\Framework\TestCase;
use WP_Error;

final class LoginAttemptsModuleTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['dstk_test_options'] = [
            'dstk_settings' => [
                'login_attempts' => [
                    'max_attempts'    => 3,
                    'window_minutes'  => 15,
                    'lockout_minutes' => 15,
                ],
            ],
        ];
        $_SERVER['REMOTE_ADDR'] = '198.51.100.20';
    }

    public function test_lockout_after_max_failures(): void
    {
        $module = new LoginAttemptsModule();

        $module->record_failure();
        $module->record_failure();
        self::assertFalse($module->is_locked());

        $module->record_failure();
        self::assertTrue($module->is_locked());
        self::assertGreaterThan(0, $module->minutes_remaining());

        $result = $module->block_if_locked(null, 'admin', 'x');
        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('dstk_login_locked', $result->get_error_code());
    }

    public function test_lockout_survives_core_password_check(): void
    {
        $GLOBALS['dstk_test_filters'] = [];
        $module = new LoginAttemptsModule();
        $module->record_failure();
        $module->record_failure();
        $module->record_failure();

        add_filter(
            'authenticate',
            static function ($user, string $username, string $password) {
                if ($username !== '' && $password !== '') {
                    return 'authenticated-user';
                }

                return $user;
            },
            20,
            3
        );
        $module->register();

        self::assertArrayHasKey(110, $GLOBALS['dstk_test_filters']['authenticate']);

        $result = apply_filters('authenticate', null, 'admin', 'secret');

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('dstk_login_locked', $result->get_error_code());
    }

    public function test_success_clears_failures(): void
    {
        $module = new LoginAttemptsModule();
        $module->record_failure();
        $module->record_failure();
        $module->clear_current();

        self::assertFalse($module->is_locked());
        self::assertSame([], $module->store());
    }

    public function test_failures_are_hashed_not_stored_raw(): void
    {
        $module = new LoginAttemptsModule();
        $module->record_failure();
        $json = wp_json_encode($module->store());

        self::assertIsString($json);
        self::assertStringNotContainsString('198.51.100.20', $json);
        self::assertArrayHasKey($module->fingerprint('198.51.100.20'), $module->store());
    }

    public function test_settings_clamp_attempts(): void
    {
        $settings = \DrSlon\Toolkit\Core\Settings::merge_with_defaults(
            [
                'login_attempts' => [
                    'max_attempts'    => 99,
                    'window_minutes'  => 0,
                    'lockout_minutes' => 5000,
                ],
            ],
            true
        );

        self::assertSame(20, $settings['login_attempts']['max_attempts']);
        self::assertSame(1, $settings['login_attempts']['window_minutes']);
        self::assertSame(1440, $settings['login_attempts']['lockout_minutes']);
        self::assertSame('', $settings['login_attempts']['whitelist']);
    }

    public function test_whitelisted_ip_is_not_counted_or_locked(): void
    {
        $GLOBALS['dstk_test_options']['dstk_settings']['login_attempts']['whitelist'] = '198.51.100.20';
        $module = new LoginAttemptsModule();

        $module->record_failure();
        $module->record_failure();
        $module->record_failure();

        self::assertTrue($module->is_whitelisted());
        self::assertFalse($module->is_locked());
        self::assertSame([], $module->store());
    }

    public function test_settings_keep_whitelist_when_the_form_omits_it(): void
    {
        $GLOBALS['dstk_test_options']['dstk_settings']['login_attempts']['whitelist'] = "192.0.2.10\n198.51.100.20";

        $settings = \DrSlon\Toolkit\Core\Settings::merge_with_defaults(
            [
                'login_attempts' => [
                    'max_attempts'    => 5,
                    'window_minutes'  => 15,
                    'lockout_minutes' => 15,
                ],
            ],
            true
        );

        self::assertSame("192.0.2.10\n198.51.100.20", $settings['login_attempts']['whitelist']);
    }

    public function test_settings_sanitize_whitelist(): void
    {
        $settings = \DrSlon\Toolkit\Core\Settings::merge_with_defaults(
            [
                'login_attempts' => [
                    'whitelist' => "192.0.2.10\nbogus\n198.51.100.20\n192.0.2.10",
                ],
            ],
            true
        );

        self::assertSame("192.0.2.10\n198.51.100.20", $settings['login_attempts']['whitelist']);
    }

    public function test_whitelist_keeps_ipv6_and_caps_at_fifty(): void
    {
        $ips = [];

        for ($index = 1; $index <= 50; $index++) {
            $ips[] = sprintf('2001:db8::%x', $index);
        }

        $ips[] = '192.0.2.55';

        $settings = \DrSlon\Toolkit\Core\Settings::merge_with_defaults(
            [
                'login_attempts' => [
                    'whitelist' => implode("\n", $ips),
                ],
            ],
            true
        );

        $saved = explode("\n", $settings['login_attempts']['whitelist']);

        self::assertCount(50, $saved);
        self::assertSame('2001:db8::1', $saved[0]);
        self::assertSame('2001:db8::32', $saved[49]);
        self::assertNotContains('192.0.2.55', $saved);
    }

    public function test_whitelisted_ipv6_is_not_counted_or_locked(): void
    {
        $_SERVER['REMOTE_ADDR'] = '2001:db8::20';
        $GLOBALS['dstk_test_options']['dstk_settings']['login_attempts']['whitelist'] = '2001:db8::20';
        $module = new LoginAttemptsModule();

        $module->record_failure();
        $module->record_failure();
        $module->record_failure();

        self::assertTrue($module->is_whitelisted());
        self::assertFalse($module->is_locked());
        self::assertSame([], $module->store());
    }
}
