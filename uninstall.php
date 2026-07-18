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

    $rewrite_rules = get_option('rewrite_rules', []);

    if (is_array($rewrite_rules)) {
        foreach ($rewrite_rules as $rule => $query) {
            if (
                is_string($query)
                && (str_contains($query, 'dstk_custom_login=') || str_contains($query, 'dstk_sitemap='))
            ) {
                unset($rewrite_rules[$rule]);
            }
        }

        update_option('rewrite_rules', $rewrite_rules, false);
    }

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

    do {
        $site_ids = get_sites(
            [
                'fields' => 'ids',
                'number' => 100,
                'offset' => $offset,
            ]
        );

        foreach ($site_ids as $site_id) {
            switch_to_blog((int) $site_id);
            $dstk_cleanup_site();
            restore_current_blog();
        }

        $offset += count($site_ids);
    } while (count($site_ids) === 100);
} else {
    $dstk_cleanup_site();
}

delete_site_transient('dstk_github_release_v1');
