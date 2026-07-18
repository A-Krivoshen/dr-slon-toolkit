<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Core;

use DrSlon\Toolkit\Modules\IndexNowModule;

final class Deactivator
{
    public static function deactivate(bool $network_wide = false): void
    {
        if (is_multisite() && $network_wide) {
            self::for_each_site([self::class, 'deactivate_site']);
            return;
        }

        self::deactivate_site();
    }

    public static function deactivate_site(): void
    {
        wp_clear_scheduled_hook(IndexNowModule::CRON_HOOK);
        delete_option(IndexNowModule::QUEUE_LOCK_OPTION);
        RewriteManager::deactivate();
    }

    /**
     * @param callable():void $callback
     */
    private static function for_each_site(callable $callback): void
    {
        $offset = 0;
        $network_id = get_current_network_id();

        do {
            $site_ids = get_sites(
                [
                    'fields'     => 'ids',
                    'network_id' => $network_id,
                    'number'     => 100,
                    'offset'     => $offset,
                ]
            );

            foreach ($site_ids as $site_id) {
                switch_to_blog((int) $site_id);

                try {
                    $callback();
                } finally {
                    restore_current_blog();
                }
            }

            $offset += count($site_ids);
        } while (count($site_ids) === 100);
    }
}
