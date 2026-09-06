<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Tests\Unit;

use DrSlon\Toolkit\Modules\ExistingSlugRewriter;
use PHPUnit\Framework\TestCase;
use WP_Post;

final class ExistingSlugRewriterTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['dstk_test_home_url'] = 'https://example.test/';
        $GLOBALS['dstk_test_options'] = [];
        $GLOBALS['dstk_test_slug_posts'] = [];
        $GLOBALS['dstk_test_posts'] = [];
        $GLOBALS['dstk_test_post_urls'] = [];
        $GLOBALS['dstk_test_terms'] = [];
        $GLOBALS['dstk_test_term_urls'] = [];
        $GLOBALS['dstk_test_updated_posts'] = [];
        $GLOBALS['dstk_test_updated_terms'] = [];
    }

    public function test_plan_converts_cyrillic_and_skips_latin_and_cjk(): void
    {
        $rewriter = new ExistingSlugRewriter();
        $cyrillic = new WP_Post(
            [
                'ID'         => 11,
                'post_title' => 'Главная страница',
                'post_name'  => 'главная-страница',
                'post_type'  => 'page',
                'post_status'=> 'publish',
            ]
        );
        $latin = new WP_Post(['ID' => 12, 'post_title' => 'About', 'post_name' => 'about', 'post_type' => 'page']);
        $cjk = new WP_Post(['ID' => 13, 'post_title' => '東京', 'post_name' => '東京', 'post_type' => 'page']);

        self::assertSame('glavnaya-stranitsa', $rewriter->plan_post($cyrillic)['new_slug'] ?? null);
        self::assertNull($rewriter->plan_post($latin));
        self::assertNull($rewriter->plan_post($cjk));
    }

    public function test_term_plan_uses_russian_profile(): void
    {
        $term = (object) [
            'term_id'  => 4,
            'slug'     => 'новости',
            'name'     => 'Новости',
            'taxonomy' => 'category',
            'parent'   => 0,
        ];

        $plan = (new ExistingSlugRewriter())->plan_term($term);

        self::assertSame('novosti', $plan['new_slug'] ?? null);
        self::assertSame('term', $plan['kind'] ?? null);
    }

    public function test_apply_rewrites_slugs_and_stores_redirects(): void
    {
        $page = new WP_Post(
            [
                'ID'          => 21,
                'post_title'  => 'О компании',
                'post_name'   => 'о-компании',
                'post_type'   => 'page',
                'post_status' => 'publish',
            ]
        );
        $GLOBALS['dstk_test_slug_posts'] = [$page];
        $GLOBALS['dstk_test_posts'][21] = $page;
        $GLOBALS['dstk_test_post_urls'][21] = 'https://example.test/о-компании/';
        $GLOBALS['dstk_test_terms'] = [
            (object) [
                'term_id'  => 7,
                'slug'     => 'услуги',
                'name'     => 'Услуги',
                'taxonomy' => 'category',
                'parent'   => 0,
            ],
        ];
        $GLOBALS['dstk_test_term_urls'][7] = 'https://example.test/category/услуги/';

        $result = (new ExistingSlugRewriter())->apply(true);

        self::assertSame(2, $result['updated']);
        self::assertSame('o-kompanii', $page->post_name);
        self::assertSame('uslugi', $GLOBALS['dstk_test_terms'][0]->slug);

        $rewriter = new ExistingSlugRewriter();
        $page_match = $rewriter->match('/о-компании');
        $term_match = $rewriter->match('/category/услуги');

        self::assertNotNull($page_match);
        self::assertSame('https://example.test/o-kompanii/', $page_match['to']);
        self::assertNotNull($term_match);
        self::assertSame('https://example.test/category/uslugi/', $term_match['to']);
        self::assertGreaterThanOrEqual(2, $result['redirects_added']);
    }

    public function test_ascii_site_is_noop(): void
    {
        $GLOBALS['dstk_test_slug_posts'] = [
            new WP_Post(['ID' => 3, 'post_name' => 'hello-world', 'post_title' => 'Hello', 'post_status' => 'publish']),
        ];
        $GLOBALS['dstk_test_terms'] = [
            (object) ['term_id' => 1, 'slug' => 'news', 'name' => 'News', 'taxonomy' => 'category', 'parent' => 0],
        ];

        $result = (new ExistingSlugRewriter())->apply(true);

        self::assertSame(0, $result['updated']);
        self::assertSame([], (new ExistingSlugRewriter())->saved_redirects());
    }

    public function test_percent_encoded_cyrillic_slug_is_detected(): void
    {
        $encoded = rawurlencode('каталог');
        $post = new WP_Post(['ID' => 8, 'post_name' => $encoded, 'post_title' => 'Каталог', 'post_type' => 'page']);

        $plan = (new ExistingSlugRewriter())->plan_post($post);

        self::assertSame('katalog', $plan['new_slug'] ?? null);
    }

    public function test_preview_reports_batch_size(): void
    {
        $GLOBALS['dstk_test_slug_posts'] = [
            new WP_Post(['ID' => 1, 'post_name' => 'главная', 'post_title' => 'Главная', 'post_status' => 'publish']),
            new WP_Post(['ID' => 2, 'post_name' => 'контакты', 'post_title' => 'Контакты', 'post_status' => 'publish']),
        ];

        $preview = (new ExistingSlugRewriter())->preview(1);

        self::assertSame(2, $preview['batch']);
        self::assertCount(1, $preview['items']);
        self::assertFalse($preview['has_more']);
    }
}
