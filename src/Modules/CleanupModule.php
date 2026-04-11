<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Modules;

use DrSlon\Toolkit\Core\ModuleInterface;
use DrSlon\Toolkit\Core\Settings;

final class CleanupModule implements ModuleInterface
{
    /**
     * @var array<string, bool>
     */
    private array $options;

    public function __construct()
    {
        $settings = Settings::all();
        $this->options = is_array($settings['cleanup'] ?? null) ? $settings['cleanup'] : [];
    }

    public function register(): void
    {
        if (! empty($this->options['disable_emojis'])) {
            $this->disable_emojis();
        }

        if (! empty($this->options['disable_wp_embed'])) {
            add_action('wp_enqueue_scripts', [$this, 'disable_embed_script'], 20);
        }

        if (! empty($this->options['disable_xmlrpc'])) {
            add_filter('xmlrpc_enabled', '__return_false');
        }

        if (! empty($this->options['clean_head'])) {
            add_action('init', [$this, 'clean_head_tags']);
        }
    }

    private function disable_emojis(): void
    {
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_styles', 'print_emoji_styles');
        remove_filter('the_content_feed', 'wp_staticize_emoji');
        remove_filter('comment_text_rss', 'wp_staticize_emoji');
        remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
        add_filter('emoji_svg_url', '__return_false');
    }

    public function disable_embed_script(): void
    {
        wp_deregister_script('wp-embed');
    }

    public function clean_head_tags(): void
    {
        remove_action('wp_head', 'wp_generator');
        remove_action('wp_head', 'rsd_link');
        remove_action('wp_head', 'wlwmanifest_link');
        remove_action('wp_head', 'wp_shortlink_wp_head', 10);
        remove_action('wp_head', 'rest_output_link_wp_head', 10);
        remove_action('wp_head', 'wp_oembed_add_discovery_links', 10);
    }
}
