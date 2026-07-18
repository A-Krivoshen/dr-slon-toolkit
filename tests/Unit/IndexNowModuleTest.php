<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Tests\Unit;

use DrSlon\Toolkit\Modules\IndexNowModule;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use WP_Post;

final class IndexNowModuleTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['dstk_test_options'] = [
            'blog_public'  => 1,
            'dstk_settings' => [
                'indexnow' => [
                    'key'        => '12345678',
                    'endpoint'   => 'https://api.indexnow.org/indexnow',
                    'post_types' => ['post'],
                ],
            ],
        ];
        $GLOBALS['dstk_test_filters'] = [];
        $GLOBALS['dstk_test_posts'] = [];
        $GLOBALS['dstk_test_post_urls'] = [];
        $GLOBALS['dstk_test_url_post_ids'] = [];
        $GLOBALS['dstk_test_home_url'] = 'https://example.test/';
        $GLOBALS['dstk_test_remote_posts'] = [];
    }

    public function test_equivalent_percent_encoding_has_one_canonical_queue_url(): void
    {
        $module = new IndexNowModule();

        $encoded = $this->invoke(
            $module,
            'normalize_site_url',
            ['https://EXAMPLE.test/%7euser/%d0%bf?q=%7e']
        );
        $canonical = $this->invoke(
            $module,
            'normalize_site_url',
            ['https://example.test/~user/%D0%BF?q=~']
        );

        self::assertSame('https://example.test/~user/%D0%BF?q=~', $encoded);
        self::assertSame($encoded, $canonical);
    }

    public function test_due_queue_entry_is_dropped_when_post_becomes_noindex(): void
    {
        $post = new WP_Post(['ID' => 42]);
        $url = 'https://example.test/current/';
        $GLOBALS['dstk_test_posts'][42] = $post;
        $GLOBALS['dstk_test_post_urls'][42] = $url;
        $GLOBALS['dstk_test_options']['dstk_indexnow_queue'] = [
            'queued-entry' => [
                'url'          => $url,
                'reason'       => 'update',
                'post_id'      => 42,
                'attempts'     => 1,
                'next_attempt' => 0,
                'created_at'   => 1,
                'last_error'   => 'retry',
            ],
        ];
        add_filter('dstk_sitemap_is_noindex', '__return_true', 10, 2);

        (new IndexNowModule())->process_queue();

        self::assertSame([], $GLOBALS['dstk_test_options']['dstk_indexnow_queue']);
        self::assertSame([], $GLOBALS['dstk_test_remote_posts']);
        self::assertSame(1, $GLOBALS['dstk_test_options']['dstk_indexnow_queue_status']['dropped']);
    }

    public function test_send_time_filter_can_reject_an_otherwise_current_post(): void
    {
        $post = new WP_Post(['ID' => 7]);
        $url = 'https://example.test/current/';
        $GLOBALS['dstk_test_posts'][7] = $post;
        $GLOBALS['dstk_test_post_urls'][7] = $url;
        add_filter('dstk_indexnow_should_submit', '__return_false', 10, 4);
        $module = new IndexNowModule();

        self::assertFalse($this->invoke($module, 'is_queued_url_eligible', [$url, 'update', 7]));

        $post->post_status = 'draft';
        remove_all_filters('dstk_indexnow_should_submit');
        self::assertFalse($this->invoke($module, 'is_queued_url_eligible', [$url, 'update', 7]));
    }

    public function test_terminal_notification_is_cancelled_if_the_same_url_is_live_again(): void
    {
        $post = new WP_Post(['ID' => 9]);
        $queued_url = 'https://example.test/revived/';
        $GLOBALS['dstk_test_posts'][9] = $post;
        $GLOBALS['dstk_test_post_urls'][9] = $queued_url;
        $module = new IndexNowModule();

        self::assertFalse($this->invoke($module, 'is_queued_url_eligible', [$queued_url, 'delete', 9]));

        $GLOBALS['dstk_test_post_urls'][9] = 'https://example.test/new-url/';
        self::assertTrue($this->invoke($module, 'is_queued_url_eligible', [$queued_url, 'delete', 9]));
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
