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

        $config = $this->config();

        if ($config['mode'] === 'allow_all') {
            return $result;
        }

        $route = $this->normalize_route($request->get_route());

        if ($route === '') {
            return $result;
        }

        if ($this->is_allowed_by_route($route, $config) || $this->is_allowed_by_namespace($route, $config)) {
            return $result;
        }

        if ($config['mode'] === 'authenticated_only') {
            if (is_user_logged_in()) {
                return $result;
            }

            return $this->build_rest_error();
        }

        if ($config['mode'] === 'whitelist') {
            if ($this->user_has_trusted_capability($config['trusted_capability'])) {
                return $result;
            }

            return $this->build_rest_error();
        }

        return $result;
    }

    /**
     * @return array{mode:string, routes:array<int,string>, namespaces:array<int,string>, trusted_capability:string}
     */
    private function config(): array
    {
        $settings = Settings::all();
        $rest_api = isset($settings['rest_api']) && is_array($settings['rest_api']) ? $settings['rest_api'] : [];

        $mode = isset($rest_api['mode']) ? (string) $rest_api['mode'] : 'allow_all';
        $trusted_capability = isset($rest_api['trusted_capability']) ? sanitize_key((string) $rest_api['trusted_capability']) : 'edit_posts';

        $system_routes = $this->parse_lines(isset($rest_api['system_routes']) ? (string) $rest_api['system_routes'] : '');
        $whitelist_routes = $this->parse_lines(isset($rest_api['whitelist_routes']) ? (string) $rest_api['whitelist_routes'] : '');

        $system_namespaces = ['oembed/1.0'];
        $whitelist_namespaces = $this->parse_lines(isset($rest_api['whitelist_namespaces']) ? (string) $rest_api['whitelist_namespaces'] : '', false);

        return [
            'mode'               => in_array($mode, ['allow_all', 'authenticated_only', 'whitelist'], true) ? $mode : 'allow_all',
            'routes'             => array_values(array_unique(array_merge($system_routes, $whitelist_routes))),
            'namespaces'         => array_values(array_unique(array_merge($system_namespaces, $whitelist_namespaces))),
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

        $route = '/' . ltrim($route, '/');

        if ($route !== '/') {
            $route = rtrim($route, '/');
        }

        return $route;
    }

    /**
     * @param array{routes:array<int,string>} $config
     */
    private function is_allowed_by_route(string $route, array $config): bool
    {
        return in_array($route, $config['routes'], true);
    }

    /**
     * @param array{namespaces:array<int,string>} $config
     */
    private function is_allowed_by_namespace(string $route, array $config): bool
    {
        $namespace = $this->route_namespace($route);

        if ($namespace === '') {
            return false;
        }

        return in_array($namespace, $config['namespaces'], true);
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
            }

            if ($line === '') {
                continue;
            }

            $items[] = $line;
        }

        return array_values(array_unique($items));
    }
}
