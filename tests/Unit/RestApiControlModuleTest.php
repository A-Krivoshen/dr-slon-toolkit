<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Tests\Unit;

use DrSlon\Toolkit\Core\Settings;
use DrSlon\Toolkit\Modules\RestApiControlModule;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

final class RestApiControlModuleTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['dstk_test_options'] = [];
        $GLOBALS['dstk_test_user_logged_in'] = false;
        $GLOBALS['dstk_test_user_capabilities'] = [];
        $GLOBALS['dstk_test_options'][Settings::OPTION_KEY] = [
            'rest_api' => [
                'mode'                 => 'whitelist',
                'whitelist_routes'     => '',
                'whitelist_namespaces' => '',
                'trusted_capability'   => 'edit_posts',
                'system_routes'        => '',
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['dstk_test_user_capabilities'], $GLOBALS['dstk_test_user_logged_in']);
    }

    public function test_whitelist_blocks_anonymous_content_routes(): void
    {
        $module = new RestApiControlModule();
        $result = $module->maybe_block_request(null, new WP_REST_Server(), new WP_REST_Request('GET', '/wp/v2/posts'));

        self::assertInstanceOf(WP_Error::class, $result);
        self::assertSame('dstk_rest_forbidden', $result->get_error_code());
        self::assertSame(401, $result->get_error_data()['status'] ?? null);
    }

    public function test_whitelist_allows_public_oembed_for_guests(): void
    {
        $module = new RestApiControlModule();
        $result = $module->maybe_block_request(null, new WP_REST_Server(), new WP_REST_Request('GET', '/oembed/1.0/embed'));

        self::assertNull($result);
    }

    public function test_whitelist_allows_editor_routes_for_logged_in_users(): void
    {
        $GLOBALS['dstk_test_user_logged_in'] = true;
        $module = new RestApiControlModule();
        $result = $module->maybe_block_request(null, new WP_REST_Server(), new WP_REST_Request('GET', '/wp/v2/posts/12'));

        self::assertNull($result);
    }

    public function test_user_whitelist_route_is_public(): void
    {
        $GLOBALS['dstk_test_options'][Settings::OPTION_KEY]['rest_api']['whitelist_routes'] = "/wp/v2/posts\n";
        $module = new RestApiControlModule();
        $result = $module->maybe_block_request(null, new WP_REST_Server(), new WP_REST_Request('GET', '/wp/v2/posts'));

        self::assertNull($result);
    }

    public function test_trusted_capability_bypasses_whitelist(): void
    {
        $GLOBALS['dstk_test_user_logged_in'] = true;
        $GLOBALS['dstk_test_user_capabilities'] = ['manage_options'];
        $GLOBALS['dstk_test_options'][Settings::OPTION_KEY]['rest_api']['trusted_capability'] = 'manage_options';
        $module = new RestApiControlModule();
        $result = $module->maybe_block_request(null, new WP_REST_Server(), new WP_REST_Request('GET', '/custom/v1/secret'));

        self::assertNull($result);
    }

    public function test_authenticated_only_blocks_anonymous_non_public_routes(): void
    {
        $GLOBALS['dstk_test_options'][Settings::OPTION_KEY]['rest_api']['mode'] = 'authenticated_only';
        $module = new RestApiControlModule();
        $result = $module->maybe_block_request(null, new WP_REST_Server(), new WP_REST_Request('GET', '/wp/v2/posts'));

        self::assertInstanceOf(WP_Error::class, $result);
    }
}
