<?php
/**
 * Plugin Name: Dr.Slon Toolkit
 * Plugin URI: https://github.com/A-Krivoshen/dr-slon-toolkit
 * Description: Модульный плагин WordPress для задач клиентских сайтов.
 * Version: 0.7.1
 * Author: Dr.Slon
 * Author URI: https://krivoshein.site
 * Text Domain: dr-slon-toolkit
 * Domain Path: /languages
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

const DSTK_VERSION = '0.7.1';
const DSTK_PLUGIN_FILE = __FILE__;
const DSTK_PLUGIN_DIR = __DIR__ . '/';

$dstk_autoloader = DSTK_PLUGIN_DIR . 'vendor/autoload.php';

if (! is_readable($dstk_autoloader)) {
    add_action(
        'admin_notices',
        static function (): void {
            if (! current_user_can('activate_plugins')) {
                return;
            }

            echo '<div class="notice notice-error"><p>';
            echo esc_html__('Не удалось запустить Dr.Slon Toolkit: не найден автозагрузчик Composer. Выполните "composer install" в папке плагина.', 'dr-slon-toolkit');
            echo '</p></div>';
        }
    );

    return;
}

require_once $dstk_autoloader;

register_activation_hook(DSTK_PLUGIN_FILE, [\DrSlon\Toolkit\Core\Activator::class, 'activate']);

add_action('plugins_loaded', static function (): void {
    load_plugin_textdomain('dr-slon-toolkit', false, dirname(plugin_basename(DSTK_PLUGIN_FILE)) . '/languages');

    $plugin = new \DrSlon\Toolkit\Core\Plugin();
    $plugin->boot();
});
