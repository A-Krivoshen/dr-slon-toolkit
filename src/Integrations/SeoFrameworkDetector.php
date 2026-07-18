<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Integrations;

final class SeoFrameworkDetector
{
    public function is_active(): bool
    {
        return defined('THE_SEO_FRAMEWORK_PRESENT')
            || defined('THE_SEO_FRAMEWORK_VERSION')
            || class_exists('The_SEO_Framework\\Load', false)
            || function_exists('tsf')
            || function_exists('the_seo_framework');
    }

    public function render_notice(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        echo '<div class="notice notice-info"><p>';
        echo esc_html__('Обнаружен The SEO Framework: Sitemap Dr.Slon Toolkit отключён во избежание дублей, а IndexNow учитывает noindex и canonical.', 'dr-slon-toolkit');
        echo '</p></div>';
    }

    public function is_sitemap_served(): bool
    {
        return $this->is_active();
    }

    public function is_post_indexable(int $post_id, string $url): bool
    {
        if (! $this->is_active()) {
            return true;
        }

        if (
            (int) get_option('blog_public', 1) !== 1
            || ! did_action('the_seo_framework_loaded')
            || ! function_exists('tsf')
        ) {
            return false;
        }

        try {
            $tsf = tsf();

            if (method_exists($tsf, 'robots') && method_exists($tsf, 'uri')) {
                $robots_api = $tsf->robots();
                $uri_api = $tsf->uri();

                if (! method_exists($robots_api, 'get_generated_meta') || ! method_exists($uri_api, 'get_canonical_url')) {
                    return false;
                }

                $robots = $robots_api->get_generated_meta(['id' => $post_id], ['noindex']);
                $canonical = $uri_api->get_canonical_url(['id' => $post_id]);
            } elseif (method_exists($tsf, 'generate_robots_meta') && method_exists($tsf, 'get_canonical_url')) {
                $robots = $tsf->generate_robots_meta(['id' => $post_id], ['noindex']);
                $canonical = $tsf->get_canonical_url(
                    [
                        'id'               => $post_id,
                        'get_custom_field' => true,
                    ]
                );
            } else {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        if (! is_array($robots) || ! empty($robots['noindex'])) {
            return false;
        }

        $canonical = is_string($canonical) ? $this->normalize_comparable_url($canonical) : '';
        $url = $this->normalize_comparable_url($url);

        return $canonical !== '' && $url !== '' && hash_equals($url, $canonical);
    }

    private function normalize_comparable_url(string $url): string
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

        $path = $this->normalize_percent_encoded_component((string) ($parts['path'] ?? '/'));

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
            $query = $this->normalize_percent_encoded_component((string) $parts['query']);

            if ($query === null) {
                return '';
            }

            $normalized .= '?' . $query;
        }

        return $normalized;
    }

    private function normalize_percent_encoded_component(string $component): ?string
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
