<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Modules;

use DrSlon\Toolkit\Core\ModuleInterface;
use DrSlon\Toolkit\Core\Settings;

final class UpdateControlsModule implements ModuleInterface
{
    public const CORE_ALL = 'all';
    public const CORE_MINOR = 'minor';
    public const CORE_SECURITY = 'security';
    public const CORE_OFF = 'off';

    public function register(): void
    {
        add_filter('allow_major_auto_core_updates', [$this, 'filter_allow_major_auto_core_updates']);
        add_filter('allow_minor_auto_core_updates', [$this, 'filter_allow_minor_auto_core_updates']);
        add_filter('allow_dev_auto_core_updates', [$this, 'filter_allow_dev_auto_core_updates']);
        add_filter('auto_update_core', [$this, 'filter_auto_update_core'], 10, 2);

        add_filter('auto_update_plugin', [$this, 'filter_auto_update_plugin'], 10, 2);
        add_filter('auto_update_theme', [$this, 'filter_auto_update_theme'], 10, 2);
        add_filter('auto_update_translation', [$this, 'filter_auto_update_translation'], 10, 2);

        add_filter('auto_core_update_send_email', [$this, 'filter_send_email'], 10, 4);
        add_filter('send_core_update_notification_email', [$this, 'filter_send_email'], 10, 2);
        add_filter('auto_plugin_update_send_email', [$this, 'filter_send_email'], 10, 2);
        add_filter('auto_theme_update_send_email', [$this, 'filter_send_email'], 10, 2);
        add_filter('automatic_updates_send_debug_email', [$this, 'filter_send_email'], 10, 1);
    }

    public function filter_allow_major_auto_core_updates(bool $allow): bool
    {
        $mode = $this->config()['core_mode'];

        if ($mode === self::CORE_ALL) {
            return true;
        }

        if ($mode === self::CORE_OFF) {
            return false;
        }

        return false;
    }

    public function filter_allow_minor_auto_core_updates(bool $allow): bool
    {
        $mode = $this->config()['core_mode'];

        if ($mode === self::CORE_OFF) {
            return false;
        }

        return in_array($mode, [self::CORE_ALL, self::CORE_MINOR, self::CORE_SECURITY], true);
    }

    public function filter_allow_dev_auto_core_updates(bool $allow): bool
    {
        $mode = $this->config()['core_mode'];

        return $mode === self::CORE_ALL;
    }

    /**
     * @param mixed $update
     * @param mixed $item
     */
    public function filter_auto_update_core($update, $item): bool
    {
        unset($item);

        $mode = $this->config()['core_mode'];

        if ($mode === self::CORE_OFF) {
            return false;
        }

        if ($mode === self::CORE_ALL) {
            return true;
        }

        if ($mode === self::CORE_MINOR || $mode === self::CORE_SECURITY) {
            return true;
        }

        return (bool) $update;
    }

    /**
     * @param mixed $update
     * @param mixed $item
     */
    public function filter_auto_update_plugin($update, $item): bool
    {
        unset($update, $item);

        return $this->config()['plugins'];
    }

    /**
     * @param mixed $update
     * @param mixed $item
     */
    public function filter_auto_update_theme($update, $item): bool
    {
        unset($update, $item);

        return $this->config()['themes'];
    }

    /**
     * @param mixed $update
     * @param mixed $item
     */
    public function filter_auto_update_translation($update, $item): bool
    {
        unset($update, $item);

        return $this->config()['translations'];
    }

    /**
     * @param mixed ...$args
     */
    public function filter_send_email(bool $send, ...$args): bool
    {
        return $this->config()['email_notifications'];
    }

    /**
     * @return array{core_mode:string,plugins:bool,themes:bool,translations:bool,email_notifications:bool}
     */
    private function config(): array
    {
        $settings = Settings::all();
        $update_controls = isset($settings['update_controls']) && is_array($settings['update_controls']) ? $settings['update_controls'] : [];

        $core_mode = isset($update_controls['core_mode']) ? sanitize_key((string) $update_controls['core_mode']) : self::CORE_MINOR;

        if (! in_array($core_mode, [self::CORE_ALL, self::CORE_MINOR, self::CORE_SECURITY, self::CORE_OFF], true)) {
            $core_mode = self::CORE_MINOR;
        }

        return [
            'core_mode'           => $core_mode,
            'plugins'             => ! array_key_exists('plugins', $update_controls) || ! empty($update_controls['plugins']),
            'themes'              => ! array_key_exists('themes', $update_controls) || ! empty($update_controls['themes']),
            'translations'        => ! array_key_exists('translations', $update_controls) || ! empty($update_controls['translations']),
            'email_notifications' => ! array_key_exists('email_notifications', $update_controls) || ! empty($update_controls['email_notifications']),
        ];
    }
}
