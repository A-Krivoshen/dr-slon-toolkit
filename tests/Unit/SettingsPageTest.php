<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Tests\Unit;

use DrSlon\Toolkit\Admin\SettingsPage;
use PHPUnit\Framework\TestCase;

final class SettingsPageTest extends TestCase
{
    public function test_top_level_settings_page_renders_settings_api_errors(): void
    {
        $GLOBALS['dstk_test_options'] = [];
        $GLOBALS['dstk_test_settings_errors_calls'] = 0;
        $_GET = [];

        ob_start();
        (new SettingsPage())->render_page();
        $output = (string) ob_get_clean();

        self::assertSame(1, $GLOBALS['dstk_test_settings_errors_calls']);
        self::assertStringContainsString('data-test="settings-errors"', $output);
    }

    public function test_redirect_editor_renders_two_fields_per_rule(): void
    {
        $GLOBALS['dstk_test_options'] = [
            'dstk_settings' => [
                'redirects' => [
                    'rules' => [
                        ['from' => '/staryj', 'to' => 'https://example.test/novyj', 'status' => 302, 'enabled' => false],
                    ],
                ],
            ],
        ];

        ob_start();
        (new SettingsPage())->render_redirects_fields();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('name="dstk_settings[redirects][editor]"', $output);
        self::assertStringContainsString('name="dstk_settings[redirects][rows][0][from]"', $output);
        self::assertStringContainsString('name="dstk_settings[redirects][rows][0][to]"', $output);
        self::assertStringContainsString('value="/staryj"', $output);
        self::assertStringContainsString('value="302" selected', $output);
        self::assertStringNotContainsString('rules_text', $output);
        self::assertStringContainsString('dstk-redirect-row__handle', $output);
    }

    public function test_redirect_script_loads_only_on_the_settings_screen(): void
    {
        $GLOBALS['dstk_test_scripts'] = [];
        $page = new SettingsPage();

        $page->enqueue_assets('toplevel_page_dr-slon-toolkit');
        self::assertSame('dstk-admin-redirects', $GLOBALS['dstk_test_scripts'][0]['handle']);
        self::assertTrue($GLOBALS['dstk_test_scripts'][0]['in_footer']);

        $GLOBALS['dstk_test_scripts'] = [];
        $page->enqueue_assets('dr-slon-toolkit_page_dr-slon-toolkit-help');
        self::assertSame([], $GLOBALS['dstk_test_scripts']);
    }

    public function test_skipped_redirect_rows_raise_a_settings_error(): void
    {
        $GLOBALS['dstk_test_options'] = [];
        $GLOBALS['dstk_test_settings_error_list'] = [];

        (new SettingsPage())->sanitize_settings(
            [
                'redirects' => [
                    '_submitted' => '1',
                    'editor'     => '1',
                    'rows'       => [
                        ['from' => '/loop', 'to' => '/loop', 'status' => '301', 'enabled' => '1'],
                    ],
                ],
            ]
        );

        self::assertSame('dstk_redirect_rows', $GLOBALS['dstk_test_settings_error_list'][0]['code']);
    }
}
