<?php

declare(strict_types=1);

if (! defined('DSTK_VERSION')) {
    define('DSTK_VERSION', 'test');
}

if (! class_exists('WP_Post')) {
    class WP_Post
    {
        public int $ID;
        public string $post_name;
        public string $post_status;

        public function __construct(object $post)
        {
            $this->ID = (int) ($post->ID ?? 0);
            $this->post_name = (string) ($post->post_name ?? '');
            $this->post_status = (string) ($post->post_status ?? 'publish');
        }
    }
}

if (! class_exists('DSTK_WP_Die_Exception')) {
    class DSTK_WP_Die_Exception extends RuntimeException
    {
    }
}

if (! function_exists('dstk_reset_rewrite_test_state')) {
    function dstk_reset_rewrite_test_state(): void
    {
        $GLOBALS['dstk_test_options'] = [];
        $GLOBALS['dstk_test_blog_options'] = [1 => []];
        $GLOBALS['dstk_test_option_events'] = [];
        $GLOBALS['dstk_test_actions'] = [];
        $GLOBALS['dstk_test_filters'] = [];
        $GLOBALS['dstk_test_flushes'] = [];
        $GLOBALS['dstk_test_site_queries'] = [];
        $GLOBALS['dstk_test_sites_by_network'] = [];
        $GLOBALS['dstk_test_network_id'] = 1;
        $GLOBALS['dstk_test_is_multisite'] = false;
        $GLOBALS['dstk_test_current_blog_id'] = 1;
        $GLOBALS['dstk_test_blog_stack'] = [];
        $GLOBALS['dstk_test_blog_switches'] = [];
        $GLOBALS['dstk_test_site_url'] = 'https://example.test';
        $GLOBALS['dstk_test_home_url'] = 'https://example.test';
        $GLOBALS['dstk_test_recovery_mode'] = false;
        $GLOBALS['dstk_test_status_header'] = null;
        $GLOBALS['dstk_test_nocache_headers'] = 0;
        $GLOBALS['dstk_test_wp_die'] = null;
        $GLOBALS['dstk_test_cleared_hooks'] = [];
        $GLOBALS['wp_rewrite'] = (object) ['root' => ''];
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
    }
}

if (! function_exists('update_option')) {
    function update_option(string $option, mixed $value, mixed $autoload = null): bool
    {
        $GLOBALS['dstk_test_options'][$option] = $value;
        $GLOBALS['dstk_test_option_events'][] = [
            'operation' => 'update',
            'blog_id'   => $GLOBALS['dstk_test_current_blog_id'],
            'option'    => $option,
            'value'     => $value,
            'autoload'  => $autoload,
        ];

        return true;
    }
}

if (! function_exists('add_option')) {
    function add_option(string $option, mixed $value = '', string $deprecated = '', mixed $autoload = 'yes'): bool
    {
        unset($deprecated);

        if (array_key_exists($option, $GLOBALS['dstk_test_options'])) {
            return false;
        }

        $GLOBALS['dstk_test_options'][$option] = $value;
        $GLOBALS['dstk_test_option_events'][] = [
            'operation' => 'add',
            'blog_id'   => $GLOBALS['dstk_test_current_blog_id'],
            'option'    => $option,
            'value'     => $value,
            'autoload'  => $autoload,
        ];

        return true;
    }
}

if (! function_exists('delete_option')) {
    function delete_option(string $option): bool
    {
        $existed = array_key_exists($option, $GLOBALS['dstk_test_options']);
        unset($GLOBALS['dstk_test_options'][$option]);
        $GLOBALS['dstk_test_option_events'][] = [
            'operation' => 'delete',
            'blog_id'   => $GLOBALS['dstk_test_current_blog_id'],
            'option'    => $option,
        ];

        return $existed;
    }
}

if (! function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $GLOBALS['dstk_test_actions'][$hook][] = [$callback, $priority, $accepted_args];

        return true;
    }
}

if (! function_exists('add_filter')) {
    function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $GLOBALS['dstk_test_filters'][$hook][] = [$callback, $priority, $accepted_args];

        return true;
    }
}

if (! function_exists('site_url')) {
    function site_url(string $path = '', ?string $scheme = null): string
    {
        unset($scheme);

        return rtrim($GLOBALS['dstk_test_site_url'], '/') . ($path === '' ? '' : '/' . ltrim($path, '/'));
    }
}

if (! function_exists('home_url')) {
    function home_url(string $path = '', ?string $scheme = null): string
    {
        unset($scheme);

        return rtrim($GLOBALS['dstk_test_home_url'], '/') . ($path === '' ? '' : '/' . ltrim($path, '/'));
    }
}

if (! function_exists('user_trailingslashit')) {
    function user_trailingslashit(string $url, string $type_of_url = ''): string
    {
        unset($type_of_url);

        return rtrim($url, '/') . '/';
    }
}

if (! function_exists('untrailingslashit')) {
    function untrailingslashit(string $value): string
    {
        return rtrim($value, '/\\');
    }
}

if (! function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1): mixed
    {
        return parse_url($url, $component);
    }
}

if (! function_exists('wp_parse_str')) {
    function wp_parse_str(string $input_string, array &$result): void
    {
        parse_str($input_string, $result);
    }
}

if (! function_exists('add_query_arg')) {
    function add_query_arg(mixed $key, mixed $value = null, mixed $url = null): string
    {
        if (is_array($key)) {
            $args = $key;
            $target = (string) $value;
        } else {
            $args = [(string) $key => $value];
            $target = (string) $url;
        }

        $fragment = '';
        $fragment_position = strpos($target, '#');

        if ($fragment_position !== false) {
            $fragment = substr($target, $fragment_position);
            $target = substr($target, 0, $fragment_position);
        }

        $query = [];
        $query_position = strpos($target, '?');

        if ($query_position !== false) {
            parse_str(substr($target, $query_position + 1), $query);
            $target = substr($target, 0, $query_position);
        }

        foreach ($args as $name => $argument) {
            if ($argument === false || $argument === null) {
                unset($query[$name]);
                continue;
            }

            $query[$name] = (string) $argument;
        }

        $query_string = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $target . ($query_string === '' ? '' : '?' . $query_string) . $fragment;
    }
}

if (! function_exists('wp_validate_redirect')) {
    function wp_validate_redirect(string $location, string $fallback = ''): string
    {
        return preg_match('/[\x00-\x1F\x7F]/', $location) === 1 ? $fallback : $location;
    }
}

if (! function_exists('wp_check_invalid_utf8')) {
    function wp_check_invalid_utf8(string $text, bool $strip = false): string
    {
        unset($strip);

        return preg_match('//u', $text) === 1 ? $text : '';
    }
}

if (! function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map('wp_unslash', $value);
        }

        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (! function_exists('wp_is_recovery_mode')) {
    function wp_is_recovery_mode(): bool
    {
        return $GLOBALS['dstk_test_recovery_mode'];
    }
}

if (! function_exists('status_header')) {
    function status_header(int $code, string $description = ''): void
    {
        $GLOBALS['dstk_test_status_header'] = [$code, $description];
    }
}

if (! function_exists('nocache_headers')) {
    function nocache_headers(): void
    {
        ++$GLOBALS['dstk_test_nocache_headers'];
    }
}

if (! function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        unset($domain);

        return $text;
    }
}

if (! function_exists('wp_die')) {
    function wp_die(mixed $message = '', mixed $title = '', mixed $args = []): never
    {
        $GLOBALS['dstk_test_wp_die'] = [$message, $title, $args];

        throw new DSTK_WP_Die_Exception((string) $message);
    }
}

if (! function_exists('is_multisite')) {
    function is_multisite(): bool
    {
        return $GLOBALS['dstk_test_is_multisite'];
    }
}

if (! function_exists('get_current_network_id')) {
    function get_current_network_id(): int
    {
        return $GLOBALS['dstk_test_network_id'];
    }
}

if (! function_exists('get_sites')) {
    function get_sites(array $args = []): array
    {
        $GLOBALS['dstk_test_site_queries'][] = $args;
        $network_id = isset($args['network_id']) ? (int) $args['network_id'] : null;

        if ($network_id === null) {
            $site_ids = array_merge([], ...array_values($GLOBALS['dstk_test_sites_by_network']));
        } else {
            $site_ids = $GLOBALS['dstk_test_sites_by_network'][$network_id] ?? [];
        }

        return array_slice($site_ids, (int) ($args['offset'] ?? 0), (int) ($args['number'] ?? 100));
    }
}

if (! function_exists('switch_to_blog')) {
    function switch_to_blog(int $new_blog_id, ?bool $deprecated = null): bool
    {
        unset($deprecated);
        $current_blog_id = $GLOBALS['dstk_test_current_blog_id'];
        $GLOBALS['dstk_test_blog_options'][$current_blog_id] = $GLOBALS['dstk_test_options'];
        $GLOBALS['dstk_test_blog_stack'][] = $current_blog_id;
        $GLOBALS['dstk_test_current_blog_id'] = $new_blog_id;
        $GLOBALS['dstk_test_options'] = $GLOBALS['dstk_test_blog_options'][$new_blog_id] ?? [];
        $GLOBALS['dstk_test_blog_switches'][] = ['switch', $new_blog_id];

        return true;
    }
}

if (! function_exists('restore_current_blog')) {
    function restore_current_blog(): bool
    {
        if ($GLOBALS['dstk_test_blog_stack'] === []) {
            return false;
        }

        $current_blog_id = $GLOBALS['dstk_test_current_blog_id'];
        $GLOBALS['dstk_test_blog_options'][$current_blog_id] = $GLOBALS['dstk_test_options'];
        $restored_blog_id = array_pop($GLOBALS['dstk_test_blog_stack']);
        $GLOBALS['dstk_test_current_blog_id'] = $restored_blog_id;
        $GLOBALS['dstk_test_options'] = $GLOBALS['dstk_test_blog_options'][$restored_blog_id] ?? [];
        $GLOBALS['dstk_test_blog_switches'][] = ['restore', $restored_blog_id];

        return true;
    }
}

if (! function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook(string $hook, array $args = [], bool $wp_error = false): int
    {
        $GLOBALS['dstk_test_cleared_hooks'][] = [
            'blog_id' => $GLOBALS['dstk_test_current_blog_id'],
            'hook'    => $hook,
            'args'    => $args,
            'wp_error' => $wp_error,
        ];

        return 1;
    }
}

if (! function_exists('flush_rewrite_rules')) {
    function flush_rewrite_rules(bool $hard = true): void
    {
        $GLOBALS['dstk_test_flushes'][] = [
            'blog_id' => $GLOBALS['dstk_test_current_blog_id'],
            'hard'    => $hard,
        ];
    }
}
