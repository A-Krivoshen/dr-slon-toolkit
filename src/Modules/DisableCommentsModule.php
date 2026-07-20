<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Modules;

use DrSlon\Toolkit\Core\ModuleInterface;
use WP_Error;

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
        add_filter('feed_links_show_comments_feed', '__return_false');
        add_filter('post_comments_feed_link', '__return_empty_string');
        add_filter('comments_pre_query', [$this, 'empty_comments_query'], 10, 1);
        add_action('pre_comment_on_post', [$this, 'block_comment_submission'], 0);
        add_filter('rest_pre_insert_comment', [$this, 'block_rest_comment'], 10, 2);
        add_filter('rest_endpoints', [$this, 'remove_comment_rest_endpoints']);

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

    /**
     * Block classic form submissions to wp-comments-post.php.
     */
    public function block_comment_submission(int $post_id): void
    {
        unset($post_id);

        wp_die(
            esc_html__('Комментарии отключены на этом сайте.', 'dr-slon-toolkit'),
            esc_html__('Комментарии отключены', 'dr-slon-toolkit'),
            ['response' => 403]
        );
    }

    /**
     * @param mixed $prepared_comment
     * @param mixed $request
     * @return mixed|WP_Error
     */
    public function block_rest_comment($prepared_comment, $request)
    {
        unset($prepared_comment, $request);

        return new WP_Error(
            'dstk_comments_disabled',
            __('Комментарии отключены на этом сайте.', 'dr-slon-toolkit'),
            ['status' => 403]
        );
    }

    /**
     * @param array<string, mixed> $endpoints
     * @return array<string, mixed>
     */
    public function remove_comment_rest_endpoints(array $endpoints): array
    {
        foreach (array_keys($endpoints) as $route) {
            if (! is_string($route)) {
                continue;
            }

            if (preg_match('#/comments(?:/|$)#', $route) === 1) {
                unset($endpoints[$route]);
            }
        }

        return $endpoints;
    }

    /**
     * @param mixed $comments
     * @return array<int, never>|mixed
     */
    public function empty_comments_query($comments)
    {
        return [];
    }
}
