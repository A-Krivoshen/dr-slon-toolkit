<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Modules;

use DrSlon\Toolkit\Core\ModuleInterface;
use DrSlon\Toolkit\Core\Settings;
use DrSlon\Toolkit\Integrations\SeoFrameworkDetector;
use WP_Post;
use WP_Term;

final class SitemapModule implements ModuleInterface
{
    private const MAX_URLS_PER_ENTITY = 1000;

    public function register(): void
    {
        add_action('template_redirect', [$this, 'maybe_serve_sitemap'], 0);

        if (is_admin()) {
            add_action('admin_notices', [$this, 'render_tsf_notice']);
        }
    }

    public function render_tsf_notice(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        if (! $this->is_runtime_enabled() || ! $this->is_tsf_sitemap_conflict()) {
            return;
        }

        echo '<div class="notice notice-warning"><p>';
        echo esc_html__('Sitemap Dr.Slon Toolkit не активируется: карту сайта уже обслуживает The SEO Framework.', 'dr-slon-toolkit');
        echo '</p></div>';
    }

    public function maybe_serve_sitemap(): void
    {
        if (is_admin()) {
            return;
        }

        if (! $this->is_runtime_enabled() || $this->is_tsf_sitemap_conflict()) {
            return;
        }

        $request_method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

        if (! in_array($request_method, ['GET', 'HEAD'], true)) {
            return;
        }

        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $request_path = (string) wp_parse_url($request_uri, PHP_URL_PATH);
        $request_path = '/' . ltrim(trim($request_path), '/');

        if ($request_path === '/sitemap.xml') {
            $this->send_xml_response($this->render_sitemap_index(), $request_method);
        }

        if (! preg_match('#^/sitemap-(pt|tax)-([a-z0-9_-]+)\.xml$#i', $request_path, $matches)) {
            return;
        }

        $kind = sanitize_key((string) $matches[1]);
        $name = sanitize_key((string) $matches[2]);

        if ($kind === 'pt') {
            $xml = $this->render_post_type_sitemap($name);

            if ($xml !== '') {
                $this->send_xml_response($xml, $request_method);
            }

            return;
        }

        $xml = $this->render_taxonomy_sitemap($name);

        if ($xml !== '') {
            $this->send_xml_response($xml, $request_method);
        }
    }

    private function is_runtime_enabled(): bool
    {
        $settings = Settings::all();
        $sitemap = isset($settings['sitemap']) && is_array($settings['sitemap']) ? $settings['sitemap'] : [];

        return ! empty($settings['modules']['sitemap']) && ! empty($sitemap['enabled']);
    }

    private function is_tsf_sitemap_conflict(): bool
    {
        $detector = new SeoFrameworkDetector();

        return $detector->is_sitemap_served();
    }

    /**
     * @return array{post_types:array<int,string>,taxonomies:array<int,string>}
     */
    private function config(): array
    {
        $settings = Settings::all();
        $sitemap = isset($settings['sitemap']) && is_array($settings['sitemap']) ? $settings['sitemap'] : [];

        $post_types = isset($sitemap['post_types']) && is_array($sitemap['post_types']) ? $sitemap['post_types'] : ['post', 'page'];
        $taxonomies = isset($sitemap['taxonomies']) && is_array($sitemap['taxonomies']) ? $sitemap['taxonomies'] : ['category', 'post_tag'];

        return [
            'post_types' => array_values(array_filter(array_map('sanitize_key', $post_types))),
            'taxonomies' => array_values(array_filter(array_map('sanitize_key', $taxonomies))),
        ];
    }

    private function render_sitemap_index(): string
    {
        $config = $this->config();
        $entries = [];

        foreach ($config['post_types'] as $post_type) {
            if (! $this->is_public_post_type($post_type)) {
                continue;
            }

            $entries[] = [
                'loc' => home_url('/sitemap-pt-' . $post_type . '.xml'),
                'lastmod' => $this->get_post_type_lastmod($post_type),
            ];
        }

        foreach ($config['taxonomies'] as $taxonomy) {
            if (! $this->is_public_taxonomy($taxonomy)) {
                continue;
            }

            $entries[] = [
                'loc' => home_url('/sitemap-tax-' . $taxonomy . '.xml'),
                'lastmod' => '',
            ];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($entries as $entry) {
            $xml .= '<sitemap>';
            $xml .= '<loc>' . esc_xml((string) esc_url_raw((string) $entry['loc'])) . '</loc>';

            if ($entry['lastmod'] !== '') {
                $xml .= '<lastmod>' . esc_xml((string) $entry['lastmod']) . '</lastmod>';
            }

            $xml .= '</sitemap>';
        }

        $xml .= '</sitemapindex>';

        return $xml;
    }

    private function render_post_type_sitemap(string $post_type): string
    {
        if (! $this->is_allowed_post_type($post_type)) {
            return '';
        }

        $post_ids = get_posts(
            [
                'post_type'              => $post_type,
                'post_status'            => 'publish',
                'posts_per_page'         => self::MAX_URLS_PER_ENTITY,
                'orderby'                => 'modified',
                'order'                  => 'DESC',
                'no_found_rows'          => true,
                'ignore_sticky_posts'    => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'fields'                 => 'ids',
            ]
        );

        if (! is_array($post_ids)) {
            return '';
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($post_ids as $post_id) {
            $post = get_post((int) $post_id);

            if (! ($post instanceof WP_Post) || ! $this->is_post_allowed($post)) {
                continue;
            }

            $loc = get_permalink($post);

            if (! is_string($loc) || $loc === '') {
                continue;
            }

            $xml .= '<url>';
            $xml .= '<loc>' . esc_xml((string) esc_url_raw($loc)) . '</loc>';

            $lastmod = $this->format_lastmod((string) $post->post_modified_gmt);

            if ($lastmod !== '') {
                $xml .= '<lastmod>' . esc_xml($lastmod) . '</lastmod>';
            }

            $xml .= '</url>';
        }

        $xml .= '</urlset>';

        return $xml;
    }

    private function render_taxonomy_sitemap(string $taxonomy): string
    {
        if (! $this->is_allowed_taxonomy($taxonomy)) {
            return '';
        }

        $terms = get_terms(
            [
                'taxonomy'   => $taxonomy,
                'hide_empty' => true,
                'number'     => self::MAX_URLS_PER_ENTITY,
                'orderby'    => 'term_id',
                'order'      => 'DESC',
            ]
        );

        if (is_wp_error($terms) || ! is_array($terms)) {
            return '';
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($terms as $term) {
            if (! ($term instanceof WP_Term)) {
                continue;
            }

            $loc = get_term_link($term);

            if (is_wp_error($loc) || ! is_string($loc) || $loc === '') {
                continue;
            }

            $xml .= '<url>';
            $xml .= '<loc>' . esc_xml((string) esc_url_raw($loc)) . '</loc>';
            $xml .= '</url>';
        }

        $xml .= '</urlset>';

        return $xml;
    }

    private function is_allowed_post_type(string $post_type): bool
    {
        return $this->is_public_post_type($post_type)
            && in_array($post_type, $this->config()['post_types'], true);
    }

    private function is_allowed_taxonomy(string $taxonomy): bool
    {
        return $this->is_public_taxonomy($taxonomy)
            && in_array($taxonomy, $this->config()['taxonomies'], true);
    }

    private function is_public_post_type(string $post_type): bool
    {
        $object = get_post_type_object($post_type);

        return $object !== null && (bool) $object->public && (bool) $object->publicly_queryable;
    }

    private function is_public_taxonomy(string $taxonomy): bool
    {
        $object = get_taxonomy($taxonomy);

        return $object !== false && (bool) $object->public;
    }

    private function is_post_allowed(WP_Post $post): bool
    {
        if ($post->post_status !== 'publish') {
            return false;
        }

        if ((string) $post->post_password !== '') {
            return false;
        }

        /**
         * Позволяет внешнему коду исключить noindex-записи из sitemap.
         *
         * @param bool    $is_noindex Текущее состояние исключения.
         * @param WP_Post $post       Текущая запись.
         */
        return ! (bool) apply_filters('dstk_sitemap_is_noindex', false, $post);
    }

    private function format_lastmod(string $datetime): string
    {
        if ($datetime === '' || $datetime === '0000-00-00 00:00:00') {
            return '';
        }

        $timestamp = mysql2date('U', $datetime, false);

        if (! is_numeric($timestamp) || (int) $timestamp <= 0) {
            return '';
        }

        return gmdate('c', (int) $timestamp);
    }

    private function get_post_type_lastmod(string $post_type): string
    {
        $post_ids = get_posts(
            [
                'post_type'              => $post_type,
                'post_status'            => 'publish',
                'posts_per_page'         => 1,
                'orderby'                => 'modified',
                'order'                  => 'DESC',
                'no_found_rows'          => true,
                'ignore_sticky_posts'    => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'fields'                 => 'ids',
            ]
        );

        if (! is_array($post_ids) || $post_ids === []) {
            return '';
        }

        $modified_gmt = get_post_field('post_modified_gmt', (int) $post_ids[0]);

        return is_string($modified_gmt) ? $this->format_lastmod($modified_gmt) : '';
    }

    private function send_xml_response(string $xml, string $request_method): void
    {
        nocache_headers();
        status_header(200);
        header('Content-Type: application/xml; charset=utf-8');

        if ($request_method === 'HEAD') {
            exit;
        }

        echo $xml;
        exit;
    }
}
