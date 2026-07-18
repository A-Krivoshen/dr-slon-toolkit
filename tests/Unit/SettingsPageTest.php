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
}
