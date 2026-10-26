<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Modules;

use DrSlon\Toolkit\Core\ModuleInterface;
use DrSlon\Toolkit\Core\Settings;

final class RedirectManagerModule implements ModuleInterface
{
    private static int $rejected = 0;

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

        $request_uri = isset($_SERVER['REQUEST_URI'])
            ? esc_url_raw(wp_unslash((string) $_SERVER['REQUEST_URI']))
            : '';
        $match = $this->match($this->request_path(), $request_uri);

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
     * Exact paths ignore the query string. Rules whose source contains `*` or `?`
     * are matched against the raw request URI: one `*` capture, case-insensitive,
     * first enabled rule wins.
     *
     * @return array{from:string,to:string,status:int,enabled:bool}|null
     */
    public function match(string $path, string $request_uri = '', bool $include_disabled = false): ?array
    {
        $path = $this->normalize_from($path);

        if ($path === '' || $this->is_protected_path($path)) {
            return null;
        }

        $subject = strtolower(rtrim($request_uri !== '' ? $request_uri : $path, '/'));

        foreach ($this->rules() as $rule) {
            if (! $include_disabled && empty($rule['enabled'])) {
                continue;
            }

            $from = $rule['from'];

            if (self::is_pattern_rule($from)) {
                $expanded = $this->expand_pattern($from, $subject, $rule);

                if ($expanded !== null) {
                    return $expanded;
                }

                continue;
            }

            if (strtolower($this->normalize_from($from)) === strtolower($path)) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Explain a saved-rule lookup for the admin probe. Does not redirect.
     *
     * @return array{code:string,from:string,to:string,status:int}
     */
    public function diagnose(string $raw): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return ['code' => 'empty', 'from' => '', 'to' => '', 'status' => 0];
        }

        $path = $raw;
        $uri = $raw;

        if (preg_match('#^https?://#i', $raw) === 1) {
            $parsed_path = wp_parse_url(esc_url_raw($raw, ['http', 'https']), PHP_URL_PATH);
            $parsed_query = wp_parse_url($raw, PHP_URL_QUERY);
            $path = is_string($parsed_path) && $parsed_path !== '' ? $parsed_path : '/';
            $uri = $path;

            if (is_string($parsed_query) && $parsed_query !== '') {
                $uri .= '?' . $parsed_query;
            }
        }

        $normalized = $this->normalize_from($path);

        if ($normalized === '' || $this->is_protected_path($normalized)) {
            return ['code' => 'blocked', 'from' => $normalized, 'to' => '', 'status' => 0];
        }

        $match = $this->match($path, $uri);

        if ($match !== null) {
            return [
                'code'   => 'matched',
                'from'   => $match['from'],
                'to'     => $match['to'],
                'status' => $match['status'],
            ];
        }

        $shadow = $this->match($path, $uri, true);

        if ($shadow !== null && empty($shadow['enabled'])) {
            return [
                'code'   => 'disabled',
                'from'   => $shadow['from'],
                'to'     => $shadow['to'],
                'status' => $shadow['status'],
            ];
        }

        return ['code' => 'none', 'from' => $normalized, 'to' => '', 'status' => 0];
    }

    private static function is_pattern_rule(string $from): bool
    {
        return str_contains($from, '*') || str_contains($from, '?');
    }

    /**
     * @param array{from:string,to:string,status:int,enabled:bool} $rule
     * @return array{from:string,to:string,status:int,enabled:bool}|null
     */
    private function expand_pattern(string $from, string $subject, array $rule): ?array
    {
        $pattern = strtolower(rtrim($from, '/'));
        $pattern = str_replace('\\*', '(.*)', preg_quote($pattern, '/'));

        if (preg_match('/\A' . $pattern . '\z/', $subject, $matches) !== 1) {
            return null;
        }

        $to = $rule['to'];

        if (str_contains($to, '*') && isset($matches[1]) && $matches[1] !== '') {
            $to = str_replace('*', $matches[1], $to);
        }

        return [
            'from'    => $rule['from'],
            'to'      => $to,
            'status'  => $rule['status'],
            'enabled' => $rule['enabled'],
        ];
    }

    /**
     * @return array<int, array{from:string,to:string,status:int,enabled:bool}>
     */
    public function rules(): array
    {
        $settings = Settings::all();
        $redirects = isset($settings['redirects']) && is_array($settings['redirects']) ? $settings['redirects'] : [];
        $rules = isset($redirects['rules']) && is_array($redirects['rules']) ? $redirects['rules'] : [];

        return self::sanitize_stored_rules($rules);
    }

    /**
     * @return array{from:string,to:string,status:int,enabled:bool}|null
     */
    public static function sanitize_rule(string $from, string $to, int $status, bool $enabled = true): ?array
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
            'from'    => $from,
            'to'      => $to,
            'status'  => $status,
            'enabled' => $enabled,
        ];
    }

    public static function rejected_count(): int
    {
        return self::$rejected;
    }

    /**
     * @param array<string, mixed> $redirects
     * @return array<int, array{from:string,to:string,status:int,enabled:bool}>
     */
    public static function rules_from_input(array $redirects): array
    {
        self::$rejected = 0;

        if (! empty($redirects['editor'])) {
            $rows = isset($redirects['rows']) && is_array($redirects['rows']) ? $redirects['rows'] : [];
            $rules = self::parse_rows($rows);
            $import = isset($redirects['import_text']) ? (string) $redirects['import_text'] : '';

            if (trim($import) !== '') {
                $rules = self::append_unique($rules, self::parse_rules_text($import));
            }

            return $rules;
        }

        if (isset($redirects['rules_text'])) {
            return self::parse_rules_text((string) $redirects['rules_text']);
        }

        if (isset($redirects['rules']) && is_array($redirects['rules'])) {
            return self::sanitize_stored_rules($redirects['rules']);
        }

        return [];
    }

    /**
     * @param array<mixed> $rows
     * @return array<int, array{from:string,to:string,status:int,enabled:bool}>
     */
    private static function parse_rows(array $rows): array
    {
        $rules = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $from = trim((string) ($row['from'] ?? ''));
            $to = trim((string) ($row['to'] ?? ''));

            if ($from === '' && $to === '') {
                continue;
            }

            if (count($rules) >= 100) {
                ++self::$rejected;
                continue;
            }

            $parsed = self::sanitize_rule(
                $from,
                $to,
                (int) ($row['status'] ?? 301),
                ! empty($row['enabled'])
            );

            if ($parsed === null || isset($rules[$parsed['from']])) {
                ++self::$rejected;
                continue;
            }

            $rules[$parsed['from']] = $parsed;
        }

        return array_values($rules);
    }

    /**
     * @param array<int, array{from:string,to:string,status:int,enabled:bool}> $rules
     * @param array<int, array{from:string,to:string,status:int,enabled:bool}> $extra
     * @return array<int, array{from:string,to:string,status:int,enabled:bool}>
     */
    private static function append_unique(array $rules, array $extra): array
    {
        $seen = [];

        foreach ($rules as $rule) {
            $seen[$rule['from']] = true;
        }

        foreach ($extra as $rule) {
            if (isset($seen[$rule['from']]) || count($rules) >= 100) {
                ++self::$rejected;
                continue;
            }

            $seen[$rule['from']] = true;
            $rules[] = $rule;
        }

        return $rules;
    }

    /**
     * @param array<mixed> $rules
     * @return array<int, array{from:string,to:string,status:int,enabled:bool}>
     */
    private static function sanitize_stored_rules(array $rules): array
    {
        $clean = [];

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $enabled = ! array_key_exists('enabled', $rule) || ! empty($rule['enabled']);
            $parsed = self::sanitize_rule(
                (string) ($rule['from'] ?? ''),
                (string) ($rule['to'] ?? ''),
                (int) ($rule['status'] ?? 301),
                $enabled
            );

            if ($parsed === null || isset($clean[$parsed['from']])) {
                continue;
            }

            $clean[$parsed['from']] = $parsed;

            if (count($clean) >= 100) {
                break;
            }
        }

        return array_values($clean);
    }

    /**
     * @return array<int, array{from:string,to:string,status:int,enabled:bool}>
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
