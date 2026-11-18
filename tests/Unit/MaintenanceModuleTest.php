<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Tests\Unit;

use DrSlon\Toolkit\Admin\SettingsPage;
use DrSlon\Toolkit\Core\Settings;
use DrSlon\Toolkit\Modules\IndexNowModule;
use DrSlon\Toolkit\Modules\MaintenanceModule;
use PHPUnit\Framework\TestCase;

final class MaintenanceModuleTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['dstk_test_options'] = [];
        $GLOBALS['dstk_test_actions'] = [];
        $GLOBALS['dstk_test_filters'] = [];
        $GLOBALS['dstk_test_query_vars'] = [];
        $GLOBALS['dstk_test_user_capabilities'] = [];
        $GLOBALS['dstk_test_is_admin'] = false;
        $GLOBALS['dstk_test_doing_ajax'] = false;
        $GLOBALS['dstk_test_doing_cron'] = false;
        $GLOBALS['dstk_test_sent_headers'] = [];
        $GLOBALS['dstk_test_status_header'] = null;
        $GLOBALS['dstk_test_nocache_headers'] = 0;
        $_GET = [];
        $_SERVER['SCRIPT_NAME'] = '/index.php';
    }

    public function test_module_is_off_until_enabled(): void
    {
        self::assertFalse(Settings::module_enabled('maintenance'));
        self::assertFalse(Settings::defaults()['modules']['maintenance']);
    }

    public function test_guest_front_is_blocked_and_admin_front_is_not(): void
    {
        self::assertTrue(MaintenanceModule::should_block($this->context()));
        self::assertFalse(MaintenanceModule::should_block($this->context(['can_manage' => true])));
    }

    public function test_login_cron_cli_and_wp_admin_stay_open(): void
    {
        self::assertFalse(MaintenanceModule::should_block($this->context(['login' => true])));
        self::assertFalse(MaintenanceModule::should_block($this->context(['cron' => true])));
        self::assertFalse(MaintenanceModule::should_block($this->context(['cli' => true])));
        self::assertFalse(MaintenanceModule::should_block($this->context(['admin' => true])));
    }

    public function test_anonymous_rest_and_ajax_are_blocked_but_editors_keep_them(): void
    {
        self::assertTrue(MaintenanceModule::should_block($this->context(['rest' => true])));
        self::assertTrue(MaintenanceModule::should_block($this->context(['ajax' => true])));
        self::assertFalse(MaintenanceModule::should_block($this->context(['rest' => true, 'can_edit' => true])));
        self::assertFalse(MaintenanceModule::should_block($this->context(['ajax' => true, 'can_manage' => true])));
        self::assertTrue(MaintenanceModule::should_block($this->context(['xmlrpc' => true, 'can_manage' => true])));
    }

    public function test_preview_shows_the_wall_to_an_administrator_only(): void
    {
        self::assertTrue(MaintenanceModule::should_block($this->context([
            'can_manage' => true,
            'preview'    => true,
        ])));
        self::assertFalse(MaintenanceModule::should_block($this->context([
            'preview' => true,
            'admin'   => true,
            'can_manage' => true,
        ])));
        self::assertTrue(MaintenanceModule::should_block($this->context(['preview' => true])));
    }

    public function test_disabled_module_never_blocks(): void
    {
        self::assertFalse(MaintenanceModule::should_block($this->context(['enabled' => false])));
    }

    public function test_page_marks_itself_noindex_and_credits_the_author(): void
    {
        $html = (new MaintenanceModule())->page_markup();

        self::assertStringContainsString('name="robots" content="noindex, nofollow, noarchive"', $html);
        self::assertStringContainsString('Сайт на реконструкции', $html);
        self::assertStringContainsString('Dr.Slon Toolkit ' . DSTK_VERSION, $html);
        self::assertStringContainsString('href="https://krivoshein.site"', $html);
        self::assertStringContainsString('target="_blank"', $html);
        self::assertStringContainsString('rel="noopener noreferrer"', $html);
        self::assertStringContainsString('Плагин разработан ИП Кривошеин', $html);
        self::assertStringContainsString('<h1>', $html);
        self::assertStringNotContainsString('<script', strtolower($html));
    }

    public function test_custom_copy_is_escaped_and_hostile_markup_is_stripped_on_save(): void
    {
        $GLOBALS['dstk_test_options']['dstk_settings'] = [
            'modules' => ['maintenance' => true],
            'maintenance' => [
                'heading' => 'Цех «Север»',
                'message' => 'Вернёмся утром.',
            ],
        ];
        $GLOBALS['dstk_test_bloginfo'] = [
            'name' => 'A & B',
            'language' => 'ru-RU',
        ];

        $html = (new MaintenanceModule())->page_markup();

        self::assertStringContainsString('A &amp; B', $html);
        self::assertStringContainsString('Цех «Север»', $html);
        self::assertStringNotContainsString('<script', $html);

        $saved = Settings::merge_with_defaults(
            [
                'modules' => ['maintenance' => '1'],
                'maintenance' => [
                    '_submitted' => '1',
                    'heading' => '<script>alert(1)</script>Заголовок',
                    'message' => "Строка\n<img src=x onerror=alert(1)>",
                ],
            ],
            true
        );

        self::assertTrue($saved['modules']['maintenance']);
        self::assertSame('alert(1)Заголовок', $saved['maintenance']['heading']);
        self::assertStringNotContainsString('<img', $saved['maintenance']['message']);
        self::assertStringContainsString('Строка', $saved['maintenance']['message']);
    }

    public function test_empty_copy_falls_back_and_omitted_copy_keeps_the_saved_text(): void
    {
        $GLOBALS['dstk_test_options']['dstk_settings'] = [
            'maintenance' => [
                'heading' => 'Закрыто до понедельника',
                'message' => 'Пишем новый каталог.',
            ],
        ];

        $kept = Settings::merge_with_defaults(['modules' => ['cleanup' => '1']], true);
        self::assertSame('Закрыто до понедельника', $kept['maintenance']['heading']);
        self::assertSame('Пишем новый каталог.', $kept['maintenance']['message']);

        $empty = Settings::merge_with_defaults(
            [
                'maintenance' => [
                    '_submitted' => '1',
                    'heading' => '   ',
                    'message' => '',
                ],
            ],
            true
        );
        self::assertSame(MaintenanceModule::DEFAULT_HEADING, $empty['maintenance']['heading']);
        self::assertSame(MaintenanceModule::DEFAULT_MESSAGE, $empty['maintenance']['message']);
    }

    public function test_heading_is_limited_to_120_characters(): void
    {
        $saved = Settings::merge_with_defaults(
            [
                'maintenance' => [
                    'heading' => str_repeat('Я', 150),
                    'message' => str_repeat('М', 450),
                ],
            ],
            true
        );

        self::assertSame(120, mb_strlen($saved['maintenance']['heading']));
        self::assertSame(400, mb_strlen($saved['maintenance']['message']));
    }

    public function test_block_headers_use_503_and_robots(): void
    {
        (new MaintenanceModule())->send_block_headers(true);

        self::assertSame([503, ''], $GLOBALS['dstk_test_status_header']);
        self::assertGreaterThan(0, $GLOBALS['dstk_test_nocache_headers']);
        $headers = implode("\n", $GLOBALS['dstk_test_sent_headers']);
        self::assertStringContainsString('Retry-After: 86400', $headers);
        self::assertStringContainsString('X-Robots-Tag: noindex, nofollow, noarchive', $headers);
        self::assertStringContainsString('Content-Type: text/html; charset=UTF-8', $headers);
    }

    public function test_rest_error_for_a_guest_is_503(): void
    {
        $GLOBALS['dstk_test_options']['dstk_settings'] = [
            'modules' => ['maintenance' => true],
        ];

        $result = (new MaintenanceModule())->maybe_block_rest(null);

        self::assertInstanceOf(\WP_Error::class, $result);
        self::assertSame('dstk_maintenance', $result->get_error_code());
        self::assertSame(503, $result->get_error_data()['status']);
    }

    public function test_rest_error_is_not_replaced_when_another_module_already_failed(): void
    {
        $existing = new \WP_Error('already', 'already', ['status' => 401]);
        $GLOBALS['dstk_test_options']['dstk_settings'] = [
            'modules' => ['maintenance' => true],
        ];

        $result = (new MaintenanceModule())->maybe_block_rest($existing);

        self::assertSame($existing, $result);
    }

    public function test_login_script_and_custom_slug_are_recognized(): void
    {
        $module = new MaintenanceModule();

        $_SERVER['SCRIPT_NAME'] = '/wp-login.php';
        self::assertTrue($module->is_login_request());

        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $GLOBALS['dstk_test_query_vars']['dstk_custom_login'] = 'my-login';
        self::assertTrue($module->is_login_request());
    }

    public function test_preview_requires_a_valid_nonce_and_manage_options(): void
    {
        $module = new MaintenanceModule();
        $_GET['dstk_maintenance_preview'] = '1';
        $_GET['_wpnonce'] = 'test-nonce-dstk_maintenance_preview';

        self::assertFalse($module->is_preview_request());

        $GLOBALS['dstk_test_user_capabilities'] = ['manage_options'];
        self::assertTrue($module->is_preview_request());

        $_GET['_wpnonce'] = 'nope';
        self::assertFalse($module->is_preview_request());
    }

    public function test_indexnow_manual_submit_is_refused_while_the_site_is_closed(): void
    {
        $GLOBALS['dstk_test_options']['dstk_settings'] = [
            'modules' => ['maintenance' => true],
        ];

        $result = (new IndexNowModule())->submit_manual_url('https://example.test/post');

        self::assertFalse($result['success']);
        self::assertStringContainsString('реконструкция', $result['message']);
    }

    public function test_register_hooks_the_gate_and_the_admin_notice(): void
    {
        (new MaintenanceModule())->register();

        $hooks = array_keys($GLOBALS['dstk_test_actions']);
        self::assertContains('template_redirect', $hooks);
        self::assertContains('admin_notices', $hooks);
        self::assertArrayHasKey('rest_pre_dispatch', $GLOBALS['dstk_test_filters']);
    }

    public function test_settings_screen_renders_copy_fields_and_preview_link(): void
    {
        $GLOBALS['dstk_test_options']['dstk_settings'] = [
            'modules' => ['maintenance' => true],
            'maintenance' => [
                'heading' => 'На реконструкции',
                'message' => 'Скоро откроем.',
            ],
        ];

        ob_start();
        (new SettingsPage())->render_maintenance_fields();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('name="dstk_settings[maintenance][heading]"', $output);
        self::assertStringContainsString('На реконструкции', $output);
        self::assertStringContainsString('dstk_maintenance_preview=1', $output);
        self::assertStringContainsString('target="_blank"', $output);
    }

    public function test_admin_notice_is_visible_to_the_administrator(): void
    {
        $GLOBALS['dstk_test_user_capabilities'] = ['manage_options'];

        ob_start();
        (new MaintenanceModule())->render_admin_notice();
        $output = (string) ob_get_clean();

        self::assertStringContainsString('notice-warning', $output);
        self::assertStringContainsString('503', $output);
        self::assertStringContainsString('admin.php?page=dr-slon-toolkit', $output);
    }

    /**
     * @param array<string, bool> $overrides
     * @return array<string, bool>
     */
    private function context(array $overrides = []): array
    {
        return array_merge(
            [
                'enabled'    => true,
                'cron'       => false,
                'cli'        => false,
                'login'      => false,
                'admin'      => false,
                'ajax'       => false,
                'rest'       => false,
                'xmlrpc'     => false,
                'can_manage' => false,
                'can_edit'   => false,
                'preview'    => false,
            ],
            $overrides
        );
    }
}
