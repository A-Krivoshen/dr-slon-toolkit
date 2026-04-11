<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Modules;

use DrSlon\Toolkit\Core\ModuleInterface;

final class DisableCommentsModule implements ModuleInterface
{
    public function register(): void
    {
        add_action('admin_init', [$this, 'remove_comment_support']);
        add_action('admin_menu', [$this, 'remove_comments_menu']);
        add_action('init', [$this, 'close_comments_for_types']);
        add_filter('comments_open', '__return_false', 20, 2);
        add_filter('pings_open', '__return_false', 20, 2);
        add_filter('comments_array', '__return_empty_array', 10, 2);

        if (is_admin()) {
            add_action('admin_bar_menu', [$this, 'remove_admin_bar_comments'], 60);
            add_action('current_screen', [$this, 'block_comments_screen']);
        }
    }

    public function close_comments_for_types(): void
    {
        $post_types = get_post_types(['public' => true], 'names');

        foreach ($post_types as $post_type) {
            if (! post_type_supports($post_type, 'comments')) {
                continue;
            }

            remove_post_type_support($post_type, 'comments');
            remove_post_type_support($post_type, 'trackbacks');
        }
    }

    public function remove_comment_support(): void
    {
        $post_types = get_post_types([], 'names');

        foreach ($post_types as $post_type) {
            remove_post_type_support($post_type, 'comments');
            remove_post_type_support($post_type, 'trackbacks');
        }
    }

    public function remove_comments_menu(): void
    {
        remove_menu_page('edit-comments.php');
    }

    public function remove_admin_bar_comments(\WP_Admin_Bar $admin_bar): void
    {
        $admin_bar->remove_node('comments');
    }

    public function block_comments_screen(\WP_Screen $screen): void
    {
        if ($screen->id !== 'edit-comments' && $screen->base !== 'comment') {
            return;
        }

        wp_safe_redirect(admin_url('index.php'));
        exit;
    }
}
