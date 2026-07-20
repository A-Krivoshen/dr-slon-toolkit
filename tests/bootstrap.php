<?php

declare(strict_types=1);

const HOUR_IN_SECONDS = 3600;
const DAY_IN_SECONDS = 86400;
const MINUTE_IN_SECONDS = 60;

if (! defined('DSTK_VERSION')) {
    define('DSTK_VERSION', 'test');
}

$GLOBALS['dstk_test_options'] = [];
$GLOBALS['dstk_test_query_vars'] = [];
$GLOBALS['dstk_test_filters'] = [];
$GLOBALS['dstk_test_posts'] = [];
$GLOBALS['dstk_test_post_urls'] = [];
$GLOBALS['dstk_test_url_post_ids'] = [];
$GLOBALS['dstk_test_home_url'] = 'https://example.test/';
$GLOBALS['dstk_test_wp_query_handler'] = null;
$GLOBALS['dstk_test_remote_posts'] = [];
$GLOBALS['dstk_test_settings_errors_calls'] = 0;

if (! class_exists('WP_Post')) {
    class WP_Post
    {
        public int $ID = 0;
        public string $post_type = 'post';
        public string $post_status = 'publish';
        public string $post_password = '';
        public string $post_modified_gmt = '0000-00-00 00:00:00';

        /** @param array<string,mixed>|object $data */
        public function __construct(array|object $data = [])
        {
            foreach ((array) $data as $key => $value) {
                if (property_exists($this, (string) $key)) {
                    $this->{$key} = $value;
                }
            }
        }
    }
}

if (! class_exists('WP_Query')) {
    class WP_Query
    {
        /** @var array<int,mixed> */
        public array $posts = [];
        public int $found_posts = 0;

        /** @param array<string,mixed> $args */
        public function __construct(array $args = [])
        {
            $handler = $GLOBALS['dstk_test_wp_query_handler'];

            if (! is_callable($handler)) {
                return;
            }

            $result = $handler($args);

            if (is_array($result) && array_key_exists('posts', $result)) {
                $this->posts = is_array($result['posts']) ? array_values($result['posts']) : [];
                $this->found_posts = isset($result['found_posts']) ? (int) $result['found_posts'] : count($this->posts);
                return;
            }

            $this->posts = is_array($result) ? array_values($result) : [];
            $this->found_posts = count($this->posts);
        }
    }
}
$GLOBALS['dstk_test_site_transients'] = [];
$GLOBALS['dstk_test_transient_expirations'] = [];
$GLOBALS['dstk_test_http_response'] = null;
$GLOBALS['dstk_test_http_calls'] = 0;
$GLOBALS['dstk_test_download_result'] = null;
$GLOBALS['dstk_test_deleted_files'] = [];

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        private string $code;
        private string $message;
        private mixed $data;

        public function __construct(string $code = '', string $message = '', mixed $data = null)
        {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function get_error_data(): mixed
        {
            return $this->data;
        }
    }
}

if (! function_exists('is_wp_error')) {
    function is_wp_error(mixed $value): bool
    {
        return $value instanceof WP_Error;
    }
}

if (! function_exists('plugin_basename')) {
    function plugin_basename(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        $marker = '/plugins/';
        $position = strpos($file, $marker);

        return $position === false ? basename($file) : substr($file, $position + strlen($marker));
    }
}

if (! function_exists('get_site_transient')) {
    function get_site_transient(string $name): mixed
    {
        return $GLOBALS['dstk_test_site_transients'][$name] ?? false;
    }
}

if (! function_exists('set_site_transient')) {
    function set_site_transient(string $name, mixed $value, int $expiration = 0): bool
    {
        $GLOBALS['dstk_test_site_transients'][$name] = $value;
        $GLOBALS['dstk_test_transient_expirations'][$name] = $expiration;
        return true;
    }
}

if (! function_exists('delete_site_transient')) {
    function delete_site_transient(string $name): bool
    {
        unset($GLOBALS['dstk_test_site_transients'][$name], $GLOBALS['dstk_test_transient_expirations'][$name]);
        return true;
    }
}

if (! function_exists('wp_safe_remote_get')) {
    function wp_safe_remote_get(string $url, array $arguments = []): mixed
    {
        unset($url, $arguments);
        ++$GLOBALS['dstk_test_http_calls'];
        return $GLOBALS['dstk_test_http_response'] ?? new WP_Error('http_error', 'HTTP unavailable');
    }
}

if (! function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code(mixed $response): int
    {
        return is_array($response) ? (int) ($response['response']['code'] ?? 0) : 0;
    }
}

if (! function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body(mixed $response): string
    {
        return is_array($response) ? (string) ($response['body'] ?? '') : '';
    }
}

if (! function_exists('wp_remote_retrieve_header')) {
    function wp_remote_retrieve_header(mixed $response, string $header): string
    {
        if (! is_array($response) || ! isset($response['headers']) || ! is_array($response['headers'])) {
            return '';
        }

        return (string) ($response['headers'][strtolower($header)] ?? '');
    }
}

if (! function_exists('download_url')) {
    function download_url(string $url, int $timeout = 300, bool $signature_verification = false): mixed
    {
        unset($url, $timeout, $signature_verification);
        return $GLOBALS['dstk_test_download_result'];
    }
}

if (! function_exists('wp_delete_file')) {
    function wp_delete_file(string $file): void
    {
        $GLOBALS['dstk_test_deleted_files'][] = $file;

        if (is_file($file)) {
            unlink($file);
        }
    }
}

if (! function_exists('WP_Filesystem')) {
    function WP_Filesystem(): bool
    {
        global $wp_filesystem;
        return is_object($wp_filesystem);
    }
}

if (! function_exists('untrailingslashit')) {
    function untrailingslashit(string $value): string
    {
        return rtrim($value, '/\\');
    }
}

if (! function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        unset($domain);
        return $text;
    }
}

if (! function_exists('get_option')) {
    function get_option(string $name, mixed $default = false): mixed
    {
        return $GLOBALS['dstk_test_options'][$name] ?? $default;
    }
}

if (! function_exists('update_option')) {
    function update_option(string $name, mixed $value, bool $autoload = true): bool
    {
        unset($autoload);
        $GLOBALS['dstk_test_options'][$name] = $value;

        return true;
    }
}

if (! function_exists('add_option')) {
    function add_option(string $name, mixed $value, string $deprecated = '', bool $autoload = true): bool
    {
        unset($deprecated, $autoload);

        if (array_key_exists($name, $GLOBALS['dstk_test_options'])) {
            return false;
        }

        $GLOBALS['dstk_test_options'][$name] = $value;

        return true;
    }
}

if (! function_exists('delete_option')) {
    function delete_option(string $name): bool
    {
        unset($GLOBALS['dstk_test_options'][$name]);

        return true;
    }
}

if (! function_exists('sanitize_key')) {
    function sanitize_key(string $value): string
    {
        return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)) ?? '';
    }
}

if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $value): string
    {
        return trim(strip_tags($value));
    }
}

if (! function_exists('sanitize_title_with_dashes')) {
    function sanitize_title_with_dashes(string $value, string $raw_title = '', string $context = 'display'): string
    {
        unset($raw_title, $context);
        $value = strtolower($value);
        $value = preg_replace_callback(
            '/[^\x00-\x7F]+/u',
            static function (array $matches): string {
                $encoded = '';

                foreach (str_split($matches[0]) as $byte) {
                    $encoded .= sprintf('%%%02x', ord($byte));
                }

                return $encoded;
            },
            $value
        ) ?? '';
        $value = preg_replace('/[^%a-z0-9-]+/', '-', $value) ?? '';

        return trim(preg_replace('/-+/', '-', $value) ?? '', '-');
    }
}

if (! function_exists('remove_accents')) {
    function remove_accents(string $value): string
    {
        return $value;
    }
}

if (! function_exists('esc_url_raw')) {
    function esc_url_raw(string $value, ?array $protocols = null): string
    {
        unset($protocols);

        if (str_starts_with($value, '/') && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_URL) ? $value : '';
    }
}

if (! function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        $home = rtrim((string) $GLOBALS['dstk_test_home_url'], '/');

        return $path === '' ? $home : $home . '/' . ltrim($path, '/');
    }
}

if (! function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1): array|string|int|null|false
    {
        return parse_url($url, $component);
    }
}

if (! function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed
    {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (! function_exists('untrailingslashit')) {
    function untrailingslashit(string $value): string
    {
        return rtrim($value, '/\\');
    }
}

if (! function_exists('get_query_var')) {
    function get_query_var(string $name, mixed $default = ''): mixed
    {
        return $GLOBALS['dstk_test_query_vars'][$name] ?? $default;
    }
}

if (! function_exists('add_query_arg')) {
    function add_query_arg(array $args, string $url): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . http_build_query($args, '', '&', PHP_QUERY_RFC3986);
    }
}

if (! function_exists('add_filter')) {
    function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $GLOBALS['dstk_test_filters'][$hook][$priority][] = [$callback, $accepted_args];

        return true;
    }
}

if (! function_exists('has_filter')) {
    function has_filter(string $hook): bool
    {
        return ! empty($GLOBALS['dstk_test_filters'][$hook]);
    }
}

if (! function_exists('remove_all_filters')) {
    function remove_all_filters(string $hook): bool
    {
        unset($GLOBALS['dstk_test_filters'][$hook]);

        return true;
    }
}

if (! function_exists('apply_filters')) {
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        $callbacks = $GLOBALS['dstk_test_filters'][$hook] ?? [];
        ksort($callbacks);

        foreach ($callbacks as $priority_callbacks) {
            foreach ($priority_callbacks as [$callback, $accepted_args]) {
                $parameters = array_slice([$value, ...$args], 0, $accepted_args);
                $value = $callback(...$parameters);
            }
        }

        return $value;
    }
}

if (! function_exists('__return_true')) {
    function __return_true(mixed ...$args): bool
    {
        unset($args);
        return true;
    }
}

if (! function_exists('__return_false')) {
    function __return_false(mixed ...$args): bool
    {
        unset($args);
        return false;
    }
}

if (! function_exists('get_post_type_object')) {
    function get_post_type_object(string $post_type): ?object
    {
        foreach (get_post_types([], 'objects') as $name => $object) {
            if ($name === $post_type) {
                return $object;
            }
        }

        return null;
    }
}

if (! function_exists('get_taxonomy')) {
    function get_taxonomy(string $taxonomy): object|false
    {
        foreach (get_taxonomies([], 'objects') as $name => $object) {
            if ($name === $taxonomy) {
                return $object;
            }
        }

        return false;
    }
}

if (! function_exists('get_post')) {
    function get_post(int $post_id): ?WP_Post
    {
        $post = $GLOBALS['dstk_test_posts'][$post_id] ?? null;

        return $post instanceof WP_Post ? $post : null;
    }
}

if (! function_exists('get_permalink')) {
    function get_permalink(WP_Post|int $post): string|false
    {
        $post_id = $post instanceof WP_Post ? $post->ID : $post;

        return $GLOBALS['dstk_test_post_urls'][$post_id] ?? false;
    }
}

if (! function_exists('url_to_postid')) {
    function url_to_postid(string $url): int
    {
        return (int) ($GLOBALS['dstk_test_url_post_ids'][$url] ?? 0);
    }
}

if (! function_exists('is_post_publicly_viewable')) {
    function is_post_publicly_viewable(WP_Post $post): bool
    {
        $object = get_post_type_object($post->post_type);

        return $post->post_status === 'publish'
            && $object !== null
            && is_post_type_viewable($object);
    }
}

if (! function_exists('mysql2date')) {
    function mysql2date(string $format, string $date, bool $translate = true): int|string|false
    {
        unset($translate);
        $timestamp = strtotime($date . ' UTC');

        return $format === 'U' ? $timestamp : ($timestamp === false ? false : gmdate($format, $timestamp));
    }
}

if (! function_exists('esc_xml')) {
    function esc_xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        unset($domain);
        return $text;
    }
}

if (! function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        unset($domain);
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = 'default'): string
    {
        unset($domain);
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return esc_url_raw($url);
    }
}

if (! function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        $allowed = $GLOBALS['dstk_test_user_capabilities'] ?? null;

        if (is_array($allowed)) {
            return in_array($capability, $allowed, true);
        }

        return true;
    }
}

if (! function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool
    {
        return ! empty($GLOBALS['dstk_test_user_logged_in']);
    }
}

if (! class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        private string $method;
        private string $route;

        public function __construct(string $method = 'GET', string $route = '/')
        {
            $this->method = $method;
            $this->route = $route;
        }

        public function get_method(): string
        {
            return $this->method;
        }

        public function get_route(): string
        {
            return $this->route;
        }
    }
}

if (! class_exists('WP_REST_Server')) {
    class WP_REST_Server
    {
    }
}

if (! function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'https://example.test/wp-admin/' . ltrim($path, '/');
    }
}

if (! function_exists('settings_errors')) {
    function settings_errors(string $setting = '', bool $sanitize = false, bool $hide_on_update = false): void
    {
        unset($setting, $sanitize, $hide_on_update);
        ++$GLOBALS['dstk_test_settings_errors_calls'];
        echo '<div data-test="settings-errors"></div>';
    }
}

if (! function_exists('settings_fields')) {
    function settings_fields(string $group): void
    {
        unset($group);
    }
}

if (! function_exists('do_settings_sections')) {
    function do_settings_sections(string $page): void
    {
        unset($page);
    }
}

if (! function_exists('submit_button')) {
    function submit_button(string $text = 'Save Changes', string $type = 'primary', string $name = 'submit', bool $wrap = true): void
    {
        unset($type, $name, $wrap);
        echo '<button>' . esc_html($text) . '</button>';
    }
}

if (! function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4(): string
    {
        static $counter = 0;
        ++$counter;

        return sprintf('00000000-0000-4000-8000-%012d', $counter);
    }
}

if (! function_exists('wp_remote_post')) {
    function wp_remote_post(string $url, array $args = []): array
    {
        $GLOBALS['dstk_test_remote_posts'][] = [$url, $args];

        return ['response' => ['code' => 202]];
    }
}

if (! function_exists('wp_safe_remote_post')) {
    function wp_safe_remote_post(string $url, array $args = []): array
    {
        return wp_remote_post($url, $args);
    }
}

if (! function_exists('did_action')) {
    function did_action(string $hook_name): int
    {
        return (int) ($GLOBALS['dstk_test_did_actions'][$hook_name] ?? 0);
    }
}

if (! function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code(array $response): int
    {
        return (int) ($response['response']['code'] ?? 0);
    }
}

if (! function_exists('is_wp_error')) {
    function is_wp_error(mixed $value): bool
    {
        return false;
    }
}

if (! function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $value): string|false
    {
        return json_encode($value);
    }
}

if (! function_exists('get_post_types')) {
    function get_post_types(array $args = [], string $output = 'names'): array
    {
        unset($args);
        $types = [
            'post'       => (object) ['name' => 'post', 'publicly_queryable' => true, 'public' => true],
            'page'       => (object) ['name' => 'page', 'publicly_queryable' => false, 'public' => true, '_builtin' => true],
            'attachment' => (object) ['name' => 'attachment', 'publicly_queryable' => true, 'public' => true],
        ];

        return $output === 'objects' ? $types : array_keys($types);
    }
}

if (! function_exists('is_post_type_viewable')) {
    function is_post_type_viewable(object $object): bool
    {
        return ! empty($object->publicly_queryable) || (! empty($object->_builtin) && ! empty($object->public));
    }
}

if (! function_exists('get_taxonomies')) {
    function get_taxonomies(array $args = [], string $output = 'names'): array
    {
        unset($args);
        $taxonomies = [
            'category' => (object) ['name' => 'category', 'publicly_queryable' => true, 'public' => true],
            'private'  => (object) ['name' => 'private', 'publicly_queryable' => false, 'public' => false],
        ];

        return $output === 'objects' ? $taxonomies : array_keys($taxonomies);
    }
}

if (! function_exists('is_taxonomy_viewable')) {
    function is_taxonomy_viewable(object $object): bool
    {
        return ! empty($object->publicly_queryable);
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';
