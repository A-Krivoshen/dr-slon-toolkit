<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Core;

final class Activator
{
    public static function activate(): void
    {
        if (! current_user_can('activate_plugins')) {
            return;
        }

        $settings = get_option(Settings::OPTION_KEY, null);

        if (! is_array($settings)) {
            add_option(Settings::OPTION_KEY, Settings::defaults());
        }

        update_option('dstk_version', DSTK_VERSION);
        flush_rewrite_rules();
    }
}
