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

    /**
     * @param array<string, mixed> $settings
     */
    public static function fingerprint(array $settings): string
    {
        $ai = isset($settings['ai_agents']) && is_array($settings['ai_agents']) ? $settings['ai_agents'] : [];
        $payload = [
            'hide_login' => [
                'on'   => ! empty($settings['modules']['hide_login']),
                'slug' => (string) ($settings['hide_login']['slug'] ?? ''),
            ],
            'sitemap'    => [
                'on'      => ! empty($settings['modules']['sitemap']),
                'enabled' => ! empty($settings['sitemap']['enabled']),
            ],
            'ai_agents'  => [
                'on'         => ! empty($settings['modules']['ai_agents']),
                'ai_txt'     => ! empty($ai['ai_txt']),
                'llms_txt'   => ! empty($ai['llms_txt']),
                'llms_full'  => ! empty($ai['llms_full']),
                'agents_md'  => ! empty($ai['agents_md']),
                'pulse_md'   => ! empty($ai['pulse_md']),
            ],
        ];
        $encoded = wp_json_encode($payload);

        return hash('sha256', is_string($encoded) ? $encoded : '');
    }

    /**
     * @param array<string, mixed> $previous
     * @param array<string, mixed> $sanitized
     */
    public static function schedule_if_changed(array $previous, array $sanitized): void
    {
        if (! hash_equals(self::fingerprint($previous), self::fingerprint($sanitized))) {
            self::schedule();
        }
    }

    public function maybe_flush(): void
    {
        if (! get_option(Settings::REWRITE_FLUSH_PENDING_OPTION, false)) {
            return;
        }

        // Flush first so a fatal/timeout during rewrite rebuild keeps the pending
        // flag and retries on the next request.
        flush_rewrite_rules();
        delete_option(Settings::REWRITE_FLUSH_PENDING_OPTION);
        delete_option(self::LEGACY_PENDING_OPTION);
    }

    public static function deactivate(): void
    {
        delete_option(Settings::REWRITE_FLUSH_PENDING_OPTION);
        delete_option(self::LEGACY_PENDING_OPTION);

        // A switched blog shares the request's WP_Rewrite object, so regenerate safely on its next request.
        delete_option('rewrite_rules');
    }
}
