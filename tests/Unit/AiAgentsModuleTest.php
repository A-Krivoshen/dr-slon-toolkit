<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Tests\Unit;

use DrSlon\Toolkit\Core\CacheVersion;
use DrSlon\Toolkit\Core\Settings;
use DrSlon\Toolkit\Core\Utf8Response;
use DrSlon\Toolkit\Modules\AiAgents\DocumentBuilder;
use DrSlon\Toolkit\Modules\AiAgents\PulseFeedBuilder;
use DrSlon\Toolkit\Modules\AiAgentsModule;
use PHPUnit\Framework\TestCase;
use WP_Post;

final class AiAgentsModuleTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['dstk_test_options'] = [
            'blog_public'         => 1,
            'permalink_structure' => '/%postname%/',
            'dstk_settings'       => [
                'modules'   => ['ai_agents' => true],
                'ai_agents' => Settings::defaults()['ai_agents'],
            ],
        ];
        $GLOBALS['dstk_test_query_vars'] = [];
        $GLOBALS['dstk_test_filters'] = [];
        $GLOBALS['dstk_test_posts'] = [];
        $GLOBALS['dstk_test_post_urls'] = [];
        $GLOBALS['dstk_test_home_url'] = 'https://example.test/';
        $GLOBALS['dstk_test_wp_query_handler'] = null;
        $GLOBALS['dstk_test_bloginfo'] = [
            'name'        => 'Клиника Кривошеин',
            'description' => 'Тестовый сайт',
            'language'    => 'ru-RU',
        ];
        $GLOBALS['dstk_test_rewrite_rules'] = [];
        $GLOBALS['dstk_test_transients'] = [];
        $_SERVER['REQUEST_URI'] = '/';
    }

    public function test_pretty_paths_resolve_kinds(): void
    {
        $module = new AiAgentsModule();

        $_SERVER['REQUEST_URI'] = '/llms.txt';
        self::assertSame('llms', $module->resolve_request_kind());

        $_SERVER['REQUEST_URI'] = '/llms-full.txt';
        self::assertSame('llms_full', $module->resolve_request_kind());

        $_SERVER['REQUEST_URI'] = '/feed/ai-pulse.md';
        self::assertSame('pulse', $module->resolve_request_kind());

        $_SERVER['REQUEST_URI'] = '/about/';
        self::assertNull($module->resolve_request_kind());
    }

    public function test_disabled_kind_is_not_enabled(): void
    {
        $GLOBALS['dstk_test_options']['dstk_settings']['ai_agents']['pulse_md'] = false;
        $module = new AiAgentsModule();

        self::assertTrue($module->kind_enabled('llms'));
        self::assertFalse($module->kind_enabled('pulse'));
    }

    public function test_documents_keep_cyrillic_and_skip_bom(): void
    {
        $post = new WP_Post(
            [
                'ID'            => 12,
                'post_type'     => 'post',
                'post_title'    => 'Привет мир',
                'post_content'  => '<p>Текст &laquo;документа&raquo;</p>',
                'post_date_gmt' => '2026-08-05 10:00:00',
            ]
        );
        $GLOBALS['dstk_test_post_urls'][12] = 'https://example.test/privet/';
        $GLOBALS['dstk_test_wp_query_handler'] = static fn (): array => ['posts' => [$post]];

        $config = Settings::defaults()['ai_agents'];
        $config['site_blurb'] = 'Клиника в Москве';
        $body = (new DocumentBuilder($config))->build('llms_full');

        self::assertTrue(mb_check_encoding($body, 'UTF-8'));
        self::assertFalse(str_starts_with($body, "\xEF\xBB\xBF"));
        self::assertStringContainsString('Клиника Кривошеин', $body);
        self::assertStringContainsString('Привет мир', $body);
        self::assertStringContainsString('документа', $body);
        self::assertSame('text/plain', (new AiAgentsModule())->content_type('llms'));
        self::assertSame('text/markdown', (new AiAgentsModule())->content_type('agents'));
    }

    public function test_password_and_non_publish_are_excluded(): void
    {
        $secret = new WP_Post(['ID' => 1, 'post_type' => 'post', 'post_title' => 'Секрет', 'post_password' => 'x']);
        $draft = new WP_Post(['ID' => 2, 'post_type' => 'post', 'post_title' => 'Черновик', 'post_status' => 'draft']);
        $live = new WP_Post(['ID' => 3, 'post_type' => 'post', 'post_title' => 'Открыто']);
        $GLOBALS['dstk_test_post_urls'] = [
            1 => 'https://example.test/secret/',
            2 => 'https://example.test/draft/',
            3 => 'https://example.test/open/',
        ];
        $GLOBALS['dstk_test_wp_query_handler'] = static fn (): array => ['posts' => [$secret, $draft, $live]];

        $body = (new DocumentBuilder(Settings::defaults()['ai_agents']))->build('llms');

        self::assertStringContainsString('Открыто', $body);
        self::assertStringNotContainsString('Секрет', $body);
        self::assertStringNotContainsString('Черновик', $body);
    }

    public function test_pulse_lists_recent_posts(): void
    {
        $post = new WP_Post(
            [
                'ID'            => 8,
                'post_title'    => 'Новость',
                'post_content'  => 'Короткий текст',
                'post_date_gmt' => '2026-08-01 09:00:00',
            ]
        );
        $GLOBALS['dstk_test_post_urls'][8] = 'https://example.test/news/';
        $GLOBALS['dstk_test_wp_query_handler'] = static fn (): array => ['posts' => [$post]];

        $body = (new PulseFeedBuilder(Settings::defaults()['ai_agents']))->build();

        self::assertStringContainsString('# AI Pulse', $body);
        self::assertStringContainsString('Новость', $body);
        self::assertStringContainsString('https://example.test/news/', $body);
    }

    public function test_utf8_response_strips_bom_and_crlf(): void
    {
        $normalized = Utf8Response::normalize("\xEF\xBB\xBFПривет\r\nмир");

        self::assertSame("Привет\nмир", $normalized);
        self::assertTrue(mb_check_encoding($normalized, 'UTF-8'));
    }

    public function test_cache_invalidation_bumps_version(): void
    {
        $module = new AiAgentsModule();
        $before = $module->cache_key('ai');
        $module->commit_cache_invalidation();
        $after = $module->cache_key('ai');

        self::assertNotSame($before, $after);
        self::assertNotSame('1', CacheVersion::get(CacheVersion::AI));
    }

    public function test_rewrite_rules_skip_disabled_pulse(): void
    {
        $GLOBALS['dstk_test_options']['dstk_settings']['ai_agents']['pulse_md'] = false;
        $module = new AiAgentsModule();
        $module->register_rewrite_rules();
        $regexes = array_column($GLOBALS['dstk_test_rewrite_rules'], 'regex');

        self::assertContains('^llms\.txt$', $regexes);
        self::assertNotContains('^feed/ai-pulse\.md$', $regexes);
    }
}
