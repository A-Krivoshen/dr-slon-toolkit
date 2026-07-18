<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Modules;

use DrSlon\Toolkit\Core\ModuleInterface;
use DrSlon\Toolkit\Core\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

final class RestApiControlModule implements ModuleInterface
{
    /**
     * Базовый встроенный allowlist, который нельзя случайно «сломать» правкой настроек.
     *
     * Суффикс * означает префиксное совпадение (без regex).
     *
     * @var array<int, string>
     */
    private const CORE_ROUTE_ALLOWLIST = [
        '/',
        '/oembed/1.0/embed',
        '/wp/v2/types',
        '/wp/v2/taxonomies',
        '/wp/v2/statuses',
        '/wp/v2/search',
        '/wp/v2/users/me',
        '/wp/v2/media*',
        '/wp/v2/posts*',
        '/wp/v2/pages*',
        '/wp/v2/block-renderer*',
        '/wp/v2/templates*',
        '/wp/v2/template-parts*',
    ];

    /**
     * @var array<int, string>
     */
    private const CORE_NAMESPACE_ALLOWLIST = [
        'oembed/1.0',
    ];

    public function register(): void
    {
        add_filter('rest_pre_dispatch', [$this, 'maybe_block_request'], 9, 3);
    }

    /**
     * @param mixed $result
     * @return mixed
     */
    public function maybe_block_request($result, WP_REST_Server $server, WP_REST_Request $request)
    {
        unset($server);

        if ($result instanceof WP_Error) {
            return $result;
        }

        if (strtoupper($request->get_method()) === 'OPTIONS') {
            return $result;
        }

        $config = $this->config();

        if ($config['mode'] === 'allow_all') {
            return $result;
        }

        $route = $this->normalize_route($request->get_route());

        if ($route === '') {
            return $result;
        }

        if ($config['mode'] === 'authenticated_only') {
            if ($this->is_allowed_by_route($route, $config['public_routes']) || $this->is_allowed_by_namespace($route, $config['public_namespaces'])) {
                return $result;
            }

            if (is_user_logged_in()) {
                return $result;
            }

            return $this->build_rest_error();
        }

        if ($config['mode'] === 'whitelist') {
            if ($this->is_allowed_by_route($route, $config['allowlist_routes']) || $this->is_allowed_by_namespace($route, $config['allowlist_namespaces'])) {
                return $result;
            }

            if ($this->user_has_trusted_capability($config['trusted_capability'])) {
                return $result;
            }

            return $this->build_rest_error();
        }

        return $result;
    }

    /**
     * @return array{
     *   mode:string,
     *   allowlist_routes:array<int,string>,
     *   allowlist_namespaces:array<int,string>,
     *   public_routes:array<int,string>,
     *   public_namespaces:array<int,string>,
     *   trusted_capability:string
     * }
     */
    private function config(): array
    {
        $settings = Settings::all();
        $rest_api = isset($settings['rest_api']) && is_array($settings['rest_api']) ? $settings['rest_api'] : [];

        $mode = isset($rest_api['mode']) ? (string) $rest_api['mode'] : 'allow_all';
        $trusted_capability = isset($rest_api['trusted_capability']) ? sanitize_key((string) $rest_api['trusted_capability']) : 'edit_posts';

        $additional_system_routes = $this->parse_lines(isset($rest_api['system_routes']) ? (string) $rest_api['system_routes'] : '');
        $whitelist_routes = $this->parse_lines(isset($rest_api['whitelist_routes']) ? (string) $rest_api['whitelist_routes'] : '');

        $core_routes = self::CORE_ROUTE_ALLOWLIST;
        $core_namespaces = self::CORE_NAMESPACE_ALLOWLIST;

        $public_routes = [
            '/',
            '/oembed/1.0/embed',
        ];

        $whitelist_namespaces = $this->parse_lines(isset($rest_api['whitelist_namespaces']) ? (string) $rest_api['whitelist_namespaces'] : '', false);

        return [
            'mode'               => in_array($mode, ['allow_all', 'authenticated_only', 'whitelist'], true) ? $mode : 'allow_all',
            'allowlist_routes'   => array_values(array_unique(array_merge($core_routes, $additional_system_routes, $whitelist_routes))),
            'allowlist_namespaces' => array_values(array_unique(array_merge($core_namespaces, $whitelist_namespaces))),
            'public_routes'      => array_values(array_unique($public_routes)),
            'public_namespaces'  => $core_namespaces,
            'trusted_capability' => $trusted_capability !== '' ? $trusted_capability : 'edit_posts',
        ];
    }

    private function build_rest_error(): WP_Error
    {
        $status = is_user_logged_in() ? 403 : 401;

        return new WP_Error(
            'dstk_rest_forbidden',
            __('Доступ к REST API ограничен настройками сайта.', 'dr-slon-toolkit'),
            ['status' => $status]
        );
    }

    private function normalize_route(string $route): string
    {
        $route = trim($route);

        if ($route === '') {
            return '';
        }

        $route = (string) wp_parse_url($route, PHP_URL_PATH);

        if ($route === '') {
            return '';
        }

        $route = '/' . ltrim($route, '/');

        if ($route !== '/') {
            $route = rtrim($route, '/');
        }

        return $route;
    }

    /**
     * @param array<int,string> $allowlist_routes
     */
    private function is_allowed_by_route(string $route, array $allowlist_routes): bool
    {
        foreach ($allowlist_routes as $allowed_route) {
            if ($allowed_route === $route) {
                return true;
            }

            if (str_ends_with($allowed_route, '*')) {
                $prefix = rtrim(substr($allowed_route, 0, -1), '/');

                if ($prefix === '') {
                    continue;
                }

                if ($route === $prefix || str_starts_with($route, $prefix . '/')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<int,string> $allowlist_namespaces
     */
    private function is_allowed_by_namespace(string $route, array $allowlist_namespaces): bool
    {
        $namespace = $this->route_namespace($route);

        if ($namespace === '') {
            return false;
        }

        return in_array($namespace, $allowlist_namespaces, true);
    }

    private function route_namespace(string $route): string
    {
        $path = trim($route, '/');

        if ($path === '') {
            return '';
        }

        $parts = explode('/', $path);

        if (count($parts) < 2) {
            return '';
        }

        return $parts[0] . '/' . $parts[1];
    }

    private function user_has_trusted_capability(string $capability): bool
    {
        if (! is_user_logged_in()) {
            return false;
        }

        return current_user_can($capability);
    }

    /**
     * @return array<int, string>
     */
    private function parse_lines(string $raw, bool $as_routes = true): array
    {
        $lines = preg_split('/[\r\n]+/', $raw) ?: [];
        $items = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);

            if ($line === '') {
                continue;
            }

            if ($as_routes) {
                $line = $this->normalize_route($line);
            } else {
                $line = trim($line, '/');
                $line = preg_replace('/[^a-z0-9_\\/-]/i', '', $line);

                if (! is_string($line)) {
                    continue;
                }
            }

            if ($line === '') {
                continue;
            }

            $items[] = $line;
        }

        return array_values(array_unique($items));
    }
}
