<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Core;

final class Settings
{
    public const OPTION_KEY = 'dstk_settings';

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'modules' => [
                'transliteration'  => false,
                'disable_comments' => false,
                'cleanup'          => false,
                'hide_login'       => false,
            ],
            'cleanup' => [
                'disable_emojis'   => true,
                'disable_wp_embed' => true,
                'disable_xmlrpc'   => false,
                'clean_head'       => true,
            ],
            'hide_login' => [
                'slug' => 'login',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $saved = get_option(self::OPTION_KEY, []);

        if (! is_array($saved)) {
            $saved = [];
        }

        return self::merge_with_defaults($saved);
    }

    public static function module_enabled(string $module): bool
    {
        $settings = self::all();

        return ! empty($settings['modules'][$module]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function merge_with_defaults(array $input): array
    {
        $defaults = self::defaults();

        $modules = isset($input['modules']) && is_array($input['modules']) ? $input['modules'] : [];
        $cleanup = isset($input['cleanup']) && is_array($input['cleanup']) ? $input['cleanup'] : [];
        $hide_login = isset($input['hide_login']) && is_array($input['hide_login']) ? $input['hide_login'] : [];

        $slug = '';

        if (array_key_exists('slug', $hide_login)) {
            $slug = sanitize_title_with_dashes((string) $hide_login['slug']);
        }

        if ($slug === '' || $slug === 'wp-login.php' || $slug === 'wp-admin') {
            $slug = $defaults['hide_login']['slug'];
        }

        return [
            'modules' => [
                'transliteration'  => ! empty($modules['transliteration']),
                'disable_comments' => ! empty($modules['disable_comments']),
                'cleanup'          => ! empty($modules['cleanup']),
                'hide_login'       => ! empty($modules['hide_login']),
            ],
            'cleanup' => [
                'disable_emojis'   => array_key_exists('disable_emojis', $cleanup) ? ! empty($cleanup['disable_emojis']) : $defaults['cleanup']['disable_emojis'],
                'disable_wp_embed' => array_key_exists('disable_wp_embed', $cleanup) ? ! empty($cleanup['disable_wp_embed']) : $defaults['cleanup']['disable_wp_embed'],
                'disable_xmlrpc'   => array_key_exists('disable_xmlrpc', $cleanup) ? ! empty($cleanup['disable_xmlrpc']) : $defaults['cleanup']['disable_xmlrpc'],
                'clean_head'       => array_key_exists('clean_head', $cleanup) ? ! empty($cleanup['clean_head']) : $defaults['cleanup']['clean_head'],
            ],
            'hide_login' => [
                'slug' => $slug,
            ],
        ];
    }
}
