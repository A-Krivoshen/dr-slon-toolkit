<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Modules;

use DrSlon\Toolkit\Core\CacheVersion;
use DrSlon\Toolkit\Core\ModuleInterface;
use DrSlon\Toolkit\Core\Settings;
use DrSlon\Toolkit\Core\Utf8Response;
use DrSlon\Toolkit\Modules\AiAgents\DocumentBuilder;
use DrSlon\Toolkit\Modules\AiAgents\PulseFeedBuilder;

final class AiAgentsModule implements ModuleInterface
{
    public const QUERY_VAR = 'dstk_ai_doc';
    public const CACHE_TTL = 600;

    private const KINDS = ['ai', 'llms', 'llms_full', 'agents', 'pulse'];

    /** @var array<string, mixed>|null */
    private ?array $configuration = null;

    private ?string $cache_version = null;
    private bool $cache_invalidated = false;
    private bool $invalidate_scheduled = false;

    public function register(): void
    {
        add_action('init', [$this, 'register_rewrite_rules']);
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_action('template_redirect', [$this, 'maybe_serve'], 0);
        add_action('wp_head', [$this, 'render_html_links'], 8);
        add_filter('robots_txt', [$this, 'add_robots_pointers'], 20, 2);
        add_action('update_option_' . Settings::OPTION_KEY, [$this, 'invalidate_cache'], 10, 0);

        foreach (
            [
                'save_post',
                'deleted_post',
                'transition_post_status',
                'created_term',
                'edited_term',
                'delete_term',
            ] as $hook
        ) {
            add_action($hook, [$this, 'invalidate_cache'], 10, 0);
        }
    }

    public function register_rewrite_rules(): void
    {
        $config = $this->config();

        if (! empty($config['ai_txt'])) {
            add_rewrite_rule('^ai\.txt$', 'index.php?' . self::QUERY_VAR . '=ai', 'top');
        }

        if (! empty($config['llms_txt'])) {
            add_rewrite_rule('^llms\.txt$', 'index.php?' . self::QUERY_VAR . '=llms', 'top');
        }

        if (! empty($config['llms_full'])) {
            add_rewrite_rule('^llms-full\.txt$', 'index.php?' . self::QUERY_VAR . '=llms_full', 'top');
        }

        if (! empty($config['agents_md'])) {
            add_rewrite_rule('^agents\.md$', 'index.php?' . self::QUERY_VAR . '=agents', 'top');
        }

        if (! empty($config['pulse_md'])) {
            add_rewrite_rule('^feed/ai-pulse\.md$', 'index.php?' . self::QUERY_VAR . '=pulse', 'top');
        }
    }

    /**
     * @param array<int, string> $query_vars
     * @return array<int, string>
     */
    public function register_query_vars(array $query_vars): array
    {
        $query_vars[] = self::QUERY_VAR;

        return array_values(array_unique($query_vars));
    }

    public function maybe_serve(): void
    {
        if (is_admin()) {
            return;
        }

        $request_method = isset($_SERVER['REQUEST_METHOD'])
            ? strtoupper(sanitize_key(wp_unslash((string) $_SERVER['REQUEST_METHOD'])))
            : 'GET';

        if (! in_array($request_method, ['GET', 'HEAD'], true)) {
            return;
        }

        $kind = $this->resolve_request_kind();

        if ($kind === null) {
            return;
        }

        if (! $this->kind_enabled($kind)) {
            status_header(404);
            nocache_headers();
            exit;
        }

        $cache_key = $this->cache_key($kind);
        $cached = get_transient($cache_key);

        if (is_string($cached) && $cached !== '') {
            Utf8Response::send($cached, $this->content_type($kind), $request_method);
        }

        $body = $kind === 'pulse'
            ? (new PulseFeedBuilder($this->config()))->build()
            : (new DocumentBuilder($this->config()))->build($kind);

        set_transient($cache_key, $body, self::CACHE_TTL);
        Utf8Response::send($body, $this->content_type($kind), $request_method);
    }

    public function render_html_links(): void
    {
        $config = $this->config();

        if (is_admin() || empty($config['html_links'])) {
            return;
        }

        $builder = new DocumentBuilder($config);

        foreach (['ai' => 'text/plain', 'llms' => 'text/plain', 'llms_full' => 'text/plain', 'agents' => 'text/markdown', 'pulse' => 'text/markdown'] as $kind => $type) {
            $url = $builder->endpoint_url($kind);

            if ($url === '') {
                continue;
            }

            printf(
                '<link rel="describedby" type="%s" href="%s" />' . "\n",
                esc_attr($type),
                esc_url($url)
            );
        }
    }

    public function add_robots_pointers(string $output, bool $public): string
    {
        $config = $this->config();

        if (! $public || empty($config['robots'])) {
            return $output;
        }

        $builder = new DocumentBuilder($config);
        $lines = ['# Dr.Slon Toolkit AI discovery'];

        foreach (['ai', 'llms', 'llms_full', 'agents', 'pulse'] as $kind) {
            $url = $builder->endpoint_url($kind);

            if ($url !== '') {
                $lines[] = '# ' . $url;
            }
        }

        if (count($lines) === 1) {
            return $output;
        }

        $block = implode("\n", $lines);
        $output = rtrim($output);

        return ($output === '' ? '' : $output . "\n") . $block . "\n";
    }

    public function invalidate_cache(): void
    {
        $this->configuration = null;

        if ($this->cache_invalidated || $this->invalidate_scheduled) {
            return;
        }

        $this->invalidate_scheduled = true;
        add_action('shutdown', [$this, 'commit_cache_invalidation'], 0);
    }

    public function commit_cache_invalidation(): void
    {
        if ($this->cache_invalidated) {
            return;
        }

        $this->cache_version = CacheVersion::bump(CacheVersion::AI);
        $this->cache_invalidated = true;
        $this->invalidate_scheduled = false;
    }

    public function resolve_request_kind(): ?string
    {
        $request_path = $this->relative_request_path();

        if ($request_path === null) {
            return null;
        }

        if ($this->uses_query_routes()) {
            if ($request_path !== '/') {
                return null;
            }

            $kind_value = get_query_var(self::QUERY_VAR, '');
            $kind = is_scalar($kind_value) ? sanitize_key((string) $kind_value) : '';

            return in_array($kind, self::KINDS, true) ? $kind : null;
        }

        $normalized = rtrim($request_path, '/');

        return match ($normalized) {
            '/ai.txt'           => 'ai',
            '/llms.txt'         => 'llms',
            '/llms-full.txt'    => 'llms_full',
            '/agents.md'        => 'agents',
            '/feed/ai-pulse.md' => 'pulse',
            default             => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        if ($this->configuration !== null) {
            return $this->configuration;
        }

        $settings = Settings::all();
        $ai = isset($settings['ai_agents']) && is_array($settings['ai_agents'])
            ? $settings['ai_agents']
            : (array) Settings::defaults()['ai_agents'];
        $this->configuration = $ai;

        return $this->configuration;
    }

    public function kind_enabled(string $kind): bool
    {
        $config = $this->config();

        return match ($kind) {
            'ai'        => ! empty($config['ai_txt']),
            'llms'      => ! empty($config['llms_txt']),
            'llms_full' => ! empty($config['llms_full']),
            'agents'    => ! empty($config['agents_md']),
            'pulse'     => ! empty($config['pulse_md']),
            default     => false,
        };
    }

    public function content_type(string $kind): string
    {
        return in_array($kind, ['agents', 'pulse'], true) ? 'text/markdown' : 'text/plain';
    }

    public function cache_key(string $kind): string
    {
        $blog_id = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 1;

        return 'dstk_ai_doc_' . $kind . '_' . $blog_id . '_' . $this->cache_version();
    }

    private function cache_version(): string
    {
        if ($this->cache_version !== null) {
            return $this->cache_version;
        }

        $this->cache_version = CacheVersion::get(CacheVersion::AI);

        return $this->cache_version;
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

    private function uses_query_routes(): bool
    {
        global $wp_rewrite;

        return (string) get_option('permalink_structure', '') === ''
            || (is_object($wp_rewrite) && method_exists($wp_rewrite, 'using_index_permalinks') && $wp_rewrite->using_index_permalinks());
    }
}
