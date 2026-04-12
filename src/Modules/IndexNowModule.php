<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Modules;

use DrSlon\Toolkit\Core\ModuleInterface;
use DrSlon\Toolkit\Core\Settings;
use WP_Post;

final class IndexNowModule implements ModuleInterface
{
    private const CACHE_OPTION = 'dstk_indexnow_cache';

    public function register(): void
    {
        add_action('template_redirect', [$this, 'maybe_serve_key_file'], 0);
        add_action('transition_post_status', [$this, 'handle_post_status_transition'], 10, 3);
        add_action('wp_trash_post', [$this, 'handle_post_trashed']);
        add_action('before_delete_post', [$this, 'handle_post_deleted']);
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

        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $request_path = (string) wp_parse_url($request_uri, PHP_URL_PATH);
        $request_path = '/' . ltrim(trim($request_path), '/');

        if ($request_path !== '/' . $key . '.txt') {
            return;
        }

        nocache_headers();
        status_header(200);
        header('Content-Type: text/plain; charset=utf-8');
        echo esc_html($key);
        exit;
    }

    public function handle_post_status_transition(string $new_status, string $old_status, WP_Post $post): void
    {
        if (! $this->is_supported_post($post)) {
            return;
        }

        if ($new_status !== 'publish') {
            return;
        }

        if ($old_status === 'publish') {
            $this->submit_post_url($post->ID, 'update');
            return;
        }

        $this->submit_post_url($post->ID, 'publish');
    }

    public function handle_post_trashed(int $post_id): void
    {
        $post = get_post($post_id);

        if (! ($post instanceof WP_Post) || ! $this->is_supported_post($post)) {
            return;
        }

        $this->submit_post_url($post_id, 'trash');
    }

    public function handle_post_deleted(int $post_id): void
    {
        $post = get_post($post_id);

        if (! ($post instanceof WP_Post) || ! $this->is_supported_post($post)) {
            return;
        }

        $this->submit_post_url($post_id, 'delete');
    }

    public function submit_manual_url(string $url): array
    {
        $url = trim($url);

        if (! $this->is_valid_site_url($url)) {
            return ['success' => false, 'message' => __('Укажите корректный URL текущего сайта.', 'dr-slon-toolkit')];
        }

        return $this->send_url($url, 'manual');
    }

    /**
     * @return array{key:string,endpoint:string,post_types:array<int,string>}
     */
    private function config(): array
    {
        $settings = Settings::all();
        $indexnow = isset($settings['indexnow']) && is_array($settings['indexnow']) ? $settings['indexnow'] : [];

        $key = isset($indexnow['key']) ? (string) $indexnow['key'] : '';
        $endpoint = isset($indexnow['endpoint']) ? (string) $indexnow['endpoint'] : 'https://api.indexnow.org/indexnow';
        $post_types = isset($indexnow['post_types']) && is_array($indexnow['post_types']) ? $indexnow['post_types'] : ['post', 'page'];

        return [
            'key'       => $key,
            'endpoint'  => $endpoint,
            'post_types'=> array_map('sanitize_key', $post_types),
        ];
    }

    private function is_supported_post(WP_Post $post): bool
    {
        $config = $this->config();

        if (! in_array($post->post_type, $config['post_types'], true)) {
            return false;
        }

        if ($post->post_status !== 'publish') {
            return false;
        }

        if ((string) get_post_meta($post->ID, '_wp_attachment_metadata', true) !== '' && $post->post_type === 'attachment') {
            return false;
        }

        return true;
    }

    private function submit_post_url(int $post_id, string $reason): void
    {
        $url = get_permalink($post_id);

        if (! is_string($url) || $url === '' || ! $this->is_valid_site_url($url)) {
            return;
        }

        $this->send_url($url, $reason);
    }

    /**
     * @return array{success:bool,message:string}
     */
    private function send_url(string $url, string $reason): array
    {
        $config = $this->config();

        if ($config['key'] === '') {
            return ['success' => false, 'message' => __('Ключ IndexNow не задан.', 'dr-slon-toolkit')];
        }

        if ($this->is_duplicate_recent_submission($url, $reason)) {
            return ['success' => true, 'message' => __('URL уже недавно отправлялся, повтор пропущен.', 'dr-slon-toolkit')];
        }

        $payload = [
            'host'        => (string) wp_parse_url(home_url('/'), PHP_URL_HOST),
            'key'         => $config['key'],
            'keyLocation' => home_url('/' . $config['key'] . '.txt'),
            'urlList'     => [$url],
        ];

        $response = wp_remote_post(
            $config['endpoint'],
            [
                'timeout' => 8,
                'headers' => [
                    'Content-Type' => 'application/json; charset=utf-8',
                ],
                'body'    => wp_json_encode($payload),
            ]
        );

        if (is_wp_error($response)) {
            return ['success' => false, 'message' => sprintf(__('Ошибка отправки IndexNow: %s', 'dr-slon-toolkit'), $response->get_error_message())];
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);

        if (! in_array($status_code, [200, 202], true)) {
            return ['success' => false, 'message' => sprintf(__('IndexNow вернул код %d.', 'dr-slon-toolkit'), $status_code)];
        }

        $this->store_submission_mark($url, $reason);

        return ['success' => true, 'message' => __('URL успешно отправлен в IndexNow.', 'dr-slon-toolkit')];
    }

    private function is_valid_site_url(string $url): bool
    {
        $url = esc_url_raw($url);

        if ($url === '') {
            return false;
        }

        $url_host = (string) wp_parse_url($url, PHP_URL_HOST);
        $site_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);

        if ($url_host === '' || $site_host === '') {
            return false;
        }

        if (strcasecmp($url_host, $site_host) !== 0) {
            return false;
        }

        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }

    private function is_duplicate_recent_submission(string $url, string $reason): bool
    {
        $cache = get_option(self::CACHE_OPTION, []);

        if (! is_array($cache)) {
            $cache = [];
        }

        $now = time();
        $hash = md5($reason . '|' . $url);

        foreach ($cache as $key => $timestamp) {
            if (! is_string($key) || ! is_int($timestamp)) {
                unset($cache[$key]);
                continue;
            }

            if (($now - $timestamp) > 3600) {
                unset($cache[$key]);
            }
        }

        update_option(self::CACHE_OPTION, $cache, false);

        return isset($cache[$hash]) && (($now - $cache[$hash]) < 300);
    }

    private function store_submission_mark(string $url, string $reason): void
    {
        $cache = get_option(self::CACHE_OPTION, []);

        if (! is_array($cache)) {
            $cache = [];
        }

        $cache[md5($reason . '|' . $url)] = time();

        update_option(self::CACHE_OPTION, $cache, false);
    }
}
