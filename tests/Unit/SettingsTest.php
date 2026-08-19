<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Tests\Unit;

use DrSlon\Toolkit\Core\Settings;
use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['dstk_test_options'] = [];
        $GLOBALS['dstk_test_filters'] = [];
    }

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

    public function test_trusted_capability_is_allowlisted(): void
    {
        self::assertSame('edit_posts', Settings::sanitize_trusted_capability('read'));
        self::assertSame('edit_posts', Settings::sanitize_trusted_capability('exist'));
        self::assertSame('manage_options', Settings::sanitize_trusted_capability('manage_options'));
    }

    public function test_ai_agents_limits_are_clamped_and_post_types_filtered(): void
    {
        $settings = Settings::merge_with_defaults(
            [
                'ai_agents' => [
                    '_submitted'       => '1',
                    'pulse_md'         => '1',
                    'pulse_limit'      => 999,
                    'full_posts_limit' => 0,
                    'post_types'       => ['page', 'attachment', 'nope'],
                    'site_blurb'       => '<strong>Клиника</strong>',
                ],
            ],
            true
        );

        self::assertTrue($settings['ai_agents']['pulse_md']);
        self::assertSame(50, $settings['ai_agents']['pulse_limit']);
        self::assertSame(1, $settings['ai_agents']['full_posts_limit']);
        self::assertSame(['page'], $settings['ai_agents']['post_types']);
        self::assertSame('Клиника', $settings['ai_agents']['site_blurb']);
        self::assertFalse($settings['ai_agents']['ai_txt']);
    }

    public function test_main_form_without_ai_block_keeps_saved_ai_settings(): void
    {
        $GLOBALS['dstk_test_options'][Settings::OPTION_KEY] = [
            'ai_agents' => [
                'site_blurb' => 'Сохранённый текст',
                'pulse_md'   => true,
                'pulse_limit' => 7,
            ],
        ];

        $settings = Settings::merge_with_defaults(
            [
                'modules' => ['cleanup' => '1'],
            ],
            true
        );

        self::assertSame('Сохранённый текст', $settings['ai_agents']['site_blurb']);
        self::assertTrue($settings['ai_agents']['pulse_md']);
        self::assertSame(7, $settings['ai_agents']['pulse_limit']);
    }
}
