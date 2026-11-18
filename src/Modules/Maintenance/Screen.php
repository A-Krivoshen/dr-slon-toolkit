<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Modules\Maintenance;

use DrSlon\Toolkit\Modules\MaintenanceModule;

final class Screen
{
    public static function markup(string $site_name, string $heading, string $message, string $language, string $version): string
    {
        $site_name = trim($site_name);
        $heading = trim($heading) !== '' ? trim($heading) : MaintenanceModule::DEFAULT_HEADING;
        $message = trim($message) !== '' ? trim($message) : MaintenanceModule::DEFAULT_MESSAGE;
        $language = trim($language) !== '' ? trim($language) : 'ru-RU';
        $title = $site_name !== '' ? $site_name . ' — ' . $heading : $heading;

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr($language); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="<?php echo esc_attr(MaintenanceModule::ROBOTS); ?>">
    <meta name="theme-color" content="#10140f">
    <title><?php echo esc_html($title); ?></title>
    <style><?php echo self::stylesheet(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static plugin stylesheet, angle brackets neutralized. ?></style>
</head>
<body>
    <div class="dstk-maint__glow" aria-hidden="true"></div>
    <div class="dstk-maint__grain" aria-hidden="true"></div>
    <main class="dstk-maint">
        <div class="dstk-maint__sheet">
            <?php echo self::mark(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed decorative SVG, no request data. ?>
            <?php if ($site_name !== '') : ?>
                <p class="dstk-maint__site"><?php echo esc_html($site_name); ?></p>
            <?php endif; ?>
            <h1><?php echo esc_html($heading); ?></h1>
            <div class="dstk-maint__rule" aria-hidden="true"></div>
            <p class="dstk-maint__lead"><?php echo esc_html($message); ?></p>
        </div>
    </main>
    <footer class="dstk-maint__footer">
        <p><?php echo esc_html('Dr.Slon Toolkit ' . $version); ?></p>
        <p><a href="<?php echo esc_url(MaintenanceModule::CREDIT_URL); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(MaintenanceModule::CREDIT_LABEL); ?></a></p>
    </footer>
</body>
</html>
        <?php

        return (string) ob_get_clean();
    }

    private static function stylesheet(): string
    {
        $root = defined('DSTK_PLUGIN_DIR') ? \DSTK_PLUGIN_DIR : dirname(__DIR__, 3) . '/';
        $path = $root . 'assets/maintenance/screen.css';
        $css = is_readable($path) ? file_get_contents($path) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Plugin stylesheet is inlined so the closed site does not need a second request.

        if (! is_string($css) || $css === '') {
            return 'body{margin:0;background:#10140f;color:#f4efe6;font-family:Georgia,serif}';
        }

        return str_replace('</', '<\\/', $css);
    }

    private static function mark(): string
    {
        return '<svg class="dstk-maint__mark" viewBox="0 0 640 280" role="img" aria-hidden="true" focusable="false">'
            . '<path pathLength="1" d="M48 236 H592"/>'
            . '<path pathLength="1" d="M140 236 V92"/>'
            . '<path pathLength="1" d="M320 236 V58"/>'
            . '<path pathLength="1" d="M500 236 V92"/>'
            . '<path pathLength="1" d="M140 92 H500"/>'
            . '<path pathLength="1" d="M140 164 H500"/>'
            . '<path pathLength="1" d="M112 92 L320 40 L528 92"/>'
            . '<path pathLength="1" d="M140 164 L320 92"/>'
            . '<path pathLength="1" d="M320 164 L500 92"/>'
            . '<circle class="dstk-maint__bob" cx="320" cy="40" r="4"/>'
            . '</svg>';
    }
}
