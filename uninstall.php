<?php

declare(strict_types=1);

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('dstk_settings');
delete_option('dstk_version');
