<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Tests\Unit;

use DrSlon\Toolkit\Core\Activator;
use DrSlon\Toolkit\Core\Deactivator;
use DrSlon\Toolkit\Core\Settings;
use DrSlon\Toolkit\Modules\IndexNowModule;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressRewriteStubs.php';

final class MultisiteRewriteLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        \dstk_reset_rewrite_test_state();
        $GLOBALS['dstk_test_is_multisite'] = true;
        $GLOBALS['dstk_test_network_id'] = 7;
        $GLOBALS['dstk_test_sites_by_network'] = [
            7 => [11, 12],
            8 => [21],
        ];
    }

    public function test_network_activation_is_limited_to_the_current_network(): void
    {
        Activator::activate(true);

        self::assertSame(7, $GLOBALS['dstk_test_site_queries'][0]['network_id']);
        self::assertSame(1, $GLOBALS['dstk_test_blog_options'][11][Settings::REWRITE_FLUSH_PENDING_OPTION] ?? null);
        self::assertSame(1, $GLOBALS['dstk_test_blog_options'][12][Settings::REWRITE_FLUSH_PENDING_OPTION] ?? null);
        self::assertArrayNotHasKey(21, $GLOBALS['dstk_test_blog_options']);
        self::assertSame(1, $GLOBALS['dstk_test_current_blog_id']);
        self::assertSame([], $GLOBALS['dstk_test_blog_stack']);
    }

    public function test_initialize_site_seeds_the_new_blog_only(): void
    {
        $site = (object) ['blog_id' => 33];
        Activator::on_initialize_site($site);

        self::assertSame(DSTK_VERSION, $GLOBALS['dstk_test_blog_options'][33]['dstk_version'] ?? null);
        self::assertSame(1, $GLOBALS['dstk_test_blog_options'][33][Settings::REWRITE_FLUSH_PENDING_OPTION] ?? null);
        self::assertIsArray($GLOBALS['dstk_test_blog_options'][33][Settings::OPTION_KEY] ?? null);
        self::assertSame(1, $GLOBALS['dstk_test_current_blog_id']);
        self::assertSame([], $GLOBALS['dstk_test_blog_stack']);
    }

    public function test_network_deactivation_invalidates_local_rules_without_using_stale_rewrite_state(): void
    {
        foreach ([11, 12, 21] as $blog_id) {
            $GLOBALS['dstk_test_blog_options'][$blog_id] = [
                Settings::REWRITE_FLUSH_PENDING_OPTION => 1,
                'dstk_hide_login_rewrite_flush_pending' => 1,
                'rewrite_rules' => ['private-login/?$' => 'index.php?dstk_custom_login=private-login'],
                IndexNowModule::QUEUE_LOCK_OPTION => 'locked',
            ];
        }

        $rewrite = (object) [
            'permalink_structure' => '/original/%postname%/',
            'extra_rules_top'     => ['marker' => 'original-blog'],
        ];
        $GLOBALS['wp_rewrite'] = $rewrite;

        Deactivator::deactivate(true);

        self::assertSame(7, $GLOBALS['dstk_test_site_queries'][0]['network_id']);
        self::assertArrayNotHasKey('rewrite_rules', $GLOBALS['dstk_test_blog_options'][11]);
        self::assertArrayNotHasKey('rewrite_rules', $GLOBALS['dstk_test_blog_options'][12]);
        self::assertArrayHasKey('rewrite_rules', $GLOBALS['dstk_test_blog_options'][21]);
        self::assertSame($rewrite, $GLOBALS['wp_rewrite']);
        self::assertSame([], $GLOBALS['dstk_test_flushes']);
        self::assertSame(1, $GLOBALS['dstk_test_current_blog_id']);
        self::assertSame([], $GLOBALS['dstk_test_blog_stack']);
    }
}
