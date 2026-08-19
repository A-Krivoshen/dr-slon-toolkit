<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Core;

final class UrlNormalizer
{
    public static function comparable(string $url): string
    {
        $url = esc_url_raw(trim($url), ['http', 'https']);
        $parts = wp_parse_url($url);

        if ($url === '' || ! is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return '';
        }

        $has_port = isset($parts['port']);
        $port = $has_port ? (int) $parts['port'] : 0;

        if ($has_port && ($port < 1 || $port > 65535)) {
            return '';
        }

        $path = self::normalize_percent_encoded_component((string) ($parts['path'] ?? '/'));

        if ($path === null) {
            return '';
        }

        $origin_host = str_contains($host, ':') && ! str_starts_with($host, '[')
            ? '[' . $host . ']'
            : $host;
        $normalized = $scheme . '://' . $origin_host;
        $default_port = $scheme === 'https' ? 443 : 80;

        if ($port > 0 && $port !== $default_port) {
            $normalized .= ':' . $port;
        }

        $normalized .= $path === '' ? '/' : $path;

        if (isset($parts['query']) && (string) $parts['query'] !== '') {
            $query = self::normalize_percent_encoded_component((string) $parts['query']);

            if ($query === null) {
                return '';
            }

            $normalized .= '?' . $query;
        }

        return $normalized;
    }

    public static function is_external_canonical(string $canonical): bool
    {
        $canonical = self::comparable($canonical);
        $home = self::comparable(home_url('/'));

        if ($canonical === '' || $home === '') {
            return false;
        }

        $canonical_parts = wp_parse_url($canonical);
        $home_parts = wp_parse_url($home);

        if (! is_array($canonical_parts) || ! is_array($home_parts)) {
            return false;
        }

        $canonical_host = strtolower((string) ($canonical_parts['host'] ?? ''));
        $home_host = strtolower((string) ($home_parts['host'] ?? ''));
        $canonical_scheme = strtolower((string) ($canonical_parts['scheme'] ?? ''));
        $home_scheme = strtolower((string) ($home_parts['scheme'] ?? ''));

        if ($canonical_host === '' || $home_host === '' || $canonical_host !== $home_host) {
            return true;
        }

        return self::effective_port($canonical_parts) !== self::effective_port($home_parts)
            || ($canonical_scheme !== '' && $home_scheme !== '' && $canonical_scheme !== $home_scheme);
    }

    /**
     * @param array<string, mixed> $parts
     */
    public static function effective_port(array $parts): int
    {
        if (isset($parts['port'])) {
            $port = (int) $parts['port'];

            return $port >= 1 && $port <= 65535 ? $port : 0;
        }

        return strtolower((string) ($parts['scheme'] ?? '')) === 'https' ? 443 : 80;
    }

    public static function normalize_percent_encoded_component(string $component): ?string
    {
        $normalized = '';
        $length = strlen($component);

        for ($index = 0; $index < $length; ++$index) {
            $character = $component[$index];

            if ($character === '%') {
                if ($index + 2 >= $length || ctype_xdigit($component[$index + 1] . $component[$index + 2]) === false) {
                    return null;
                }

                $byte = (int) hexdec($component[$index + 1] . $component[$index + 2]);
                $decoded = chr($byte);
                $normalized .= preg_match('/[A-Za-z0-9._~-]/', $decoded) === 1
                    ? $decoded
                    : '%' . strtoupper($component[$index + 1] . $component[$index + 2]);
                $index += 2;
                continue;
            }

            $byte = ord($character);
            $normalized .= $byte > 127 ? sprintf('%%%02X', $byte) : $character;
        }

        return $normalized;
    }
}
