<?php
/**
 * Plugin Name: Dr.Slon Toolkit
 * Plugin URI: https://github.com/A-Krivoshen/dr-slon-toolkit
 * Description: Modular WordPress toolkit for client websites.
 * Version: 0.1.0
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

define('DSTK_VERSION', '0.1.0');
define('DSTK_FILE', __FILE__);
define('DSTK_DIR', plugin_dir_path(__FILE__));
define('DSTK_URL', plugin_dir_url(__FILE__));

$dstk_autoloader = DSTK_DIR . 'vendor/autoload.php';

if (file_exists($dstk_autoloader)) {
    require_once $dstk_autoloader;
}

if (! class_exists(\DrSlon\Toolkit\Core\Plugin::class)) {
    require_once DSTK_DIR . 'src/Core/Plugin.php';
}

register_activation_hook(DSTK_FILE, static function (): void {
    if (get_option('dstk_settings', null) === null) {
        add_option('dstk_settings', []);
    }

    update_option('dstk_version', DSTK_VERSION);
});

register_deactivation_hook(DSTK_FILE, static function (): void {
    // Reserved for future cleanup tasks on deactivation.
});

add_action('plugins_loaded', static function (): void {
    load_plugin_textdomain(
        'dr-slon-toolkit',
        false,
        dirname(plugin_basename(DSTK_FILE)) . '/languages'
    );

    \DrSlon\Toolkit\Core\Plugin::instance()->boot();
});
