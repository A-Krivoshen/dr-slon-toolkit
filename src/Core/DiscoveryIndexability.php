<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Core;

use DrSlon\Toolkit\Integrations\SeoFrameworkDetector;
use WP_Post;

final class DiscoveryIndexability
{
    /**
     * Shared publishability for Sitemap, IndexNow and AI Agents.
     *
     * @param array{exclude_noindex?:bool,noindex_filter?:string} $args
     */
    public static function is_post_indexable(WP_Post $post, string $url = '', array $args = []): bool
    {
        if ($post->post_status !== 'publish' || (string) $post->post_password !== '') {
            return false;
        }

        if ((int) get_option('blog_public', 1) !== 1) {
            return false;
        }

        $exclude_noindex = ! array_key_exists('exclude_noindex', $args) || ! empty($args['exclude_noindex']);

        if (! $exclude_noindex) {
            return true;
        }

        $filter = isset($args['noindex_filter']) ? (string) $args['noindex_filter'] : 'dstk_sitemap_is_noindex';

        if ($filter !== '' && (bool) apply_filters($filter, false, $post)) {
            return false;
        }

        if (
            $filter === 'dstk_ai_is_noindex'
            && (bool) apply_filters('dstk_sitemap_is_noindex', false, $post)
        ) {
            return false;
        }

        $permalink = $url !== '' ? $url : (string) get_permalink($post);

        return (new SeoFrameworkDetector())->is_post_indexable((int) $post->ID, $permalink);
    }
}
