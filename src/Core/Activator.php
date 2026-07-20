<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Core;

final class Activator
{
    public static function register_hooks(): void
    {
        if (is_multisite()) {
            add_action('wp_initialize_site', [self::class, 'on_initialize_site'], 10, 1);
        }
    }

    public static function activate(bool $network_wide = false): void
    {
        if (is_multisite() && $network_wide) {
            self::for_each_site([self::class, 'activate_site']);
            return;
        }

        self::activate_site();
    }

    /**
     * Seed options when a new site is created under a network-activated plugin.
     *
     * @param mixed $new_site
     */
    public static function on_initialize_site($new_site): void
    {
        $blog_id = 0;

        if (is_object($new_site) && isset($new_site->blog_id)) {
            $blog_id = (int) $new_site->blog_id;
        } elseif (is_numeric($new_site)) {
            $blog_id = (int) $new_site;
        }

        if ($blog_id < 1) {
            return;
        }

        switch_to_blog($blog_id);

        try {
            self::activate_site();
        } finally {
            restore_current_blog();
        }
    }

    public static function activate_site(): void
    {
        $settings = get_option(Settings::OPTION_KEY, null);

        if ($settings === false || $settings === null) {
            add_option(Settings::OPTION_KEY, Settings::defaults());
        } elseif (! is_array($settings)) {
            // Repair corrupted option values left by manual DB edits or failed writes.
            update_option(Settings::OPTION_KEY, Settings::defaults());
        }

        update_option('dstk_version', DSTK_VERSION);
        RewriteManager::schedule();
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
