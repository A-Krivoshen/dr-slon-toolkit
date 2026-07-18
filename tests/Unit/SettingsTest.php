<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Tests\Unit;

use DrSlon\Toolkit\Core\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    public function test_submitted_false_and_empty_values_are_preserved(): void
    {
        $settings = Settings::merge_with_defaults(
            [
                'cleanup' => [
                    '_submitted'      => '1',
                    'disable_emojis'  => '0',
                    'disable_wp_embed' => '0',
                    'clean_head'      => '0',
                ],
                'indexnow' => ['_submitted' => '1', 'post_types' => []],
                'sitemap' => ['_submitted' => '1', 'enabled' => '0', 'post_types' => [], 'taxonomies' => []],
                'update_controls' => [
                    '_submitted'        => '1',
                    'plugins'           => '0',
                    'themes'            => '0',
                    'translations'      => '0',
                    'email_notifications' => '0',
                ],
            ],
            true
        );

        self::assertFalse($settings['cleanup']['disable_emojis']);
        self::assertFalse($settings['cleanup']['disable_wp_embed']);
        self::assertFalse($settings['sitemap']['enabled']);
        self::assertSame([], $settings['indexnow']['post_types']);
        self::assertSame([], $settings['sitemap']['post_types']);
        self::assertSame([], $settings['sitemap']['taxonomies']);
        self::assertFalse($settings['update_controls']['plugins']);
    }

    public function test_page_is_viewable_but_attachment_is_not_selectable(): void
    {
        $settings = Settings::merge_with_defaults(
            [
                'indexnow' => [
                    '_submitted' => '1',
                    'post_types' => ['page', 'attachment'],
                ],
            ],
            true
        );

        self::assertSame(['page'], $settings['indexnow']['post_types']);
    }

    public function test_hide_login_slug_policy_is_consistent(): void
    {
        self::assertSame('client-login', Settings::sanitize_hide_login_slug('Client Login'));
        self::assertSame('login', Settings::sanitize_hide_login_slug('wp-json'));
        self::assertSame('login', Settings::sanitize_hide_login_slug('поиск'));
    }
}
