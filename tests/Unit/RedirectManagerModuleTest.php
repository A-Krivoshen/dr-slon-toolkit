<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Tests\Unit;

use DrSlon\Toolkit\Core\Settings;
use DrSlon\Toolkit\Modules\RedirectManagerModule;
use PHPUnit\Framework\TestCase;

final class RedirectManagerModuleTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['dstk_test_home_url'] = 'https://example.test/';
        $GLOBALS['dstk_test_options'] = [
            'dstk_settings' => [
                'redirects' => [
                    'rules' => [
                        ['from' => '/staryj', 'to' => 'https://example.test/novyj', 'status' => 301],
                    ],
                ],
            ],
        ];
    }

    public function test_exact_path_matches_and_strips_slash(): void
    {
        $module = new RedirectManagerModule();
        $match = $module->match('/staryj/');

        self::assertNotNull($match);
        self::assertSame('/staryj', $match['from']);
        self::assertSame(301, $match['status']);
        self::assertSame('https://example.test/novyj', $match['to']);
    }

    public function test_unknown_path_does_not_match(): void
    {
        self::assertNull((new RedirectManagerModule())->match('/other'));
    }

    public function test_admin_and_login_paths_are_ignored(): void
    {
        $GLOBALS['dstk_test_options']['dstk_settings']['redirects']['rules'] = [
            ['from' => '/wp-admin', 'to' => '/', 'status' => 301],
            ['from' => '/wp-login.php', 'to' => '/', 'status' => 301],
        ];
        $module = new RedirectManagerModule();

        self::assertNull($module->match('/wp-admin'));
        self::assertNull(RedirectManagerModule::sanitize_rule('/wp-json/wp/v2', '/', 301));
    }

    public function test_self_redirect_is_rejected(): void
    {
        self::assertNull(RedirectManagerModule::sanitize_rule('/same/', '/same', 301));
    }

    public function test_parse_text_supports_arrows_and_302(): void
    {
        $rules = RedirectManagerModule::parse_rules_text(
            "# comment\n/old -> /new | 302\n/a → /b\nhttps://example.test/from-url | /to-url | 301\n"
        );

        self::assertCount(3, $rules);
        self::assertSame(302, $rules[0]['status']);
        self::assertSame('/old', $rules[0]['from']);
        self::assertSame('/a', $rules[1]['from']);
        self::assertSame('/from-url', $rules[2]['from']);
    }

    public function test_parse_text_keeps_clearfy_wildcard_sources(): void
    {
        $rules = RedirectManagerModule::parse_rules_text("/?client=* -> /\n/client* -> /\n");

        self::assertCount(2, $rules);
        self::assertSame('/?client=*', $rules[0]['from']);
        self::assertSame('/client*', $rules[1]['from']);
        self::assertSame('https://example.test/', $rules[0]['to']);
    }

    public function test_exact_match_is_case_insensitive_and_ignores_query(): void
    {
        $GLOBALS['dstk_test_options']['dstk_settings']['redirects']['rules'] = [
            ['from' => '/IntellectMoney', 'to' => 'https://pay.example/go', 'status' => 301],
        ];
        $module = new RedirectManagerModule();

        $match = $module->match('/intellectmoney', '/IntellectMoney?utm=1');

        self::assertNotNull($match);
        self::assertSame('https://pay.example/go', $match['to']);
    }

    public function test_clearfy_wildcard_and_query_rules(): void
    {
        $GLOBALS['dstk_test_options']['dstk_settings']['redirects']['rules'] = [
            ['from' => '/?client=*', 'to' => 'https://example.test/', 'status' => 301],
            ['from' => '/client*', 'to' => 'https://example.test/', 'status' => 301],
            ['from' => '/alfa', 'to' => 'https://alfa.me/B3kPzV', 'status' => 301],
        ];
        $module = new RedirectManagerModule();

        $query = $module->match('/', '/?client=42');
        $prefix = $module->match('/clientfoo', '/clientfoo');
        $nested = $module->match('/client/sila-buhgalterii', '/client/sila-buhgalterii/');
        $partner = $module->match('/alfa', '/alfa?utm=newsletter');

        self::assertNotNull($query);
        self::assertSame('https://example.test/', $query['to']);
        self::assertNotNull($prefix);
        self::assertSame('https://example.test/', $prefix['to']);
        self::assertNotNull($nested);
        self::assertSame('https://example.test/', $nested['to']);
        self::assertNotNull($partner);
        self::assertSame('https://alfa.me/B3kPzV', $partner['to']);
        self::assertNull($module->match('/clickfraud', '/clickfraud'));
    }

    public function test_external_target_is_kept(): void
    {
        $rule = RedirectManagerModule::sanitize_rule('/go', 'https://other.test/page', 301);

        self::assertNotNull($rule);
        self::assertSame('https://other.test/page', $rule['to']);
    }

    public function test_settings_parse_rules_text_on_save(): void
    {
        $settings = Settings::merge_with_defaults(
            [
                'redirects' => [
                    '_submitted' => '1',
                    'rules_text' => "/alpha -> /beta\n/loop -> /loop\n",
                ],
            ],
            true
        );

        self::assertCount(1, $settings['redirects']['rules']);
        self::assertSame('/alpha', $settings['redirects']['rules'][0]['from']);
        self::assertTrue($settings['redirects']['rules'][0]['enabled']);
    }

    public function test_editor_rows_keep_order_enabled_flag_and_import(): void
    {
        $settings = Settings::merge_with_defaults(
            [
                'redirects' => [
                    '_submitted' => '1',
                    'editor'     => '1',
                    'rows'       => [
                        ['from' => '/b', 'to' => '/two', 'status' => '302', 'enabled' => '0'],
                        ['from' => '/a', 'to' => '/one', 'status' => '301', 'enabled' => '1'],
                        ['from' => '/a', 'to' => '/other', 'status' => '301', 'enabled' => '1'],
                        ['from' => '', 'to' => ''],
                        ['from' => '/loop', 'to' => '/loop', 'status' => '301', 'enabled' => '1'],
                    ],
                    'import_text' => "/c -> /three\n/a -> /nope\n/?client=* -> /\n",
                ],
            ],
            true
        );

        $rules = $settings['redirects']['rules'];

        self::assertSame(['/b', '/a', '/c', '/?client=*'], array_column($rules, 'from'));
        self::assertSame(302, $rules[0]['status']);
        self::assertFalse($rules[0]['enabled']);
        self::assertTrue($rules[1]['enabled']);
        self::assertSame('https://example.test/', $rules[3]['to']);
        self::assertSame(3, RedirectManagerModule::rejected_count());
    }

    public function test_empty_editor_clears_saved_rules(): void
    {
        $settings = Settings::merge_with_defaults(
            [
                'redirects' => [
                    '_submitted' => '1',
                    'editor'     => '1',
                    'rows'       => [
                        ['from' => '', 'to' => ''],
                    ],
                ],
            ],
            true
        );

        self::assertSame([], $settings['redirects']['rules']);
    }

    public function test_stored_disabled_rule_survives_merge_and_does_not_match(): void
    {
        $settings = Settings::merge_with_defaults(
            [
                'redirects' => [
                    'rules' => [
                        ['from' => '/off', 'to' => '/no', 'status' => 301, 'enabled' => false],
                        ['from' => '/on', 'to' => '/yes', 'status' => 301],
                    ],
                ],
            ]
        );

        self::assertFalse($settings['redirects']['rules'][0]['enabled']);
        self::assertTrue($settings['redirects']['rules'][1]['enabled']);

        $GLOBALS['dstk_test_options']['dstk_settings'] = $settings;
        $module = new RedirectManagerModule();

        self::assertNull($module->match('/off'));
        self::assertNotNull($module->match('/on'));
        self::assertSame('disabled', $module->diagnose('/off')['code']);
        self::assertSame('matched', $module->diagnose('https://example.test/on')['code']);
        self::assertSame('blocked', $module->diagnose('/wp-admin')['code']);
        self::assertSame('none', $module->diagnose('/missing')['code']);
    }
}
