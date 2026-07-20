<?php
/**
 * Plugin Name: Dr.Slon Toolkit
 * Plugin URI: https://github.com/A-Krivoshen/dr-slon-toolkit
 * Description: Модульный плагин WordPress для задач клиентских сайтов.
 * Version: 0.9.1
 * Author: Dr.Slon
 * Author URI: https://krivoshein.site
 * Text Domain: dr-slon-toolkit
 * Domain Path: /languages
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Update URI: https://github.com/A-Krivoshen/dr-slon-toolkit
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

const DSTK_VERSION = '0.9.1';
const DSTK_PLUGIN_FILE = __FILE__;
const DSTK_PLUGIN_DIR = __DIR__ . '/';

/**
 * Register class loading for the plugin.
 *
 * Production has no Composer runtime packages. Prefer vendor/autoload.php when
 * present (release ZIP / local dev), otherwise use a built-in PSR-4 loader so a
 * plain source tree still works without running Composer.
 */
$dstk_vendor_autoloader = DSTK_PLUGIN_DIR . 'vendor/autoload.php';

if (is_readable($dstk_vendor_autoloader)) {
    require_once $dstk_vendor_autoloader;
} else {
    spl_autoload_register(
        static function (string $class): void {
            $prefix = 'DrSlon\\Toolkit\\';

            if (! str_starts_with($class, $prefix)) {
                return;
            }

            $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
            $file     = DSTK_PLUGIN_DIR . 'src/' . $relative . '.php';

            if (is_readable($file)) {
                require_once $file;
            }
        }
    );
}

if (! is_readable(DSTK_PLUGIN_DIR . 'src/Core/Plugin.php')) {
    add_action(
        'admin_notices',
        static function (): void {
            if (! current_user_can('activate_plugins')) {
                return;
            }

            echo '<div class="notice notice-error"><p>';
            echo esc_html__(
                'Не удалось запустить Dr.Slon Toolkit: повреждена установка. Скачайте ZIP из GitHub Releases (не Code → Download ZIP) и установите заново.',
                'dr-slon-toolkit'
            );
            echo '</p></div>';
        }
    );

    return;
}

register_activation_hook(DSTK_PLUGIN_FILE, [\DrSlon\Toolkit\Core\Activator::class, 'activate']);
register_deactivation_hook(DSTK_PLUGIN_FILE, [\DrSlon\Toolkit\Core\Deactivator::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    load_plugin_textdomain('dr-slon-toolkit', false, dirname(plugin_basename(DSTK_PLUGIN_FILE)) . '/languages');

    $plugin = new \DrSlon\Toolkit\Core\Plugin();
    $plugin->boot();
});
