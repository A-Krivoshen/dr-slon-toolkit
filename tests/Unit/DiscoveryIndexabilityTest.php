<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Tests\Unit;

use DrSlon\Toolkit\Core\DiscoveryIndexability;
use DrSlon\Toolkit\Core\RewriteManager;
use DrSlon\Toolkit\Core\Settings;
use DrSlon\Toolkit\Core\UrlNormalizer;
use DrSlon\Toolkit\Integrations\SeoFrameworkDetector;
use PHPUnit\Framework\TestCase;
use WP_Post;

final class DiscoveryIndexabilityTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['dstk_test_options'] = ['blog_public' => 1];
        $GLOBALS['dstk_test_filters'] = [];
        $GLOBALS['dstk_test_did_actions'] = [];
        $GLOBALS['dstk_test_home_url'] = 'https://example.test/';
        $GLOBALS['dstk_test_post_urls'] = [5 => 'https://example.test/post/'];
    }

    public function test_password_and_draft_are_not_indexable(): void
    {
        $password = new WP_Post(['ID' => 5, 'post_password' => 'x']);
        $draft = new WP_Post(['ID' => 5, 'post_status' => 'draft']);

        self::assertFalse(DiscoveryIndexability::is_post_indexable($password));
        self::assertFalse(DiscoveryIndexability::is_post_indexable($draft));
    }

    public function test_sitemap_noindex_filter_is_honored(): void
    {
        $post = new WP_Post(['ID' => 5]);
        add_filter('dstk_sitemap_is_noindex', '__return_true', 10, 2);

        self::assertFalse(DiscoveryIndexability::is_post_indexable($post));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_tsf_present_but_not_ready_is_fail_open(): void
    {
        if (! defined('THE_SEO_FRAMEWORK_PRESENT')) {
            define('THE_SEO_FRAMEWORK_PRESENT', true);
        }

        $detector = new SeoFrameworkDetector();

        self::assertTrue($detector->is_active());
        self::assertSame('unavailable', $detector->indexability_source());
        self::assertTrue($detector->is_post_indexable(5, 'https://example.test/post/'));
        self::assertTrue(DiscoveryIndexability::is_post_indexable(new WP_Post(['ID' => 5])));
    }

    public function test_external_canonical_is_rejected(): void
    {
        self::assertTrue(UrlNormalizer::is_external_canonical('https://other.test/page/'));
        self::assertFalse(UrlNormalizer::is_external_canonical('https://example.test/page/'));
        self::assertFalse(UrlNormalizer::is_external_canonical('https://EXAMPLE.test:443/page/'));
    }

    public function test_rewrite_fingerprint_includes_ai_endpoints(): void
    {
        $base = Settings::defaults();
        $changed = $base;
        $changed['modules']['ai_agents'] = true;

        self::assertNotSame(
            RewriteManager::fingerprint($base),
            RewriteManager::fingerprint($changed)
        );

        RewriteManager::schedule_if_changed($base, $changed);
        self::assertSame(1, $GLOBALS['dstk_test_options'][Settings::REWRITE_FLUSH_PENDING_OPTION]);
    }
}
