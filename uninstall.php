<?php

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$dstk_cleanup_site = static function (): void {
    wp_clear_scheduled_hook('dstk_indexnow_process_queue');

    foreach (
        [
            'dstk_settings',
            'dstk_version',
            'dstk_indexnow_cache',
            'dstk_indexnow_queue',
            'dstk_indexnow_queue_status',
            'dstk_indexnow_queue_lock',
            'dstk_sitemap_cache_version',
            'dstk_rewrite_flush_pending',
            'dstk_hide_login_rewrite_flush_pending',
        ] as $option
    ) {
        delete_option($option);
    }

    // Drop rewrite cache so rules for hide-login/sitemap regenerate without residual entries.
    delete_option('rewrite_rules');

    global $wpdb;

    $patterns = [
        $wpdb->esc_like('_transient_dstk_sitemap_') . '%',
        $wpdb->esc_like('_transient_timeout_dstk_sitemap_') . '%',
    ];

    foreach ($patterns as $pattern) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $pattern));
    }
};

if (is_multisite()) {
    $offset = 0;
    $network_id = function_exists('get_current_network_id') ? get_current_network_id() : 0;

    do {
        $query = [
            'fields' => 'ids',
            'number' => 100,
            'offset' => $offset,
        ];

        if ($network_id > 0) {
            $query['network_id'] = $network_id;
        }

        $site_ids = get_sites($query);

        foreach ($site_ids as $site_id) {
            switch_to_blog((int) $site_id);

            try {
                $dstk_cleanup_site();
            } finally {
                restore_current_blog();
            }
        }

        $offset += count($site_ids);
    } while (count($site_ids) === 100);
} else {
    $dstk_cleanup_site();
}

delete_site_transient('dstk_github_release_v1');
