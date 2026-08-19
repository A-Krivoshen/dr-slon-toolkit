<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Modules;

use DrSlon\Toolkit\Core\ModuleInterface;
use DrSlon\Toolkit\Core\Settings;
use WP_Error;
use WP_User;

final class LoginAttemptsModule implements ModuleInterface
{
    public const STORE_OPTION = 'dstk_login_lockouts';

    private const MAX_TRACKED = 500;

    public function register(): void
    {
        add_filter('authenticate', [$this, 'block_if_locked'], 4, 3);
        add_action('wp_login_failed', [$this, 'record_failure']);
        add_action('wp_login', [$this, 'clear_current']);
    }

    /**
     * @param null|WP_User|WP_Error $user
     * @return null|WP_User|WP_Error
     */
    public function block_if_locked($user, string $username, string $password)
    {
        unset($username, $password);

        if (! $this->is_locked()) {
            return $user;
        }

        return new WP_Error(
            'dstk_login_locked',
            sprintf(
                /* translators: %d: minutes remaining */
                __('Слишком много неудачных попыток входа. Подождите %d мин.', 'dr-slon-toolkit'),
                $this->minutes_remaining()
            )
        );
    }

    public function record_failure(string $username = ''): void
    {
        unset($username);

        $ip = $this->request_ip();

        if ($ip === '') {
            return;
        }

        $now = time();
        $config = $this->config();
        $store = $this->store();
        $key = $this->fingerprint($ip);
        $entry = isset($store[$key]) && is_array($store[$key]) ? $store[$key] : [];
        $first = isset($entry['first_failure']) ? (int) $entry['first_failure'] : $now;
        $locked_until = isset($entry['locked_until']) ? (int) $entry['locked_until'] : 0;

        if ($locked_until > $now) {
            $this->persist($store);

            return;
        }

        if ($locked_until > 0 || ($now - $first) > $config['window_seconds']) {
            $first = $now;
            $failures = 1;
        } else {
            $failures = isset($entry['failures']) ? (int) $entry['failures'] + 1 : 1;
        }

        $entry = [
            'failures'      => $failures,
            'first_failure' => $first,
            'locked_until'  => $failures >= $config['max_attempts'] ? $now + $config['lockout_seconds'] : 0,
        ];
        $store[$key] = $entry;
        $this->persist($store);
    }

    public function clear_current(): void
    {
        $this->clear_ip($this->request_ip());
    }

    public function clear_ip(string $ip): void
    {
        if ($ip === '') {
            return;
        }

        $store = $this->store();
        unset($store[$this->fingerprint($ip)]);
        $this->persist($store);
    }

    public function is_locked(?string $ip = null): bool
    {
        $ip = $ip ?? $this->request_ip();

        if ($ip === '') {
            return false;
        }

        $entry = $this->entry($ip);

        return isset($entry['locked_until']) && (int) $entry['locked_until'] > time();
    }

    public function minutes_remaining(?string $ip = null): int
    {
        $ip = $ip ?? $this->request_ip();
        $entry = $this->entry($ip);
        $until = isset($entry['locked_until']) ? (int) $entry['locked_until'] : 0;

        return $until > time() ? (int) max(1, (int) ceil(($until - time()) / 60)) : 0;
    }

    public function request_ip(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : '';

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }

    /**
     * @return array{max_attempts:int,window_seconds:int,lockout_seconds:int}
     */
    public function config(): array
    {
        $settings = Settings::all();
        $attempts = isset($settings['login_attempts']) && is_array($settings['login_attempts'])
            ? $settings['login_attempts']
            : [];

        $max = isset($attempts['max_attempts']) ? (int) $attempts['max_attempts'] : 5;
        $window = isset($attempts['window_minutes']) ? (int) $attempts['window_minutes'] : 15;
        $lockout = isset($attempts['lockout_minutes']) ? (int) $attempts['lockout_minutes'] : 15;

        return [
            'max_attempts'    => max(1, min(20, $max)),
            'window_seconds'  => max(60, min(86400, $window * 60)),
            'lockout_seconds' => max(60, min(86400, $lockout * 60)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function store(): array
    {
        $store = get_option(self::STORE_OPTION, []);

        return is_array($store) ? $store : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(string $ip): array
    {
        $store = $this->store();
        $entry = $store[$this->fingerprint($ip)] ?? [];

        return is_array($entry) ? $entry : [];
    }

    /**
     * @param array<string, mixed> $store
     */
    private function persist(array $store): void
    {
        $now = time();

        foreach ($store as $key => $entry) {
            if (! is_array($entry)) {
                unset($store[$key]);
                continue;
            }

            $locked_until = isset($entry['locked_until']) ? (int) $entry['locked_until'] : 0;
            $first = isset($entry['first_failure']) ? (int) $entry['first_failure'] : 0;

            if ($locked_until < $now && ($now - $first) > 86400) {
                unset($store[$key]);
            }
        }

        if (count($store) > self::MAX_TRACKED) {
            $store = array_slice($store, -self::MAX_TRACKED, null, true);
        }

        update_option(self::STORE_OPTION, $store, false);
    }

    public function fingerprint(string $ip): string
    {
        $salt = function_exists('wp_salt') ? (string) wp_salt('auth') : 'dstk';

        return hash('sha256', $ip . '|' . $salt);
    }
}
