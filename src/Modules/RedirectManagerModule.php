<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Modules;

use DrSlon\Toolkit\Core\ModuleInterface;
use DrSlon\Toolkit\Core\Settings;

final class RedirectManagerModule implements ModuleInterface
{
    public function register(): void
    {
        add_action('template_redirect', [$this, 'maybe_redirect'], 1);
    }

    public function maybe_redirect(): void
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return;
        }

        $method = isset($_SERVER['REQUEST_METHOD'])
            ? strtoupper(sanitize_key(wp_unslash((string) $_SERVER['REQUEST_METHOD'])))
            : 'GET';

        if (! in_array($method, ['GET', 'HEAD'], true)) {
            return;
        }

        $match = $this->match($this->request_path());

        if ($match === null) {
            return;
        }

        if ($this->is_external_target($match['to'])) {
            wp_redirect($match['to'], $match['status']); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- host already compared to home_url.
            exit;
        }

        wp_safe_redirect($match['to'], $match['status']);
        exit;
    }

    /**
     * @return array{from:string,to:string,status:int}|null
     */
    public function match(string $path): ?array
    {
        $path = $this->normalize_from($path);

        if ($path === '' || $this->is_protected_path($path)) {
            return null;
        }

        foreach ($this->rules() as $rule) {
            if ($this->normalize_from($rule['from']) === $path) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @return array<int, array{from:string,to:string,status:int}>
     */
    public function rules(): array
    {
        $settings = Settings::all();
        $redirects = isset($settings['redirects']) && is_array($settings['redirects']) ? $settings['redirects'] : [];
        $rules = isset($redirects['rules']) && is_array($redirects['rules']) ? $redirects['rules'] : [];
        $clean = [];

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $parsed = self::sanitize_rule(
                (string) ($rule['from'] ?? ''),
                (string) ($rule['to'] ?? ''),
                (int) ($rule['status'] ?? 301)
            );

            if ($parsed !== null) {
                $clean[] = $parsed;
            }
        }

        return $clean;
    }

    /**
     * @return array{from:string,to:string,status:int}|null
     */
    public static function sanitize_rule(string $from, string $to, int $status): ?array
    {
        $from = self::normalize_source($from);
        $to = self::normalize_target($to);
        $status = $status === 302 ? 302 : 301;

        if ($from === '' || $to === '' || self::is_protected_static($from)) {
            return null;
        }

        if (! self::is_external_static($to) && self::normalize_source($to) === $from) {
            return null;
        }

        return [
            'from'   => $from,
            'to'     => $to,
            'status' => $status,
        ];
    }

    /**
     * @return array<int, array{from:string,to:string,status:int}>
     */
    public static function parse_rules_text(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $rules = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^(.+?)\s*(?:->|→|\|)\s*(.+?)(?:\s*\|\s*(301|302))?\s*$/u', $line, $matches) !== 1) {
                continue;
            }

            $rule = self::sanitize_rule(
                (string) $matches[1],
                (string) $matches[2],
                isset($matches[3]) ? (int) $matches[3] : 301
            );

            if ($rule === null) {
                continue;
            }

            $rules[$rule['from']] = $rule;

            if (count($rules) >= 100) {
                break;
            }
        }

        return array_values($rules);
    }

    public static function rules_to_text(array $rules): string
    {
        $lines = [];

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $from = (string) ($rule['from'] ?? '');
            $to = (string) ($rule['to'] ?? '');
            $status = (int) ($rule['status'] ?? 301);

            if ($from === '' || $to === '') {
                continue;
            }

            $lines[] = $from . ' -> ' . $to . ($status === 302 ? ' | 302' : '');
        }

        return implode("\n", $lines);
    }

    public function request_path(): string
    {
        $request_uri = isset($_SERVER['REQUEST_URI'])
            ? esc_url_raw(wp_unslash((string) $_SERVER['REQUEST_URI']))
            : '';
        $path = is_string($request_uri) ? (string) wp_parse_url($request_uri, PHP_URL_PATH) : '';

        return $this->normalize_from($path);
    }

    private function normalize_from(string $path): string
    {
        return self::normalize_source($path);
    }

    private static function normalize_source(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $value) === 1) {
            $path = wp_parse_url(esc_url_raw($value, ['http', 'https']), PHP_URL_PATH);
            $value = is_string($path) ? $path : '';
        }

        $value = '/' . ltrim($value, '/');

        if (str_contains($value, '..') || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
            return '';
        }

        $home_path = wp_parse_url(home_url('/'), PHP_URL_PATH);
        $home_path = is_string($home_path) ? '/' . trim($home_path, '/') : '/';

        if ($home_path !== '/' && str_starts_with($value, $home_path . '/')) {
            $value = '/' . ltrim(substr($value, strlen($home_path)), '/');
        } elseif ($home_path !== '/' && rtrim($value, '/') === rtrim($home_path, '/')) {
            $value = '/';
        }

        if ($value !== '/') {
            $value = rtrim($value, '/');
        }

        return $value === '' ? '/' : $value;
    }

    private static function normalize_target(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $value) === 1) {
            return esc_url_raw($value, ['http', 'https']);
        }

        $path = self::normalize_source($value);

        return $path === '' ? '' : home_url($path);
    }

    private function is_protected_path(string $path): bool
    {
        return self::is_protected_static($path);
    }

    private static function is_protected_static(string $path): bool
    {
        $path = strtolower($path);

        foreach (['/wp-admin', '/wp-login.php', '/wp-json', '/xmlrpc.php'] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    private function is_external_target(string $url): bool
    {
        return self::is_external_static($url);
    }

    private static function is_external_static(string $url): bool
    {
        $target = wp_parse_url($url);
        $home = wp_parse_url(home_url('/'));

        if (! is_array($target) || ! is_array($home)) {
            return false;
        }

        $target_host = strtolower((string) ($target['host'] ?? ''));
        $home_host = strtolower((string) ($home['host'] ?? ''));

        return $target_host !== '' && $home_host !== '' && $target_host !== $home_host;
    }
}
