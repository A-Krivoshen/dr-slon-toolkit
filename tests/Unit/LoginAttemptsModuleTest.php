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
    }
}
