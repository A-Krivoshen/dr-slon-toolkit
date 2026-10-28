<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Tests\Unit;

use DrSlon\Toolkit\Admin\SettingsPage;
use DrSlon\Toolkit\Core\Plugin;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class PluginTsfNoticeTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['dstk_test_options'] = [
            'dstk_settings' => [
                'modules' => [],
            ],
        ];
        $GLOBALS['dstk_test_is_admin'] = true;
        $GLOBALS['dstk_test_actions'] = [];
        $GLOBALS['dstk_test_filters'] = [];
        $GLOBALS['dstk_test_user_capabilities'] = ['manage_options'];
        $_GET = [];
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_notice_hook_is_absent_without_tsf(): void
    {
        $this->register_modules();

        self::assertArrayNotHasKey('admin_notices', $GLOBALS['dstk_test_actions'] ?? []);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_notice_renders_only_on_toolkit_screens(): void
    {
        if (! defined('THE_SEO_FRAMEWORK_PRESENT')) {
            define('THE_SEO_FRAMEWORK_PRESENT', true);
        }

        $options_before = $GLOBALS['dstk_test_options'];
        $this->register_modules();

        $hooks = $GLOBALS['dstk_test_actions']['admin_notices'] ?? [];
        self::assertCount(1, $hooks);
        self::assertSame($options_before, $GLOBALS['dstk_test_options']);

        foreach (['', 'plugins', 'tools', 'not-dr-slon-toolkit'] as $page) {
            $html = $this->render_notice_for($page);
            self::assertStringNotContainsString('The SEO Framework', $html, $page === '' ? '(no page)' : $page);
        }

        foreach ([
            SettingsPage::PAGE_SLUG,
            SettingsPage::HELP_PAGE_SLUG,
            SettingsPage::AI_PAGE_SLUG,
            'DR-SLON-TOOLKIT',
        ] as $page) {
            $html = $this->render_notice_for($page);
            self::assertStringContainsString('The SEO Framework', $html, $page);
        }

        self::assertSame($options_before, $GLOBALS['dstk_test_options']);
    }

    private function register_modules(): void
    {
        $method = new ReflectionMethod(Plugin::class, 'register_modules');
        $method->invoke(new Plugin());
    }

    private function render_notice_for(string $page): string
    {
        if ($page === '') {
            unset($_GET['page']);
        } else {
            $_GET['page'] = $page;
        }

        ob_start();

        foreach ($GLOBALS['dstk_test_actions']['admin_notices'] as $hook) {
            $callback = $hook[0];
            $callback();
        }

        return (string) ob_get_clean();
    }
}
