<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Admin;

final class InfoPanel
{
    private const SCRIPT_HANDLE = 'dstk-partner-widget';

    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('in_admin_footer', [$this, 'render_for_current_screen'], 20);
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
            '//wpwidget.ru/js/wps-widget-entry.min.js',
            [],
            null,
            [
                'in_footer' => true,
                'strategy'  => 'async',
            ]
        );
    }

    public function render_for_current_screen(): void
    {
        $screen = get_current_screen();

        if (! ($screen instanceof \WP_Screen)) {
            return;
        }

        if (! $this->is_plugin_screen($screen->id)) {
            return;
        }

        $this->render();
    }

    public function render(): void
    {
        ?>
        <div class="card" style="max-width:920px;margin-top:20px;padding:16px 20px;">
            <h2 style="margin-top:0;"><?php echo esc_html__('Служебная информация', 'dr-slon-toolkit'); ?></h2>
            <p>
                <strong><?php echo esc_html__('По всем вопросам:', 'dr-slon-toolkit'); ?></strong>
                <a href="mailto:aleksey@krivoshein.site">aleksey@krivoshein.site</a>
            </p>
            <p style="margin:8px 0 14px;">
                <?php echo esc_html__('Этот блок помогает поддерживать и развивать Dr.Slon Toolkit. Здесь размещаются полезные материалы без навязчивой рекламы и без вмешательства в рабочий интерфейс.', 'dr-slon-toolkit'); ?>
            </p>
            <div class="wps-widget" data-w="//wpwidget.ru/greetings?orientation=3&pid=11291"></div>
        </div>
        <?php
    }

    private function is_plugin_screen(string $hook_suffix): bool
    {
        return strpos($hook_suffix, 'dr-slon-toolkit') !== false;
    }
}
