<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Modules;

use DrSlon\Toolkit\Core\ModuleInterface;
use DrSlon\Toolkit\Core\Settings;
use DrSlon\Toolkit\Integrations\SeoFrameworkDetector;
use WP_Post;
use WP_Query;
use WP_Term;

final class SitemapModule implements ModuleInterface
{
    private const MAX_URLS_PER_ENTITY = 1000;
    private const MAX_FILTERED_SOURCE_PAGES = 10;
    private const CACHE_TTL = 600;
    private const CLIENT_CACHE_TTL = 300;
    private const CACHE_VERSION_OPTION = 'dstk_sitemap_cache_version';
    private const QUERY_KIND = 'dstk_sitemap';
    private const QUERY_NAME = 'dstk_sitemap_name';
    private const QUERY_PAGE = 'dstk_sitemap_page';

    /** @var array{post_types:array<int,string>,taxonomies:array<int,string>}|null */
    private ?array $configuration = null;

    /** @var array<string,int> */
    private array $post_type_page_counts = [];

    /** @var array<string,int> */
    private array $taxonomy_page_counts = [];

    /** @var array<string,array<int,array{loc:string,lastmod:string}>> */
    private array $filtered_post_entries = [];

    private ?string $cache_version = null;
    private bool $cache_invalidated = false;
    private ?bool $runtime_enabled = null;

    public function register(): void
    {
        add_action('init', [$this, 'register_rewrite_rules']);
        add_action('template_redirect', [$this, 'maybe_serve_sitemap'], 0);
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_filter('robots_txt', [$this, 'add_sitemap_to_robots'], 20, 2);
        add_filter('wp_sitemaps_enabled', [$this, 'filter_core_sitemaps_enabled'], 20);
        add_action('update_option_' . Settings::OPTION_KEY, [$this, 'invalidate_cache'], 10, 0);

        if ($this->is_runtime_enabled()) {
            foreach (
                [
                    'clean_post_cache',
                    'clean_term_cache',
                    'set_object_terms',
                    'added_post_meta',
                    'updated_post_meta',
                    'deleted_post_meta',
                ] as $hook
            ) {
                add_action($hook, [$this, 'invalidate_cache'], 10, 0);
            }
        }

        if (is_admin()) {
            add_action('admin_notices', [$this, 'render_tsf_notice']);
        }
    }

    public function register_rewrite_rules(): void
    {
        if (! $this->is_custom_provider_enabled()) {
            return;
        }

        add_rewrite_rule(
            '^sitemap\.xml$',
            'index.php?' . self::QUERY_KIND . '=index',
            'top'
        );
        add_rewrite_rule(
            '^sitemap-(pt|tax)-([a-z0-9_-]+)\.xml$',
            'index.php?' . self::QUERY_KIND . '=$matches[1]&' . self::QUERY_NAME . '=$matches[2]&' . self::QUERY_PAGE . '=1',
            'top'
        );
        add_rewrite_rule(
            '^sitemap-(pt|tax)-([a-z0-9_-]+)-([1-9][0-9]*)\.xml$',
            'index.php?' . self::QUERY_KIND . '=$matches[1]&' . self::QUERY_NAME . '=$matches[2]&' . self::QUERY_PAGE . '=$matches[3]',
            'top'
        );
    }

    /**
     * @param array<int,string> $query_vars
     * @return array<int,string>
     */
    public function register_query_vars(array $query_vars): array
    {
        $query_vars[] = self::QUERY_KIND;
        $query_vars[] = self::QUERY_NAME;
        $query_vars[] = self::QUERY_PAGE;

        return array_values(array_unique($query_vars));
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
        echo esc_html__('Sitemap Dr.Slon Toolkit не активируется при активном The SEO Framework, чтобы избежать дублирования карт сайта.', 'dr-slon-toolkit');
        echo '</p></div>';
    }

    public function maybe_serve_sitemap(): void
    {
        if (is_admin() || ! $this->is_custom_provider_enabled()) {
            return;
        }

        $request_method = isset($_SERVER['REQUEST_METHOD'])
            ? strtoupper(sanitize_key(wp_unslash((string) $_SERVER['REQUEST_METHOD'])))
            : 'GET';

        if (! in_array($request_method, ['GET', 'HEAD'], true)) {
            return;
        }

        $route = $this->resolve_request_route();

        if ($route === null) {
            return;
        }

        if (! $this->is_route_allowed($route)) {
            $this->send_not_found_response();
        }

        $cache_key = $this->cache_key($route);
        $response = $this->get_cached_response($cache_key);

        if ($response !== null) {
            $this->send_xml_response($response, $request_method);
        }

        if (! $this->route_has_items($route)) {
            $this->send_not_found_response();
        }

        $xml = $this->render_route($route);

        if ($xml === '') {
            $this->send_not_found_response();
        }

        $response = [
            'body'     => $xml,
            'etag'     => '"' . hash('sha256', $xml) . '"',
            'modified' => time(),
        ];

        set_transient($cache_key, $response, self::CACHE_TTL);
        $this->send_xml_response($response, $request_method);
    }

    public function filter_core_sitemaps_enabled(bool $enabled): bool
    {
        if ($this->is_custom_provider_enabled()) {
            return false;
        }

        return $enabled;
    }

    public function add_sitemap_to_robots(string $output, bool $public): string
    {
        if (! $public || ! $this->is_custom_provider_enabled()) {
            return $output;
        }

        $directive = 'Sitemap: ' . $this->sitemap_url(
            [
                'kind' => 'index',
                'name' => '',
                'page' => 1,
            ]
        );
        $lines = preg_split('/\r\n|\r|\n/', $output) ?: [];

        foreach ($lines as $line) {
            if (strcasecmp(trim((string) $line), $directive) === 0) {
                return $output;
            }
        }

        $output = rtrim($output);

        return ($output === '' ? '' : $output . "\n") . $directive . "\n";
    }

    public function invalidate_cache(): void
    {
        $this->configuration = null;
        $this->runtime_enabled = null;
        $this->post_type_page_counts = [];
        $this->taxonomy_page_counts = [];
        $this->filtered_post_entries = [];

        if ($this->cache_invalidated) {
            return;
        }

        $version = wp_generate_uuid4();

        if (! add_option(self::CACHE_VERSION_OPTION, $version, '', false)) {
            update_option(self::CACHE_VERSION_OPTION, $version, false);
        }

        $this->cache_version = $version;
        $this->cache_invalidated = true;
    }

    private function is_runtime_enabled(): bool
    {
        if ($this->runtime_enabled !== null) {
            return $this->runtime_enabled;
        }

        $settings = Settings::all();
        $sitemap = isset($settings['sitemap']) && is_array($settings['sitemap']) ? $settings['sitemap'] : [];

        $this->runtime_enabled = (int) get_option('blog_public', 1) === 1
            && ! empty($settings['modules']['sitemap'])
            && ! empty($sitemap['enabled']);

        return $this->runtime_enabled;
    }

    private function is_custom_provider_enabled(): bool
    {
        return $this->is_runtime_enabled() && ! $this->is_tsf_sitemap_conflict();
    }

    private function is_tsf_sitemap_conflict(): bool
    {
        $detector = new SeoFrameworkDetector();

        return $detector->is_active();
    }

    /**
     * @return array{post_types:array<int,string>,taxonomies:array<int,string>}
     */
    private function config(): array
    {
        if ($this->configuration !== null) {
            return $this->configuration;
        }

        $settings = Settings::all();
        $sitemap = isset($settings['sitemap']) && is_array($settings['sitemap']) ? $settings['sitemap'] : [];
        $post_types = isset($sitemap['post_types']) && is_array($sitemap['post_types']) ? $sitemap['post_types'] : ['post', 'page'];
        $taxonomies = isset($sitemap['taxonomies']) && is_array($sitemap['taxonomies']) ? $sitemap['taxonomies'] : ['category', 'post_tag'];
        $post_types = array_values(array_filter(array_map('sanitize_key', $post_types)));
        $taxonomies = array_values(array_filter(array_map('sanitize_key', $taxonomies)));
        $this->configuration = [
            'post_types' => array_values(array_unique($post_types)),
            'taxonomies' => array_values(array_unique($taxonomies)),
        ];

        return $this->configuration;
    }

    /**
     * @return array{kind:string,name:string,page:int}|null
     */
    private function resolve_request_route(): ?array
    {
        $request_path = $this->relative_request_path();

        if ($request_path === null) {
            return null;
        }

        if ($this->uses_query_routes()) {
            if ($request_path !== '/') {
                return null;
            }

            $kind_value = get_query_var(self::QUERY_KIND, '');
            $kind = is_scalar($kind_value) ? sanitize_key((string) $kind_value) : '';

            if ($kind === 'index') {
                return [
                    'kind' => 'index',
                    'name' => '',
                    'page' => 1,
                ];
            }

            if (! in_array($kind, ['pt', 'tax'], true)) {
                return null;
            }

            $name_value = get_query_var(self::QUERY_NAME, '');
            $page_value = get_query_var(self::QUERY_PAGE, '1');
            $name = is_scalar($name_value) ? sanitize_key((string) $name_value) : '';
            $page_raw = is_scalar($page_value) ? (string) $page_value : '';

            return [
                'kind' => $kind,
                'name' => $name,
                'page' => preg_match('/^[1-9][0-9]*$/', $page_raw) === 1 ? (int) $page_raw : 0,
            ];
        }

        if ($request_path === '/sitemap.xml') {
            return [
                'kind' => 'index',
                'name' => '',
                'page' => 1,
            ];
        }

        if (preg_match('#^/sitemap-(pt|tax)-([a-z0-9_-]+)-([1-9][0-9]*)\.xml$#i', $request_path, $matches) === 1) {
            return [
                'kind' => sanitize_key((string) $matches[1]),
                'name' => sanitize_key((string) $matches[2]),
                'page' => (int) $matches[3],
            ];
        }

        if (preg_match('#^/sitemap-(pt|tax)-([a-z0-9_-]+)\.xml$#i', $request_path, $matches) === 1) {
            return [
                'kind' => sanitize_key((string) $matches[1]),
                'name' => sanitize_key((string) $matches[2]),
                'page' => 1,
            ];
        }

        return null;
    }

    private function relative_request_path(): ?string
    {
        $request_uri = isset($_SERVER['REQUEST_URI'])
            ? esc_url_raw(wp_unslash((string) $_SERVER['REQUEST_URI']))
            : '';

        if ($request_uri === '' || preg_match('/[\x00-\x1f\x7f]/', $request_uri) === 1) {
            return null;
        }

        $request_path = wp_parse_url($request_uri, PHP_URL_PATH);
        $home_path = wp_parse_url(home_url('/'), PHP_URL_PATH);

        if (! is_string($request_path) || ! is_string($home_path)) {
            return null;
        }

        $request_path = '/' . ltrim($request_path, '/');
        $home_path = '/' . trim($home_path, '/');

        if ($home_path === '/') {
            return $request_path;
        }

        if ($request_path === $home_path || $request_path === $home_path . '/') {
            return '/';
        }

        if (! str_starts_with($request_path, $home_path . '/')) {
            return null;
        }

        return '/' . ltrim((string) substr($request_path, strlen($home_path)), '/');
    }

    /**
     * @param array{kind:string,name:string,page:int} $route
     */
    private function is_route_allowed(array $route): bool
    {
        if ($route['kind'] === 'index') {
            return true;
        }

        if ($route['page'] < 1 || $route['name'] === '') {
            return false;
        }

        if ($route['kind'] === 'pt') {
            return $this->is_allowed_post_type($route['name']);
        }

        if ($route['kind'] === 'tax') {
            return $this->is_allowed_taxonomy($route['name']);
        }

        return false;
    }

    /**
     * @param array{kind:string,name:string,page:int} $route
     */
    private function route_has_items(array $route): bool
    {
        if ($route['kind'] === 'index') {
            return true;
        }

        if ($route['kind'] === 'pt') {
            return $route['page'] <= $this->get_post_type_page_count($route['name']);
        }

        return $route['page'] <= $this->get_taxonomy_page_count($route['name']);
    }

    /**
     * @param array{kind:string,name:string,page:int} $route
     */
    private function render_route(array $route): string
    {
        if ($route['kind'] === 'index') {
            return $this->render_sitemap_index();
        }

        if ($route['kind'] === 'pt') {
            return $this->render_post_type_sitemap($route['name'], $route['page']);
        }

        return $this->render_taxonomy_sitemap($route['name'], $route['page']);
    }

    private function render_sitemap_index(): string
    {
        $config = $this->config();
        $entries = [];

        foreach ($config['post_types'] as $post_type) {
            if (! $this->is_public_post_type($post_type)) {
                continue;
            }

            $page_count = $this->get_post_type_page_count($post_type);

            if ($page_count < 1) {
                continue;
            }

            $lastmod = $this->get_post_type_lastmod($post_type);

            for ($page = 1; $page <= $page_count; $page++) {
                $entries[] = [
                    'loc' => $this->sitemap_url(
                        [
                            'kind' => 'pt',
                            'name' => $post_type,
                            'page' => $page,
                        ]
                    ),
                    'lastmod' => $lastmod,
                ];
            }
        }

        foreach ($config['taxonomies'] as $taxonomy) {
            if (! $this->is_public_taxonomy($taxonomy)) {
                continue;
            }

            $page_count = $this->get_taxonomy_page_count($taxonomy);

            for ($page = 1; $page <= $page_count; $page++) {
                $entries[] = [
                    'loc' => $this->sitemap_url(
                        [
                            'kind' => 'tax',
                            'name' => $taxonomy,
                            'page' => $page,
                        ]
                    ),
                    'lastmod' => '',
                ];
            }
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

    private function render_post_type_sitemap(string $post_type, int $page): string
    {
        if (! $this->is_allowed_post_type($post_type) || $page < 1) {
            return '';
        }

        if (has_filter('dstk_sitemap_is_noindex')) {
            $entries = array_slice(
                $this->get_filtered_post_entries($post_type),
                ($page - 1) * self::MAX_URLS_PER_ENTITY,
                self::MAX_URLS_PER_ENTITY
            );

            return $this->render_urlset($entries);
        }

        $query = new WP_Query(
            [
                'post_type'              => $post_type,
                'post_status'            => 'publish',
                'posts_per_page'         => self::MAX_URLS_PER_ENTITY,
                'paged'                  => $page,
                'orderby'                => 'ID',
                'order'                  => 'ASC',
                'has_password'           => false,
                'no_found_rows'          => true,
                'ignore_sticky_posts'    => true,
                'update_post_meta_cache' => has_filter('dstk_sitemap_is_noindex'),
                'update_post_term_cache' => false,
            ]
        );

        $entries = [];

        foreach ($query->posts as $post) {
            if (! ($post instanceof WP_Post)) {
                continue;
            }

            $entry = $this->post_entry($post);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return $this->render_urlset($entries);
    }

    private function render_taxonomy_sitemap(string $taxonomy, int $page): string
    {
        if (! $this->is_allowed_taxonomy($taxonomy) || $page < 1) {
            return '';
        }

        $terms = get_terms(
            [
                'taxonomy'   => $taxonomy,
                'hide_empty' => true,
                'number'     => self::MAX_URLS_PER_ENTITY,
                'offset'     => ($page - 1) * self::MAX_URLS_PER_ENTITY,
                'orderby'    => 'term_id',
                'order'      => 'ASC',
            ]
        );

        if (is_wp_error($terms) || ! is_array($terms)) {
            return '';
        }

        $entries = [];

        foreach ($terms as $term) {
            if (! ($term instanceof WP_Term)) {
                continue;
            }

            $loc = get_term_link($term);

            if (is_wp_error($loc) || ! is_string($loc) || $loc === '') {
                continue;
            }

            $loc = $this->normalize_site_url($loc);

            if ($loc === '') {
                continue;
            }

            $entries[] = [
                'loc'     => $loc,
                'lastmod' => '',
            ];
        }

        return $this->render_urlset($entries);
    }

    private function get_post_type_page_count(string $post_type): int
    {
        if (isset($this->post_type_page_counts[$post_type])) {
            return $this->post_type_page_counts[$post_type];
        }

        if (has_filter('dstk_sitemap_is_noindex')) {
            $total = count($this->get_filtered_post_entries($post_type));
            $this->post_type_page_counts[$post_type] = (int) ceil($total / self::MAX_URLS_PER_ENTITY);

            return $this->post_type_page_counts[$post_type];
        }

        $query = new WP_Query(
            [
                'post_type'              => $post_type,
                'post_status'            => 'publish',
                'posts_per_page'         => 1,
                'paged'                  => 1,
                'fields'                 => 'ids',
                'has_password'           => false,
                'no_found_rows'          => false,
                'ignore_sticky_posts'    => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ]
        );
        $total = max(0, (int) $query->found_posts);
        $this->post_type_page_counts[$post_type] = (int) ceil($total / self::MAX_URLS_PER_ENTITY);

        return $this->post_type_page_counts[$post_type];
    }

    /**
     * Arbitrary noindex callbacks cannot be represented in SQL. Scan a fixed
     * candidate budget and compact eligible entries so no advertised page is empty.
     *
     * @return array<int,array{loc:string,lastmod:string}>
     */
    private function get_filtered_post_entries(string $post_type): array
    {
        if (array_key_exists($post_type, $this->filtered_post_entries)) {
            return $this->filtered_post_entries[$post_type];
        }

        $entries = [];

        for ($source_page = 1; $source_page <= self::MAX_FILTERED_SOURCE_PAGES; ++$source_page) {
            $query = new WP_Query(
                [
                    'post_type'              => $post_type,
                    'post_status'            => 'publish',
                    'posts_per_page'         => self::MAX_URLS_PER_ENTITY,
                    'paged'                  => $source_page,
                    'orderby'                => 'ID',
                    'order'                  => 'ASC',
                    'has_password'           => false,
                    'no_found_rows'          => true,
                    'ignore_sticky_posts'    => true,
                    'update_post_meta_cache' => true,
                    'update_post_term_cache' => false,
                ]
            );
            $source_count = count($query->posts);

            foreach ($query->posts as $post) {
                if (! ($post instanceof WP_Post)) {
                    continue;
                }

                $entry = $this->post_entry($post);

                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }

            if ($source_count < self::MAX_URLS_PER_ENTITY) {
                break;
            }
        }

        $this->filtered_post_entries[$post_type] = $entries;

        return $this->filtered_post_entries[$post_type];
    }

    /**
     * @return array{loc:string,lastmod:string}|null
     */
    private function post_entry(WP_Post $post): ?array
    {
        if (! $this->is_post_allowed($post)) {
            return null;
        }

        $loc = get_permalink($post);

        if (! is_string($loc) || $loc === '') {
            return null;
        }

        $loc = $this->normalize_site_url($loc);

        if ($loc === '') {
            return null;
        }

        return [
            'loc'     => $loc,
            'lastmod' => $this->format_lastmod((string) $post->post_modified_gmt),
        ];
    }

    /**
     * @param array<int,array{loc:string,lastmod:string}> $entries
     */
    private function render_urlset(array $entries): string
    {
        if ($entries === []) {
            return '';
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($entries as $entry) {
            $xml .= '<url>';
            $xml .= '<loc>' . esc_xml((string) esc_url_raw($entry['loc'])) . '</loc>';

            if ($entry['lastmod'] !== '') {
                $xml .= '<lastmod>' . esc_xml($entry['lastmod']) . '</lastmod>';
            }

            $xml .= '</url>';
        }

        $xml .= '</urlset>';

        return $xml;
    }

    private function get_taxonomy_page_count(string $taxonomy): int
    {
        if (isset($this->taxonomy_page_counts[$taxonomy])) {
            return $this->taxonomy_page_counts[$taxonomy];
        }

        $total = wp_count_terms(
            [
                'taxonomy'   => $taxonomy,
                'hide_empty' => true,
            ]
        );
        $total = is_wp_error($total) ? 0 : max(0, (int) $total);
        $this->taxonomy_page_counts[$taxonomy] = (int) ceil($total / self::MAX_URLS_PER_ENTITY);

        return $this->taxonomy_page_counts[$taxonomy];
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

        return $object !== null && is_post_type_viewable($object);
    }

    private function is_public_taxonomy(string $taxonomy): bool
    {
        $object = get_taxonomy($taxonomy);

        return $object !== false && is_taxonomy_viewable($object);
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
        $query = new WP_Query(
            [
                'post_type'              => $post_type,
                'post_status'            => 'publish',
                'posts_per_page'         => 1,
                'orderby'                => 'modified',
                'order'                  => 'DESC',
                'has_password'           => false,
                'no_found_rows'          => true,
                'ignore_sticky_posts'    => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
            ]
        );
        $post = $query->posts[0] ?? null;

        if (! ($post instanceof WP_Post)) {
            return '';
        }

        return $this->format_lastmod((string) $post->post_modified_gmt);
    }

    /**
     * @param array{kind:string,name:string,page:int} $route
     */
    private function sitemap_url(array $route): string
    {
        if ($this->uses_query_routes()) {
            $query_args = [self::QUERY_KIND => $route['kind']];

            if ($route['kind'] !== 'index') {
                $query_args[self::QUERY_NAME] = $route['name'];
                $query_args[self::QUERY_PAGE] = $route['page'];
            }

            return (string) add_query_arg($query_args, home_url('/'));
        }

        if ($route['kind'] === 'index') {
            return home_url('/sitemap.xml');
        }

        $page_suffix = $route['page'] > 1 ? '-' . $route['page'] : '';

        return home_url('/sitemap-' . $route['kind'] . '-' . $route['name'] . $page_suffix . '.xml');
    }

    /**
     * @param array{kind:string,name:string,page:int} $route
     */
    private function cache_key(array $route): string
    {
        $context = wp_json_encode(
            [
                'version'    => $this->cache_version(),
                'format'     => 2,
                'route'      => $route,
                'config'     => $this->config(),
                'home'       => home_url('/'),
                'permalinks' => (string) get_option('permalink_structure', ''),
                'front'      => (string) get_option('show_on_front', 'posts'),
                'front_page' => (int) get_option('page_on_front', 0),
                'category'   => (string) get_option('category_base', ''),
                'tag'        => (string) get_option('tag_base', ''),
            ]
        );

        return 'dstk_sitemap_' . md5(is_string($context) ? $context : '');
    }

    private function cache_version(): string
    {
        if ($this->cache_version !== null) {
            return $this->cache_version;
        }

        $version = get_option(self::CACHE_VERSION_OPTION, '1');
        $this->cache_version = is_scalar($version) && (string) $version !== '' ? (string) $version : '1';

        return $this->cache_version;
    }

    /**
     * @return array{body:string,etag:string,modified:int}|null
     */
    private function get_cached_response(string $cache_key): ?array
    {
        $response = get_transient($cache_key);

        if (
            ! is_array($response)
            || ! isset($response['body'], $response['etag'], $response['modified'])
            || ! is_string($response['body'])
            || ! is_string($response['etag'])
            || ! is_numeric($response['modified'])
        ) {
            return null;
        }

        return [
            'body'     => $response['body'],
            'etag'     => $response['etag'],
            'modified' => (int) $response['modified'],
        ];
    }

    /**
     * @param array{body:string,etag:string,modified:int} $response
     */
    private function send_xml_response(array $response, string $request_method): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=' . self::CLIENT_CACHE_TTL . ', must-revalidate');
        header('ETag: ' . $response['etag']);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $response['modified']) . ' GMT');

        if ($this->request_is_not_modified($response)) {
            status_header(304);
            exit;
        }

        status_header(200);

        if ($request_method === 'HEAD') {
            exit;
        }

        echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML entries are escaped while rendering.
        exit;
    }

    /**
     * @param array{body:string,etag:string,modified:int} $response
     */
    private function request_is_not_modified(array $response): bool
    {
        $if_none_match = isset($_SERVER['HTTP_IF_NONE_MATCH'])
            ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_IF_NONE_MATCH']))
            : '';

        if ($if_none_match !== '') {
            foreach (explode(',', $if_none_match) as $candidate) {
                $candidate = trim($candidate);

                if ($candidate === '*' || preg_replace('/^W\//', '', $candidate) === $response['etag']) {
                    return true;
                }
            }

            return false;
        }

        $if_modified_since = isset($_SERVER['HTTP_IF_MODIFIED_SINCE'])
            ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_IF_MODIFIED_SINCE']))
            : '';
        $timestamp = $if_modified_since !== '' ? strtotime($if_modified_since) : false;

        return $timestamp !== false && $timestamp >= $response['modified'];
    }

    private function send_not_found_response(): void
    {
        status_header(404);
        nocache_headers();
        exit;
    }

    private function uses_query_routes(): bool
    {
        global $wp_rewrite;

        return (string) get_option('permalink_structure', '') === ''
            || (is_object($wp_rewrite) && method_exists($wp_rewrite, 'using_index_permalinks') && $wp_rewrite->using_index_permalinks());
    }

    private function normalize_site_url(string $url): string
    {
        $url = esc_url_raw($url, ['http', 'https']);
        $parts = wp_parse_url($url);
        $home = wp_parse_url(home_url('/'));

        if ($url === '' || ! is_array($parts) || ! is_array($home)) {
            return '';
        }

        if (
            strtolower((string) ($parts['scheme'] ?? '')) !== strtolower((string) ($home['scheme'] ?? ''))
            || strtolower((string) ($parts['host'] ?? '')) !== strtolower((string) ($home['host'] ?? ''))
            || $this->effective_port($parts) !== $this->effective_port($home)
        ) {
            return '';
        }

        $path = '/' . ltrim((string) ($parts['path'] ?? '/'), '/');
        $home_path = untrailingslashit('/' . ltrim((string) ($home['path'] ?? '/'), '/'));

        if ($home_path !== '' && $home_path !== '/' && $path !== $home_path && ! str_starts_with($path, $home_path . '/')) {
            return '';
        }

        return $url;
    }

    /**
     * @param array<string,mixed> $parts
     */
    private function effective_port(array $parts): int
    {
        if (isset($parts['port'])) {
            return (int) $parts['port'];
        }

        return strtolower((string) ($parts['scheme'] ?? '')) === 'https' ? 443 : 80;
    }
}
