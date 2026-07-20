<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Modules;

use DrSlon\Toolkit\Core\ModuleInterface;
use DrSlon\Toolkit\Core\Settings;
use DrSlon\Toolkit\Integrations\SeoFrameworkDetector;
use WP_Post;

final class IndexNowModule implements ModuleInterface
{
    public const CRON_HOOK = 'dstk_indexnow_process_queue';
    public const QUEUE_LOCK_OPTION = 'dstk_indexnow_queue_lock';

    private const CACHE_OPTION = 'dstk_indexnow_cache';
    private const QUEUE_OPTION = 'dstk_indexnow_queue';
    private const STATUS_OPTION = 'dstk_indexnow_queue_status';
    private const KEY_QUERY_ARG = 'dstk_indexnow_key';
    private const CACHE_TTL = 600;
    private const CACHE_GC_TTL = 7200;
    private const QUEUE_DELAY = 5;
    private const QUEUE_LIMIT = 1000;
    private const BATCH_SIZE = 3;
    private const MAX_ATTEMPTS = 3;
    private const CLAIM_TTL = 120;

    /** @var array{key:string,endpoint:string,post_types:array<int,string>}|null */
    private ?array $configuration = null;

    /**
     * @var array<int,int>
     */
    private const RETRY_DELAYS = [60, 300];

    /**
     * @var array<int,string>
     */
    private const ALLOWED_ENDPOINTS = [
        'https://api.indexnow.org/indexnow',
        'https://www.bing.com/indexnow',
        'https://yandex.com/indexnow',
    ];

    public function register(): void
    {
        add_action('template_redirect', [$this, 'maybe_serve_key_file'], 0);
        add_action('wp_after_insert_post', [$this, 'handle_post_saved'], 20, 4);
        add_action('before_delete_post', [$this, 'handle_post_deleted'], 10, 2);
        add_action(self::CRON_HOOK, [$this, 'process_queue']);
        // Queue scheduling happens only from enqueue / process_queue — not on every front request.
    }

    public function maybe_serve_key_file(): void
    {
        if (is_admin()) {
            return;
        }

        $key = $this->config()['key'];

        if ($key === '') {
            return;
        }

        $request_method = isset($_SERVER['REQUEST_METHOD'])
            ? strtoupper(sanitize_key(wp_unslash((string) $_SERVER['REQUEST_METHOD'])))
            : 'GET';

        if (! in_array($request_method, ['GET', 'HEAD'], true)) {
            return;
        }

        $request_uri = isset($_SERVER['REQUEST_URI'])
            ? esc_url_raw(wp_unslash((string) $_SERVER['REQUEST_URI']))
            : '';
        $request_path = $this->normalize_url_path((string) wp_parse_url($request_uri, PHP_URL_PATH));
        $home_path = $this->normalize_url_path((string) wp_parse_url(home_url('/'), PHP_URL_PATH));

        if ($request_path === '' || $home_path === '') {
            return;
        }

        $key_path = ($home_path === '/' ? '' : rtrim($home_path, '/')) . '/' . $key . '.txt';
        // Public verification endpoint; no state changes and therefore no nonce.
        $query_key = isset($_GET[self::KEY_QUERY_ARG]) && is_string($_GET[self::KEY_QUERY_ARG]) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ? sanitize_text_field(wp_unslash($_GET[self::KEY_QUERY_ARG])) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            : '';
        $home_path_without_slash = rtrim($home_path, '/');
        $is_home_request = $request_path === $home_path
            || $request_path === ($home_path_without_slash === '' ? '/' : $home_path_without_slash);
        $is_query_route = $is_home_request && $query_key !== '' && hash_equals($key . '.txt', $query_key);

        if ($request_path !== $key_path && ! $is_query_route) {
            return;
        }

        nocache_headers();
        status_header(200);
        header('Content-Type: text/plain; charset=utf-8');
        if ($request_method === 'HEAD') {
            exit;
        }

        echo esc_html($key);
        exit;
    }

    public function handle_post_saved(int $post_id, WP_Post $post, bool $update, ?WP_Post $post_before): void
    {
        unset($update);

        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        $current_url = $this->post_url($post);
        $old_url = $post_before instanceof WP_Post ? $this->post_url($post_before) : '';
        $old_visible = $post_before instanceof WP_Post
            && $this->is_supported_post($post_before, (string) $post_before->post_status)
            && $old_url !== '';
        $reason = $old_visible && hash_equals($old_url, $current_url) ? 'update' : 'publish';
        $current_indexable = $current_url !== ''
            && $this->is_queued_url_eligible($current_url, $reason, $post_id);

        if ($old_visible && (! $current_indexable || ! hash_equals($old_url, $current_url))) {
            $this->enqueue_url($old_url, 'delete', $post_id);
        }

        if (! $current_indexable) {
            return;
        }

        $this->enqueue_url($current_url, $reason, $post_id);
    }

    public function handle_post_deleted(int $post_id, ?WP_Post $post = null): void
    {
        $post = $post instanceof WP_Post ? $post : get_post($post_id);

        if (! ($post instanceof WP_Post)) {
            return;
        }

        $viewable_status = $post->post_status;

        if ($viewable_status === 'trash') {
            $viewable_status = (string) get_post_meta($post_id, '_wp_trash_meta_status', true);
        }

        if ($viewable_status === '' || ! $this->is_supported_post($post, $viewable_status)) {
            return;
        }

        $url = $this->post_url($post);

        if ($url !== '') {
            $this->enqueue_url($url, 'delete', $post_id);
        }
    }

    public function submit_manual_url(string $url): array
    {
        $url = $this->normalize_site_url($url);

        if ($url === '') {
            return ['success' => false, 'message' => __('Укажите корректный URL текущего сайта.', 'dr-slon-toolkit')];
        }

        $result = $this->send_url($url, 'manual');

        return ['success' => $result['success'], 'message' => $result['message']];
    }

    /**
     * @return array{key:string,endpoint:string,post_types:array<int,string>}
     */
    private function config(): array
    {
        if ($this->configuration !== null) {
            return $this->configuration;
        }

        $settings = Settings::all();
        $indexnow = isset($settings['indexnow']) && is_array($settings['indexnow']) ? $settings['indexnow'] : [];

        $key = isset($indexnow['key']) ? (string) $indexnow['key'] : '';
        $endpoint = isset($indexnow['endpoint']) ? (string) $indexnow['endpoint'] : 'https://api.indexnow.org/indexnow';
        $post_types = isset($indexnow['post_types']) && is_array($indexnow['post_types']) ? $indexnow['post_types'] : ['post', 'page'];

        $this->configuration = [
            'key'        => $key,
            'endpoint'   => $this->normalize_endpoint($endpoint),
            'post_types' => array_map('sanitize_key', $post_types),
        ];

        return $this->configuration;
    }

    private function is_supported_post(WP_Post $post, string $viewable_status): bool
    {
        $config = $this->config();

        if (! in_array($post->post_type, $config['post_types'], true)) {
            return false;
        }

        if (trim($post->post_password) !== '') {
            return false;
        }

        $viewable_post = clone $post;
        $viewable_post->post_status = $viewable_status;

        return is_post_publicly_viewable($viewable_post);
    }

    private function post_url(WP_Post $post): string
    {
        $url = get_permalink($post);

        if (! is_string($url)) {
            return '';
        }

        return $this->normalize_site_url($url);
    }

    private function enqueue_url(string $url, string $reason, int $post_id): void
    {
        $url = $this->normalize_site_url($url);

        if ($url === '') {
            return;
        }

        if ($this->config()['key'] === '' || $this->is_duplicate_recent_submission($url, $reason)) {
            return;
        }

        $lock = $this->acquire_queue_lock();

        if ($lock === null) {
            $this->update_queue_status(
                [
                    'last_failure' => time(),
                    'last_error'   => __('Очередь IndexNow временно занята. Повторите сохранение записи.', 'dr-slon-toolkit'),
                ],
                0,
                1
            );
            return;
        }

        try {
            $queue = $this->queue();
            $queue_id = $this->queue_id($url, $reason);

            if (! isset($queue[$queue_id])) {
                if (count($queue) >= self::QUEUE_LIMIT) {
                    $this->update_queue_status(
                        [
                            'queued'       => count($queue),
                            'last_failure' => time(),
                            'last_error'   => __('Очередь IndexNow заполнена. URL не был добавлен.', 'dr-slon-toolkit'),
                        ],
                        0,
                        1
                    );
                    return;
                }

                $now = time();
                $queue[$queue_id] = [
                    'url'          => $url,
                    'reason'       => $reason,
                    'post_id'      => $post_id,
                    'attempts'     => 0,
                    'next_attempt' => $now,
                    'created_at'   => $now,
                    'last_error'   => '',
                ];

                update_option(self::QUEUE_OPTION, $queue, false);
            }

            $this->update_queue_status(['queued' => count($queue)]);
        } finally {
            $this->release_queue_lock($lock);
        }

        $this->schedule_queue($queue);
    }

    public function process_queue(): void
    {
        $claim = $this->claim_due_entries();
        $snapshot = $claim['entries'];
        $now = time();

        if ($snapshot === []) {
            $queue = $this->queue();
            $this->update_queue_status(['queued' => count($queue), 'last_run' => $now]);

            if ($queue !== []) {
                $this->schedule_queue($queue);
            }
            return;
        }

        $updates = [];
        $processed = 0;
        $failed = 0;
        $dropped = 0;
        $last_success = 0;
        $last_error = '';
        $last_failed_url = '';
        $last_failed_reason = '';
        $last_failed_attempts = 0;

        foreach ($snapshot as $queue_id => $entry) {
            if (! is_array($entry)) {
                $updates[$queue_id] = null;
                ++$processed;
                ++$failed;
                $last_error = __('Поврежденная запись удалена из очереди IndexNow.', 'dr-slon-toolkit');
                continue;
            }

            ++$processed;

            $url = isset($entry['url']) ? $this->normalize_site_url((string) $entry['url']) : '';
            $reason = isset($entry['reason']) ? (string) $entry['reason'] : 'update';
            $post_id = isset($entry['post_id']) ? max(0, (int) $entry['post_id']) : 0;
            $attempts = isset($entry['attempts']) ? max(0, (int) $entry['attempts']) : 0;

            if (! in_array($reason, ['publish', 'update', 'trash', 'delete'], true)) {
                $reason = 'update';
            }

            if ($url === '' || $attempts >= self::MAX_ATTEMPTS) {
                $updates[$queue_id] = null;
                ++$failed;
                $last_error = __('Некорректная запись удалена из очереди IndexNow.', 'dr-slon-toolkit');
                $last_failed_url = $url;
                $last_failed_reason = $reason;
                $last_failed_attempts = $attempts;
                continue;
            }

            if (! $this->is_queued_url_eligible($url, $reason, $post_id)) {
                $updates[$queue_id] = null;
                ++$dropped;
                continue;
            }

            $result = $this->send_url($url, $reason);

            if ($result['success']) {
                $updates[$queue_id] = null;
                $last_success = time();
                continue;
            }

            ++$attempts;
            $last_error = $result['message'];
            $last_failed_url = $url;
            $last_failed_reason = $reason;
            $last_failed_attempts = $attempts;

            if (! $result['retryable'] || $attempts >= self::MAX_ATTEMPTS) {
                $updates[$queue_id] = null;
                ++$failed;
                continue;
            }

            $delay_index = min($attempts - 1, count(self::RETRY_DELAYS) - 1);
            $entry['url'] = $url;
            $entry['reason'] = $reason;
            $entry['post_id'] = $post_id;
            $entry['attempts'] = $attempts;
            $entry['next_attempt'] = $now + self::RETRY_DELAYS[$delay_index];
            $entry['last_error'] = $result['message'];
            $updates[$queue_id] = $entry;
        }

        $queue = $this->finish_claim($claim['token'], $updates);

        $status = [
            'queued'   => count($queue),
            'last_run' => time(),
        ];

        if ($last_success > 0) {
            $status['last_success'] = $last_success;
        }

        if ($last_error !== '') {
            $status['last_failure'] = time();
            $status['last_error'] = $last_error;
            $status['last_failed_url'] = $last_failed_url;
            $status['last_failed_reason'] = $last_failed_reason;
            $status['last_failed_attempts'] = $last_failed_attempts;
        } elseif ($processed > 0) {
            $status['last_error'] = '';
        }

        $this->update_queue_status($status, $failed, $dropped);

        if ($queue !== []) {
            $this->schedule_queue($queue);
        }
    }

    /**
     * @return array{success:bool,message:string,retryable:bool}
     */
    private function send_url(string $url, string $reason): array
    {
        $url = $this->normalize_site_url($url);

        if ($url === '') {
            return [
                'success'   => false,
                'message'   => __('URL IndexNow не принадлежит текущему сайту.', 'dr-slon-toolkit'),
                'retryable' => false,
            ];
        }

        $config = $this->config();

        if ($config['key'] === '') {
            return [
                'success'   => false,
                'message'   => __('Ключ IndexNow не задан.', 'dr-slon-toolkit'),
                'retryable' => false,
            ];
        }

        if ($this->is_duplicate_recent_submission($url, $reason)) {
            return [
                'success'   => true,
                'message'   => __('URL уже недавно отправлялся, повтор пропущен.', 'dr-slon-toolkit'),
                'retryable' => false,
            ];
        }

        $payload = [
            'host'        => (string) wp_parse_url(home_url('/'), PHP_URL_HOST),
            'key'         => $config['key'],
            'keyLocation' => self::key_location_url($config['key']),
            'urlList'     => [$url],
        ];

        $response = wp_safe_remote_post(
            $config['endpoint'],
            [
                'timeout'     => 8,
                'redirection' => 2,
                'headers'     => [
                    'Content-Type' => 'application/json; charset=utf-8',
                ],
                'body'        => wp_json_encode($payload),
            ]
        );

        if (is_wp_error($response)) {
            return [
                'success'   => false,
                'message'   => sprintf(__('Ошибка отправки IndexNow: %s', 'dr-slon-toolkit'), $response->get_error_message()),
                'retryable' => true,
            ];
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);

        if (! in_array($status_code, [200, 202], true)) {
            return [
                'success'   => false,
                'message'   => sprintf(__('IndexNow вернул код %d.', 'dr-slon-toolkit'), $status_code),
                'retryable' => in_array($status_code, [408, 425, 429], true) || $status_code >= 500,
            ];
        }

        $this->store_submission_mark($url, $reason);

        return [
            'success'   => true,
            'message'   => __('URL успешно отправлен в IndexNow.', 'dr-slon-toolkit'),
            'retryable' => false,
        ];
    }

    private function normalize_site_url(string $url): string
    {
        $url = trim($url);

        if (preg_match('#^https?://#i', $url) !== 1) {
            return '';
        }

        $url = esc_url_raw($url, ['http', 'https']);

        if ($url === '') {
            return '';
        }

        $parts = wp_parse_url($url);
        $home_parts = wp_parse_url(home_url('/'));

        if (! is_array($parts) || ! is_array($home_parts)) {
            return '';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $home_scheme = strtolower((string) ($home_parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $home_host = strtolower((string) ($home_parts['host'] ?? ''));

        if ($scheme === '' || $scheme !== $home_scheme || $host === '' || $host !== $home_host) {
            return '';
        }

        $port = $this->effective_port($parts);
        $home_port = $this->effective_port($home_parts);

        if ($port === 0 || $home_port === 0 || $port !== $home_port) {
            return '';
        }

        $path = $this->normalize_url_path((string) ($parts['path'] ?? '/'));
        $home_path = $this->normalize_url_path((string) ($home_parts['path'] ?? '/'));

        if ($path === '' || $home_path === '') {
            return '';
        }

        $home_scope = rtrim($home_path, '/');

        if ($home_scope !== '' && $path !== $home_scope && ! str_starts_with($path, $home_scope . '/')) {
            return '';
        }

        $origin_host = str_contains($home_host, ':') && ! str_starts_with($home_host, '[')
            ? '[' . $home_host . ']'
            : $home_host;
        $normalized = $home_scheme . '://' . $origin_host;

        if (isset($home_parts['port'])) {
            $normalized .= ':' . (int) $home_parts['port'];
        }

        $normalized .= $path;

        if (isset($parts['query']) && (string) $parts['query'] !== '') {
            $query = $this->normalize_percent_encoded_component((string) $parts['query']);

            if ($query === null) {
                return '';
            }

            $normalized .= '?' . $query;
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $parts
     */
    private function effective_port(array $parts): int
    {
        if (isset($parts['port'])) {
            $port = (int) $parts['port'];

            return $port >= 1 && $port <= 65535 ? $port : 0;
        }

        return strtolower((string) ($parts['scheme'] ?? '')) === 'https' ? 443 : 80;
    }

    private function normalize_url_path(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        if (! str_starts_with($path, '/') || preg_match('/%(?![0-9a-f]{2})/i', $path) === 1) {
            return '';
        }

        $trailing_slash = str_ends_with($path, '/');
        $segments = explode('/', $path);
        $normalized = [];

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            $decoded = rawurldecode($segment);

            if (preg_match('/[\x00-\x1f\x7f]/', $decoded) === 1 || str_contains($decoded, '/') || str_contains($decoded, '\\')) {
                return '';
            }

            if ($decoded === '.') {
                continue;
            }

            if ($decoded === '..') {
                if ($normalized === []) {
                    return '';
                }

                array_pop($normalized);
                continue;
            }

            $normalized[] = rawurlencode($decoded);
        }

        $path = '/' . implode('/', $normalized);

        if ($trailing_slash && $path !== '/') {
            $path .= '/';
        }

        return $path;
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

    private function is_queued_url_eligible(string $url, string $reason, int $post_id): bool
    {
        if ((int) get_option('blog_public', 1) !== 1) {
            return false;
        }

        $is_content_notification = ! in_array($reason, ['trash', 'delete'], true);

        if ($post_id < 1 && $is_content_notification && function_exists('url_to_postid')) {
            $post_id = (int) url_to_postid($url);
        }

        $post = $post_id > 0 ? get_post($post_id) : null;
        $post = $post instanceof WP_Post ? $post : null;
        $eligible = true;

        if ($is_content_notification) {
            $eligible = $post instanceof WP_Post
                && $this->is_supported_post($post, (string) $post->post_status);

            if ($eligible) {
                $current_url = $this->post_url($post);
                $eligible = $current_url !== '' && hash_equals($current_url, $url);
            }

            if ($eligible) {
                $eligible = ! (bool) apply_filters('dstk_sitemap_is_noindex', false, $post)
                    && (new SeoFrameworkDetector())->is_post_indexable($post_id, $url);
            }
        } elseif ($post instanceof WP_Post && $this->is_supported_post($post, (string) $post->post_status)) {
            $current_url = $this->post_url($post);

            if ($current_url !== '' && hash_equals($current_url, $url)) {
                $eligible = false;
            }
        }

        /**
         * Filters automatic IndexNow eligibility after current post state is checked.
         *
         * @param bool         $should_submit Whether the URL should be submitted.
         * @param string       $url           Normalized URL queued for submission.
         * @param string       $reason        Queue reason.
         * @param WP_Post|null $post          Current post, when it still exists.
         */
        $filter_eligible = (bool) apply_filters('dstk_indexnow_should_submit', true, $url, $reason, $post);

        return $eligible && $filter_eligible;
    }

    private function is_duplicate_recent_submission(string $url, string $reason): bool
    {
        $cache = get_option(self::CACHE_OPTION, []);
        $changed = false;

        if (! is_array($cache)) {
            $cache = [];
            $changed = true;
        }

        $now = time();
        $hash = $this->submission_hash($url);
        $state = $this->submission_state($reason);

        foreach ($cache as $key => $entry) {
            $timestamp = is_array($entry) ? ($entry['timestamp'] ?? null) : $entry;

            if (! is_string($key) || ! is_numeric($timestamp)) {
                unset($cache[$key]);
                $changed = true;
                continue;
            }

            if (($now - (int) $timestamp) > self::CACHE_GC_TTL) {
                unset($cache[$key]);
                $changed = true;
            }
        }

        if ($changed) {
            update_option(self::CACHE_OPTION, $cache, false);
        }

        if (isset($cache[$hash])) {
            $entry = $cache[$hash];

            if (is_array($entry)) {
                return ($entry['state'] ?? '') === $state
                    && isset($entry['timestamp'])
                    && (($now - (int) $entry['timestamp']) < self::CACHE_TTL);
            }
        }

        // Version 0.8.x stored md5($url) timestamps for content notifications.
        $legacy_hash = md5($url);

        return $state === 'content'
            && isset($cache[$legacy_hash])
            && is_numeric($cache[$legacy_hash])
            && (($now - (int) $cache[$legacy_hash]) < self::CACHE_TTL);
    }

    private function store_submission_mark(string $url, string $reason): void
    {
        $cache = get_option(self::CACHE_OPTION, []);

        if (! is_array($cache)) {
            $cache = [];
        }

        $cache[$this->submission_hash($url)] = [
            'state'     => $this->submission_state($reason),
            'timestamp' => time(),
        ];

        update_option(self::CACHE_OPTION, $cache, false);
    }

    private function submission_hash(string $url): string
    {
        return md5($url);
    }

    private function queue_id(string $url, string $reason): string
    {
        return md5($this->submission_state($reason) . "\n" . $url);
    }

    private function submission_state(string $reason): string
    {
        return in_array($reason, ['trash', 'delete'], true) ? 'terminal' : 'content';
    }

    public static function key_location_url(string $key): string
    {
        global $wp_rewrite;

        if (
            (string) get_option('permalink_structure', '') === ''
            || (is_object($wp_rewrite) && method_exists($wp_rewrite, 'using_index_permalinks') && $wp_rewrite->using_index_permalinks())
        ) {
            return (string) add_query_arg(self::KEY_QUERY_ARG, $key . '.txt', home_url('/'));
        }

        return home_url('/' . $key . '.txt');
    }

    /**
     * @return array<string,mixed>
     */
    private function queue(): array
    {
        $queue = get_option(self::QUEUE_OPTION, []);

        return is_array($queue) ? $queue : [];
    }

    /**
     * @return array{token:string,entries:array<string,mixed>}
     */
    private function claim_due_entries(): array
    {
        $lock = $this->acquire_queue_lock();

        if ($lock === null) {
            return ['token' => '', 'entries' => []];
        }

        try {
            $queue = $this->queue();
            $entries = [];
            $claim_token = wp_generate_uuid4();
            $now = time();
            $changed = false;

            foreach ($queue as $queue_id => $entry) {
                if (! is_array($entry)) {
                    unset($queue[$queue_id]);
                    $changed = true;
                    continue;
                }

                $claim_until = isset($entry['claim_until']) ? (int) $entry['claim_until'] : 0;
                $next_attempt = isset($entry['next_attempt']) ? (int) $entry['next_attempt'] : 0;

                if ($claim_until > $now || $next_attempt > $now) {
                    continue;
                }

                $entry['claim_token'] = $claim_token;
                $entry['claim_until'] = $now + self::CLAIM_TTL;
                $queue[$queue_id] = $entry;
                $entries[$queue_id] = $entry;
                $changed = true;

                if (count($entries) >= self::BATCH_SIZE) {
                    break;
                }
            }

            if ($changed) {
                update_option(self::QUEUE_OPTION, $queue, false);
            }

            return [
                'token'   => $entries === [] ? '' : $claim_token,
                'entries' => $entries,
            ];
        } finally {
            $this->release_queue_lock($lock);
        }
    }

    /**
     * @param array<string,array<string,mixed>|null> $updates
     * @return array<string,mixed>
     */
    private function finish_claim(string $claim_token, array $updates): array
    {
        if ($claim_token === '') {
            return $this->queue();
        }

        $lock = $this->acquire_queue_lock();

        if ($lock === null) {
            return $this->queue();
        }

        try {
            $queue = $this->queue();

            foreach ($updates as $queue_id => $entry) {
                if (
                    ! isset($queue[$queue_id])
                    || ! is_array($queue[$queue_id])
                    || ($queue[$queue_id]['claim_token'] ?? '') !== $claim_token
                ) {
                    continue;
                }

                if ($entry === null) {
                    unset($queue[$queue_id]);
                    continue;
                }

                unset($entry['claim_token'], $entry['claim_until']);
                $queue[$queue_id] = $entry;
            }

            update_option(self::QUEUE_OPTION, $queue, false);

            return $queue;
        } finally {
            $this->release_queue_lock($lock);
        }
    }

    private function acquire_queue_lock(): ?string
    {
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            $token = wp_generate_uuid4();
            $lock = [
                'token'   => $token,
                'expires' => time() + 10,
            ];

            if (add_option(self::QUEUE_LOCK_OPTION, $lock, '', false)) {
                return $token;
            }

            $existing = get_option(self::QUEUE_LOCK_OPTION, []);

            if (is_array($existing) && (int) ($existing['expires'] ?? 0) < time()) {
                delete_option(self::QUEUE_LOCK_OPTION);
                continue;
            }

            usleep(20000);
        }

        return null;
    }

    private function release_queue_lock(string $token): void
    {
        $lock = get_option(self::QUEUE_LOCK_OPTION, []);

        if (is_array($lock) && isset($lock['token']) && hash_equals($token, (string) $lock['token'])) {
            delete_option(self::QUEUE_LOCK_OPTION);
        }
    }

    /**
     * @param array<string,mixed> $queue
     */
    private function schedule_queue(array $queue): void
    {
        if ($queue === []) {
            return;
        }

        $now = time();
        $earliest = PHP_INT_MAX;

        foreach ($queue as $entry) {
            $next_attempt = is_array($entry) && isset($entry['next_attempt']) ? (int) $entry['next_attempt'] : $now;
            $claim_until = is_array($entry) && isset($entry['claim_until']) ? (int) $entry['claim_until'] : 0;
            $next_attempt = max($next_attempt, $claim_until);
            $earliest = min($earliest, $next_attempt);
        }

        $timestamp = max($now + self::QUEUE_DELAY, $earliest);
        $scheduled = wp_next_scheduled(self::CRON_HOOK);

        if ($scheduled !== false && (int) $scheduled <= $timestamp) {
            return;
        }

        if ($scheduled !== false) {
            $unscheduled = wp_unschedule_event((int) $scheduled, self::CRON_HOOK, [], true);

            if (is_wp_error($unscheduled) || $unscheduled === false) {
                $message = is_wp_error($unscheduled)
                    ? $unscheduled->get_error_message()
                    : __('Не удалось перенести задачу IndexNow.', 'dr-slon-toolkit');
                $this->update_queue_status(['last_error' => $message]);
                return;
            }
        }

        $result = wp_schedule_single_event($timestamp, self::CRON_HOOK, [], true);

        if (is_wp_error($result) || $result === false) {
            $message = is_wp_error($result)
                ? $result->get_error_message()
                : __('Не удалось запланировать задачу IndexNow.', 'dr-slon-toolkit');
            $this->update_queue_status(['last_error' => $message]);
        }
    }

    /**
     * @param array<string,int|string> $changes
     */
    private function update_queue_status(array $changes, int $failed = 0, int $dropped = 0): void
    {
        $status = get_option(self::STATUS_OPTION, []);
        $status = is_array($status) ? $status : [];
        $status = array_merge(
            [
                'queued'               => 0,
                'last_run'             => 0,
                'last_success'         => 0,
                'last_failure'         => 0,
                'last_error'           => '',
                'last_failed_url'      => '',
                'last_failed_reason'   => '',
                'last_failed_attempts' => 0,
                'failed'               => 0,
                'dropped'              => 0,
            ],
            $status
        );

        $status['failed'] = (int) $status['failed'] + $failed;
        $status['dropped'] = (int) $status['dropped'] + $dropped;

        foreach ($changes as $key => $value) {
            $status[$key] = $value;
        }

        update_option(self::STATUS_OPTION, $status, false);
    }

    private function normalize_endpoint(string $endpoint): string
    {
        $endpoint = esc_url_raw($endpoint);

        if (! in_array($endpoint, self::ALLOWED_ENDPOINTS, true)) {
            return self::ALLOWED_ENDPOINTS[0];
        }

        return $endpoint;
    }
}
