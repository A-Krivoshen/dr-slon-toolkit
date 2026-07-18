<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Modules;

use DrSlon\Toolkit\Core\ModuleInterface;

final class DisableCommentsModule implements ModuleInterface
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'remove_comments_menu']);
        add_action('init', [$this, 'remove_comment_support'], 100);
        add_action('wp_loaded', [$this, 'remove_comment_support'], PHP_INT_MAX);
        add_action('registered_post_type', [$this, 'remove_comment_support_from_post_type']);
        add_action('admin_bar_menu', [$this, 'remove_admin_bar_comments'], PHP_INT_MAX);
        add_filter('comments_open', '__return_false', 20, 2);
        add_filter('pings_open', '__return_false', 20, 2);
        add_filter('comments_array', '__return_empty_array', 10, 2);

        if (is_admin()) {
            add_action('current_screen', [$this, 'block_comments_screen']);
        }
    }

    public function remove_comment_support(): void
    {
        $post_types = get_post_types([], 'names');

        foreach ($post_types as $post_type) {
            $this->remove_comment_support_from_post_type($post_type);
        }
    }

    public function remove_comment_support_from_post_type(string $post_type): void
    {
        remove_post_type_support($post_type, 'comments');
        remove_post_type_support($post_type, 'trackbacks');
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
        if (wp_doing_ajax()) {
            return;
        }

        if ($screen->id !== 'edit-comments' && $screen->base !== 'comment') {
            return;
        }

        wp_safe_redirect(admin_url('index.php'));
        exit;
    }
}
