<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Core;

final class RewriteManager
{
    private const LEGACY_PENDING_OPTION = 'dstk_hide_login_rewrite_flush_pending';

    public function register(): void
    {
        add_action('wp_loaded', [$this, 'maybe_flush'], PHP_INT_MAX);
    }

    public static function schedule(): void
    {
        update_option(Settings::REWRITE_FLUSH_PENDING_OPTION, 1, false);
    }

    public function maybe_flush(): void
    {
        if (! get_option(Settings::REWRITE_FLUSH_PENDING_OPTION, false)) {
            return;
        }

        delete_option(Settings::REWRITE_FLUSH_PENDING_OPTION);
        delete_option(self::LEGACY_PENDING_OPTION);
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        delete_option(Settings::REWRITE_FLUSH_PENDING_OPTION);
        delete_option(self::LEGACY_PENDING_OPTION);

        // A switched blog shares the request's WP_Rewrite object, so regenerate safely on its next request.
        delete_option('rewrite_rules');
    }
}
