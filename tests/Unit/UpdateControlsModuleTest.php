<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Tests\Unit;

use DrSlon\Toolkit\Core\Settings;
use DrSlon\Toolkit\Modules\UpdateControlsModule;
use PHPUnit\Framework\TestCase;

final class UpdateControlsModuleTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['dstk_test_options']['dstk_settings'] = [
            'update_controls' => [
                'core_mode'           => 'minor',
                'plugins'             => true,
                'themes'              => true,
                'translations'        => true,
                'email_notifications' => true,
            ],
        ];
    }

    public function test_minor_mode_never_reverses_a_core_veto(): void
    {
        $module = new UpdateControlsModule();

        self::assertFalse($module->filter_allow_major_auto_core_updates(true));
        self::assertFalse($module->filter_auto_update_core(false, null));
        self::assertTrue($module->filter_auto_update_core(true, null));
        self::assertFalse($module->filter_allow_dev_auto_core_updates(true));
    }

    public function test_enabled_controls_preserve_incoming_decisions(): void
    {
        $module = new UpdateControlsModule();

        self::assertFalse($module->filter_auto_update_plugin(false, null));
        self::assertTrue($module->filter_auto_update_plugin(true, null));
        self::assertFalse($module->filter_send_email(false));
        self::assertTrue($module->filter_send_email(true));
    }

    public function test_legacy_security_mode_behaves_like_minor(): void
    {
        $GLOBALS['dstk_test_options']['dstk_settings']['update_controls']['core_mode'] = 'security';
        $module = new UpdateControlsModule();

        self::assertFalse($module->filter_allow_major_auto_core_updates(true));
        self::assertFalse($module->filter_allow_dev_auto_core_updates(true));
        self::assertTrue($module->filter_allow_minor_auto_core_updates(true));
        self::assertFalse($module->filter_auto_update_core(false, null));
        self::assertTrue($module->filter_auto_update_core(true, null));
    }

    public function test_core_off_blocks_all_core_auto_updates(): void
    {
        $GLOBALS['dstk_test_options']['dstk_settings']['update_controls']['core_mode'] = 'off';
        $module = new UpdateControlsModule();

        self::assertFalse($module->filter_allow_major_auto_core_updates(true));
        self::assertFalse($module->filter_allow_minor_auto_core_updates(true));
        self::assertFalse($module->filter_auto_update_core(true, null));
    }

    public function test_settings_map_security_to_minor_on_sanitize(): void
    {
        $settings = Settings::merge_with_defaults(
            [
                'update_controls' => [
                    '_submitted' => '1',
                    'core_mode'  => 'security',
                    'plugins'    => '1',
                ],
            ]
        );

        self::assertSame('minor', $settings['update_controls']['core_mode']);
    }
}
