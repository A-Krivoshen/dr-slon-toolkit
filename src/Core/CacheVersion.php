<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Core;

final class CacheVersion
{
    public const SITEMAP = 'dstk_sitemap_cache_version';
    public const AI = 'dstk_ai_cache_version';

    public static function get(string $option): string
    {
        $version = get_option($option, '1');

        return is_scalar($version) && (string) $version !== '' ? (string) $version : '1';
    }

    public static function bump(string $option): string
    {
        $version = wp_generate_uuid4();

        if (! add_option($option, $version, '', false)) {
            update_option($option, $version, false);
        }

        return $version;
    }
}
