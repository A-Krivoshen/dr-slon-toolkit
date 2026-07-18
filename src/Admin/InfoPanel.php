<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Admin;

final class InfoPanel
{
    private const STYLE_HANDLE = 'dstk-admin-info-panel';

    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function enqueue_assets(string $hook_suffix): void
    {
        if (! $this->is_plugin_screen($hook_suffix)) {
            return;
        }

        wp_enqueue_style(
            self::STYLE_HANDLE,
            plugins_url('assets/admin/info-panel.css', DSTK_PLUGIN_FILE),
            [],
            DSTK_VERSION
        );
    }

    public function render(): void
    {
        ?>
        <div class="dstk-info-panels">
            <section class="card dstk-info-panel" aria-labelledby="dstk-support-title">
                <h2 id="dstk-support-title" class="dstk-info-panel__title"><?php echo esc_html__('Поддержка плагина', 'dr-slon-toolkit'); ?></h2>
                <p class="dstk-info-panel__text">
                    <?php echo esc_html__('По всем вопросам пишите:', 'dr-slon-toolkit'); ?>
                    <a href="mailto:aleksey@krivoshein.site">aleksey@krivoshein.site</a>.
                </p>
            </section>

            <section class="card dstk-info-panel" aria-labelledby="dstk-review-title">
                <h2 id="dstk-review-title" class="dstk-info-panel__title"><?php echo esc_html__('Нравится плагин?', 'dr-slon-toolkit'); ?></h2>
                <p class="dstk-info-panel__text">
                    <?php echo esc_html__('Если Dr.Slon Toolkit помогает вашему проекту, вы можете отблагодарить, оставив отзыв. Хороший отзыв помогает продвигать продукты и услуги.', 'dr-slon-toolkit'); ?>
                </p>
                <p class="dstk-info-panel__actions">
                    <a class="button button-primary" href="<?php echo esc_url('https://yandex.ru/maps/org/ip_krivoshein_aleksey_sergeyevich/100156734340/reviews/'); ?>" target="_blank" rel="noopener noreferrer">
                        <?php echo esc_html__('Оставить отзыв на Яндекс.Картах', 'dr-slon-toolkit'); ?>
                    </a>
                </p>
                <p class="dstk-info-panel__text"><?php echo esc_html__('Также вы можете ознакомиться с другими услугами.', 'dr-slon-toolkit'); ?></p>
                <p class="dstk-info-panel__actions">
                    <a class="button" href="<?php echo esc_url('https://krivoshein.site/prays-list/'); ?>" target="_blank" rel="noopener noreferrer">
                        <?php echo esc_html__('Посмотреть услуги', 'dr-slon-toolkit'); ?>
                    </a>
                </p>
            </section>
        </div>
        <?php
    }

    private function is_plugin_screen(string $hook_suffix): bool
    {
        return strpos($hook_suffix, 'dr-slon-toolkit') !== false;
    }
}
