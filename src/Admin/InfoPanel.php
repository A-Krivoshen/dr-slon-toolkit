<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Admin;

final class InfoPanel
{
    private const SCRIPT_HANDLE = 'dstk-partner-widget';

    public function register_assets(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets(string $hook_suffix): void
    {
        if (! $this->is_plugin_screen($hook_suffix)) {
            return;
        }

        if (wp_script_is(self::SCRIPT_HANDLE, 'enqueued')) {
            return;
        }

        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            'https://wpwidget.ru/js/wps-widget-entry.min.js',
            [],
            null,
            [
                'in_footer' => true,
                'strategy'  => 'async',
            ]
        );
    }

    public function render(): void
    {
        ?>
        <div class="notice notice-info" style="margin-top:16px;">
            <p>
                <strong><?php echo esc_html__('По всем вопросам:', 'dr-slon-toolkit'); ?></strong>
                <a href="mailto:aleksey@krivoshein.site">aleksey@krivoshein.site</a>
            </p>
            <p style="margin-top:4px;">
                <?php echo esc_html__('Поддержать развитие плагина можно через партнёрские материалы.', 'dr-slon-toolkit'); ?>
            </p>
            <div class="wps-widget" data-w="//wpwidget.ru/greetings?orientation=3&amp;pid=11291"></div>
        </div>
        <?php
    }

    private function is_plugin_screen(string $hook_suffix): bool
    {
        return strpos($hook_suffix, 'dr-slon-toolkit') !== false;
    }
}
