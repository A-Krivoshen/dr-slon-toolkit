<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Tests\Unit;

use DrSlon\Toolkit\Core\Settings;
use DrSlon\Toolkit\Modules\HideLoginModule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressRewriteStubs.php';

final class HideLoginCollisionPost extends \WP_Post
{
    public string $post_name = '';
}

final class HideLoginModuleTest extends TestCase
{
    protected function setUp(): void
    {
        \dstk_reset_rewrite_test_state();
        $GLOBALS['dstk_test_options'][Settings::OPTION_KEY] = [
            'hide_login' => ['slug' => 'private-login'],
        ];
        $GLOBALS['dstk_test_options']['permalink_structure'] = '/%postname%/';
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function generatedLoginEndpoints(): iterable
    {
        yield 'wp_login_form action' => [
            'https://example.test/wp-login.php',
            'https://example.test/private-login/',
        ];
        yield 'logout' => [
            'https://example.test/wp-login.php?action=logout&_wpnonce=nonce',
            'https://example.test/private-login/?action=logout&_wpnonce=nonce',
        ];
        yield 'registration' => [
            'https://example.test/wp-login.php?action=register',
            'https://example.test/private-login/?action=register',
        ];
        yield 'lost password' => [
            'https://example.test/wp-login.php?action=lostpassword',
            'https://example.test/private-login/?action=lostpassword',
        ];
        yield 'reset password short action' => [
            'https://example.test/wp-login.php?action=rp&key=reset-key&login=user%40example.test',
            'https://example.test/private-login/?action=rp&key=reset-key&login=user%40example.test',
        ];
        yield 'reset password long action' => [
            'https://example.test/wp-login.php?action=resetpass&key=reset-key&login=user',
            'https://example.test/private-login/?action=resetpass&key=reset-key&login=user',
        ];
        yield 'protected post password' => [
            'https://example.test/wp-login.php?action=postpass',
            'https://example.test/private-login/?action=postpass',
        ];
    }

    #[DataProvider('generatedLoginEndpoints')]
    public function test_site_login_endpoints_are_rewritten_outside_custom_rendering(
        string $native_url,
        string $expected_url
    ): void {
        $module = new HideLoginModule();

        self::assertSame(
            $expected_url,
            $module->filter_site_login_url($native_url, 'wp-login.php', 'login', null)
        );
    }

    public function test_network_generated_login_url_uses_the_current_sites_custom_route(): void
    {
        $module = new HideLoginModule();

        self::assertSame(
            'https://example.test/private-login/?action=register',
            $module->filter_network_login_url(
                'https://network.example/wp-login.php?action=register',
                'wp-login.php?action=register',
                'login'
            )
        );
    }

    public function test_non_login_site_url_and_recovery_url_remain_native(): void
    {
        $module = new HideLoginModule();
        $other_url = 'https://example.test/wp-admin/profile.php';
        $recovery_url = 'https://example.test/wp-login.php?action=enter_recovery_mode&rm_token=token&rm_key=key';

        self::assertSame($other_url, $module->filter_site_login_url($other_url, 'wp-admin/profile.php', null, null));
        self::assertSame(
            $recovery_url,
            $module->filter_site_login_url($recovery_url, 'wp-login.php', 'login', null)
        );
    }

    public function test_direct_reset_and_post_password_requests_are_blocked(): void
    {
        $module = new HideLoginModule();

        foreach (['rp', 'resetpass', 'postpass'] as $action) {
            $_GET = ['action' => $action];
            $_REQUEST = $_GET;

            try {
                $module->handle_direct_wp_login_access();
                self::fail('Direct wp-login.php access was not blocked for ' . $action);
            } catch (\DSTK_WP_Die_Exception) {
                self::assertSame([404, ''], $GLOBALS['dstk_test_status_header']);
                self::assertSame(404, $GLOBALS['dstk_test_wp_die'][2]['response']);
            }
        }
    }

    public function test_collision_refresh_observes_new_previous_and_deleted_slugs(): void
    {
        $module = new HideLoginModule();
        $scenarios = [
            'created' => [
                new HideLoginCollisionPost((object) ['ID' => 1, 'post_name' => 'private-login', 'post_status' => 'publish']),
                null,
            ],
            'renamed away' => [
                new HideLoginCollisionPost((object) ['ID' => 1, 'post_name' => 'about', 'post_status' => 'publish']),
                new HideLoginCollisionPost((object) ['ID' => 1, 'post_name' => 'private-login', 'post_status' => 'publish']),
            ],
            'trashed' => [
                new HideLoginCollisionPost((object) ['ID' => 1, 'post_name' => 'private-login', 'post_status' => 'trash']),
                new HideLoginCollisionPost((object) ['ID' => 1, 'post_name' => 'private-login', 'post_status' => 'publish']),
            ],
            'restored' => [
                new HideLoginCollisionPost((object) ['ID' => 1, 'post_name' => 'private-login', 'post_status' => 'publish']),
                new HideLoginCollisionPost((object) ['ID' => 1, 'post_name' => 'private-login', 'post_status' => 'trash']),
            ],
        ];

        foreach ($scenarios as $name => [$post, $post_before]) {
            unset($GLOBALS['dstk_test_options'][Settings::REWRITE_FLUSH_PENDING_OPTION]);
            $module->maybe_schedule_collision_flush($post->ID, $post, $post_before !== null, $post_before);
            self::assertSame(
                1,
                $GLOBALS['dstk_test_options'][Settings::REWRITE_FLUSH_PENDING_OPTION] ?? null,
                'Rewrite refresh was not scheduled when collision content was ' . $name
            );
        }

        unset($GLOBALS['dstk_test_options'][Settings::REWRITE_FLUSH_PENDING_OPTION]);
        $deleted = new HideLoginCollisionPost(
            (object) ['ID' => 1, 'post_name' => 'private-login', 'post_status' => 'publish']
        );
        $module->maybe_schedule_deleted_collision_flush($deleted->ID, $deleted);

        self::assertSame(1, $GLOBALS['dstk_test_options'][Settings::REWRITE_FLUSH_PENDING_OPTION] ?? null);
    }

    public function test_collision_hook_receives_the_previous_post_snapshot(): void
    {
        $module = new HideLoginModule();
        $module->register();

        self::assertArrayHasKey('wp_after_insert_post', $GLOBALS['dstk_test_actions']);
        self::assertSame(4, $GLOBALS['dstk_test_actions']['wp_after_insert_post'][0][2]);
    }
}
