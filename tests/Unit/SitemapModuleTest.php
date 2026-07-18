<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Tests\Unit;

use DrSlon\Toolkit\Modules\SitemapModule;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use WP_Post;

final class SitemapModuleTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['dstk_test_options'] = [
            'permalink_structure' => '/%postname%/',
            'dstk_settings'       => [
                'sitemap' => [
                    'enabled'     => true,
                    'post_types'  => ['post'],
                    'taxonomies'  => [],
                ],
            ],
        ];
        $GLOBALS['dstk_test_query_vars'] = [];
        $GLOBALS['dstk_test_filters'] = [];
        $GLOBALS['dstk_test_post_urls'] = [];
        $GLOBALS['dstk_test_home_url'] = 'https://example.test/';
        $GLOBALS['dstk_test_wp_query_handler'] = null;
        $_SERVER['REQUEST_URI'] = '/';
        unset($_SERVER['HTTP_IF_NONE_MATCH'], $_SERVER['HTTP_IF_MODIFIED_SINCE']);
    }

    public function test_pretty_routes_ignore_query_vars_on_frontend_paths_and_home(): void
    {
        $module = new SitemapModule();
        $GLOBALS['dstk_test_query_vars']['dstk_sitemap'] = 'index';

        $_SERVER['REQUEST_URI'] = '/article/?dstk_sitemap=index';
        self::assertNull($this->invoke($module, 'resolve_request_route'));

        $_SERVER['REQUEST_URI'] = '/?dstk_sitemap=index';
        self::assertNull($this->invoke($module, 'resolve_request_route'));

        $_SERVER['REQUEST_URI'] = '/sitemap.xml?dstk_sitemap=tax';
        self::assertSame(
            ['kind' => 'index', 'name' => '', 'page' => 1],
            $this->invoke($module, 'resolve_request_route')
        );
    }

    public function test_plain_permalink_query_route_only_matches_site_root(): void
    {
        $GLOBALS['dstk_test_options']['permalink_structure'] = '';
        $GLOBALS['dstk_test_home_url'] = 'https://example.test/site/';
        $GLOBALS['dstk_test_query_vars'] = [
            'dstk_sitemap'      => 'pt',
            'dstk_sitemap_name' => 'post',
            'dstk_sitemap_page' => '2',
        ];
        $module = new SitemapModule();

        $_SERVER['REQUEST_URI'] = '/site/?dstk_sitemap=pt';
        self::assertSame(
            ['kind' => 'pt', 'name' => 'post', 'page' => 2],
            $this->invoke($module, 'resolve_request_route')
        );

        $_SERVER['REQUEST_URI'] = '/site/article/?dstk_sitemap=pt';
        self::assertNull($this->invoke($module, 'resolve_request_route'));
    }

    public function test_noindex_entries_are_compacted_and_empty_pages_are_not_rendered(): void
    {
        $excluded = new WP_Post(['ID' => 1]);
        $included = new WP_Post(['ID' => 2]);
        $GLOBALS['dstk_test_post_urls'] = [
            1 => 'https://example.test/excluded/',
            2 => 'https://example.test/included/',
        ];
        $GLOBALS['dstk_test_wp_query_handler'] = static fn (array $args): array => [
            'posts' => ($args['paged'] ?? 1) === 1 ? [$excluded, $included] : [],
        ];
        add_filter(
            'dstk_sitemap_is_noindex',
            static fn (bool $is_noindex, WP_Post $post): bool => $is_noindex || $post->ID === 1,
            10,
            2
        );
        $module = new SitemapModule();

        self::assertSame(1, $this->invoke($module, 'get_post_type_page_count', ['post']));
        $xml = $this->invoke($module, 'render_post_type_sitemap', ['post', 1]);
        self::assertIsString($xml);
        self::assertStringContainsString('https://example.test/included/', $xml);
        self::assertStringNotContainsString('https://example.test/excluded/', $xml);

        $empty_module = new SitemapModule();
        remove_all_filters('dstk_sitemap_is_noindex');
        add_filter('dstk_sitemap_is_noindex', '__return_true', 10, 2);
        self::assertSame(0, $this->invoke($empty_module, 'get_post_type_page_count', ['post']));
        self::assertSame('', $this->invoke($empty_module, 'render_post_type_sitemap', ['post', 1]));
        self::assertStringNotContainsString('sitemap-pt-post', $this->invoke($empty_module, 'render_sitemap_index'));
    }

    public function test_noindex_scan_stops_at_fixed_source_page_budget(): void
    {
        $post = new WP_Post(['ID' => 1]);
        $queries = 0;
        $GLOBALS['dstk_test_post_urls'][1] = 'https://example.test/excluded/';
        $GLOBALS['dstk_test_wp_query_handler'] = static function () use (&$queries, $post): array {
            ++$queries;

            return ['posts' => array_fill(0, 1000, $post)];
        };
        add_filter('dstk_sitemap_is_noindex', '__return_true', 10, 2);
        $module = new SitemapModule();
        $limit = (new ReflectionClass(SitemapModule::class))->getConstant('MAX_FILTERED_SOURCE_PAGES');

        self::assertSame(0, $this->invoke($module, 'get_post_type_page_count', ['post']));
        self::assertSame($limit, $queries);
    }

    public function test_conditional_validator_matching_supports_etag_and_last_modified(): void
    {
        $module = new SitemapModule();
        $response = [
            'body'     => '<xml/>',
            'etag'     => '"validator"',
            'modified' => 1_700_000_000,
        ];

        $_SERVER['HTTP_IF_NONE_MATCH'] = 'W/"validator"';
        self::assertTrue($this->invoke($module, 'request_is_not_modified', [$response]));

        unset($_SERVER['HTTP_IF_NONE_MATCH']);
        $_SERVER['HTTP_IF_MODIFIED_SINCE'] = gmdate('D, d M Y H:i:s', $response['modified']) . ' GMT';
        self::assertTrue($this->invoke($module, 'request_is_not_modified', [$response]));
    }

    /**
     * @param array<int,mixed> $arguments
     */
    private function invoke(object $object, string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $arguments);
    }
}
