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
    }
}
