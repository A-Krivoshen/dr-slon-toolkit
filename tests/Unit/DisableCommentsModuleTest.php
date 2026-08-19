<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Tests\Unit;

use DrSlon\Toolkit\Modules\DisableCommentsModule;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;

final class DisableCommentsModuleTest extends TestCase
{
    public function test_rest_insert_is_blocked_with_403(): void
    {
        $result = (new DisableCommentsModule())->block_rest_comment(['content' => 'hi'], new WP_REST_Request());

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('dstk_comments_disabled', $result->get_error_code());
        self::assertSame(403, $result->get_error_data()['status'] ?? 0);
    }

    public function test_comment_rest_routes_are_removed(): void
    {
        $filtered = (new DisableCommentsModule())->remove_comment_rest_endpoints(
            [
                '/wp/v2/posts'              => ['GET'],
                '/wp/v2/comments'           => ['GET'],
                '/wp/v2/comments/(?P<id>\d+)' => ['GET'],
            ]
        );

        self::assertArrayHasKey('/wp/v2/posts', $filtered);
        self::assertArrayNotHasKey('/wp/v2/comments', $filtered);
        self::assertArrayNotHasKey('/wp/v2/comments/(?P<id>\d+)', $filtered);
    }

    public function test_empty_comments_query_returns_empty_list(): void
    {
        self::assertSame([], (new DisableCommentsModule())->empty_comments_query(['ignored']));
    }
}
