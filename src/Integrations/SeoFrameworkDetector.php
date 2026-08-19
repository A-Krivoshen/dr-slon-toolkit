<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Integrations;

use DrSlon\Toolkit\Core\UrlNormalizer;

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
        $status = $this->indexability_source();
        $message = __('Обнаружен The SEO Framework: Sitemap Dr.Slon Toolkit не дублирует карту TSF, а IndexNow и AI Agents учитывают noindex и внешний canonical.', 'dr-slon-toolkit');

        if ($status === 'unavailable') {
            $message = __('The SEO Framework установлен, но API ещё не готов: IndexNow, Sitemap и AI Agents работают в fail-open режиме до загрузки TSF.', 'dr-slon-toolkit');
        }

        echo esc_html($message);
        echo '</p></div>';
    }

    /**
     * Whether The SEO Framework is actively serving an XML sitemap.
     * Fail closed (true) when TSF is present but its sitemap state cannot be determined.
     */
    public function is_sitemap_served(): bool
    {
        if (! $this->is_active()) {
            return false;
        }

        if (! did_action('the_seo_framework_loaded') || ! function_exists('tsf')) {
            return true;
        }

        try {
            $tsf = tsf();

            if (method_exists($tsf, 'get_option')) {
                $output = $tsf->get_option('sitemaps_output');

                if (is_bool($output) || is_int($output) || is_string($output)) {
                    return ! empty($output);
                }
            }

            if (method_exists($tsf, 'sitemap')) {
                $sitemap = $tsf->sitemap();

                if (is_object($sitemap) && method_exists($sitemap, 'enabled')) {
                    return (bool) $sitemap->enabled();
                }
            }
        } catch (\Throwable) {
            return true;
        }

        return true;
    }

    public function is_api_ready(): bool
    {
        return $this->is_active()
            && did_action('the_seo_framework_loaded') > 0
            && function_exists('tsf');
    }

    /**
     * @return 'none'|'unavailable'|'tsf'
     */
    public function indexability_source(): string
    {
        if (! $this->is_active()) {
            return 'none';
        }

        return $this->is_api_ready() ? 'tsf' : 'unavailable';
    }

    public function is_post_indexable(int $post_id, string $url): bool
    {
        unset($url);

        if (! $this->is_active()) {
            return true;
        }

        if ((int) get_option('blog_public', 1) !== 1) {
            return false;
        }

        if (! $this->is_api_ready()) {
            return true;
        }

        try {
            $tsf = tsf();

            if (method_exists($tsf, 'robots') && method_exists($tsf, 'uri')) {
                $robots_api = $tsf->robots();
                $uri_api = $tsf->uri();

                if (! method_exists($robots_api, 'get_generated_meta') || ! method_exists($uri_api, 'get_canonical_url')) {
                    return true;
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
                return true;
            }
        } catch (\Throwable) {
            return true;
        }

        if (! is_array($robots) || ! empty($robots['noindex'])) {
            return false;
        }

        if (is_string($canonical) && $canonical !== '' && UrlNormalizer::is_external_canonical($canonical)) {
            return false;
        }

        return true;
    }

    /**
     * @deprecated 0.10.0 Use UrlNormalizer::comparable().
     */
    private function normalize_comparable_url(string $url): string
    {
        return UrlNormalizer::comparable($url);
    }
}
