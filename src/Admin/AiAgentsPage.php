<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Admin;

use DrSlon\Toolkit\Core\CacheVersion;
use DrSlon\Toolkit\Core\Settings;
use DrSlon\Toolkit\Modules\AiAgents\DocumentBuilder;

final class AiAgentsPage
{
    private SettingsPage $settings_page;

    public function __construct(?SettingsPage $settings_page = null)
    {
        $this->settings_page = $settings_page ?? new SettingsPage();
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu'], 11);
        add_action('admin_post_dstk_ai_purge_cache', [$this, 'handle_purge_cache']);
    }

    public function register_menu(): void
    {
        add_submenu_page(
            SettingsPage::PAGE_SLUG,
            __('AI Agents', 'dr-slon-toolkit'),
            __('AI Agents', 'dr-slon-toolkit'),
            'manage_options',
            SettingsPage::AI_PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function handle_purge_cache(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Недостаточно прав для выполнения действия.', 'dr-slon-toolkit'));
        }

        check_admin_referer('dstk_ai_purge_cache', 'dstk_ai_purge_nonce');
        CacheVersion::bump(CacheVersion::AI);

        wp_safe_redirect(
            add_query_arg(
                [
                    'page'            => SettingsPage::AI_PAGE_SLUG,
                    'dstk_ai_purged'  => '1',
                ],
                admin_url('admin.php')
            )
        );
        exit;
    }

    public function render_page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $settings = Settings::all();
        $enabled = ! empty($settings['modules']['ai_agents']);
        $ai = isset($settings['ai_agents']) && is_array($settings['ai_agents']) ? $settings['ai_agents'] : [];
        $builder = new DocumentBuilder($ai);
        $purged = isset($_GET['dstk_ai_purged']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        ?>
        <div class="wrap dstk-admin">
            <?php $this->settings_page->render_admin_header('ai'); ?>
            <?php settings_errors(); ?>

            <?php if ($purged) : ?>
                <div class="notice notice-success"><p><?php echo esc_html__('Кеш документов AI Agents сброшен.', 'dr-slon-toolkit'); ?></p></div>
            <?php endif; ?>

            <main class="dstk-main-card">
                <div class="dstk-card-heading">
                    <div>
                        <span class="dstk-eyebrow"><?php echo esc_html__('Machine-readable discovery', 'dr-slon-toolkit'); ?></span>
                        <h2><?php echo esc_html__('AI Agents', 'dr-slon-toolkit'); ?></h2>
                    </div>
                </div>

                <section class="dstk-help-card">
                    <h2><?php echo esc_html__('Статус', 'dr-slon-toolkit'); ?></h2>
                    <p>
                        <strong><?php echo esc_html($enabled ? __('Модуль включён', 'dr-slon-toolkit') : __('Модуль выключен', 'dr-slon-toolkit')); ?></strong>
                    </p>
                    <ul class="dstk-steps">
                        <?php foreach ($this->endpoint_catalog() as $kind => $meta) : ?>
                            <?php
                            $live = $enabled && $builder->endpoint_url($kind) !== '';
                            $url = $builder->endpoint_url($kind);
                            $display_url = $url !== '' ? $url : home_url($meta['path']);
                            ?>
                            <li>
                                <code><?php echo esc_html($display_url); ?></code>
                                —
                                <?php echo esc_html($live ? __('живой', 'dr-slon-toolkit') : __('пауза', 'dr-slon-toolkit')); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('dstk_ai_purge_cache', 'dstk_ai_purge_nonce'); ?>
                        <input type="hidden" name="action" value="dstk_ai_purge_cache">
                        <?php submit_button(__('Сбросить кеш', 'dr-slon-toolkit'), 'secondary', 'submit', false); ?>
                    </form>
                </section>

                <form class="dstk-settings-form" method="post" action="options.php">
                    <?php settings_fields('dstk_settings_group'); ?>
                    <input type="hidden" name="dstk_settings[_form]" value="ai_agents">
                    <input type="hidden" name="dstk_settings[ai_agents][_submitted]" value="1">

                    <section class="dstk-help-card">
                        <h2><?php echo esc_html__('Модуль', 'dr-slon-toolkit'); ?></h2>
                        <label>
                            <input type="hidden" name="dstk_settings[modules][ai_agents]" value="0">
                            <input type="checkbox" name="dstk_settings[modules][ai_agents]" value="1" <?php checked($enabled); ?>>
                            <?php echo esc_html__('Включить AI Agents', 'dr-slon-toolkit'); ?>
                        </label>
                    </section>

                    <section class="dstk-help-card">
                        <h2><?php echo esc_html__('Endpoints', 'dr-slon-toolkit'); ?></h2>
                        <?php foreach ($this->endpoint_toggles() as $key => $label) : ?>
                            <p>
                                <label>
                                    <input type="hidden" name="dstk_settings[ai_agents][<?php echo esc_attr($key); ?>]" value="0">
                                    <input type="checkbox" name="dstk_settings[ai_agents][<?php echo esc_attr($key); ?>]" value="1" <?php checked(! empty($ai[$key])); ?>>
                                    <?php echo esc_html($label); ?>
                                </label>
                            </p>
                        <?php endforeach; ?>
                    </section>

                    <section class="dstk-help-card">
                        <h2><?php echo esc_html__('Содержание (гибрид)', 'dr-slon-toolkit'); ?></h2>
                        <p class="description"><?php echo esc_html__('Пустые поля не ломают документы: тогда берётся автоматика WordPress.', 'dr-slon-toolkit'); ?></p>
                        <?php foreach ($this->textareas() as $key => $label) : ?>
                            <p>
                                <label for="dstk-ai-<?php echo esc_attr($key); ?>"><strong><?php echo esc_html($label); ?></strong></label><br>
                                <textarea id="dstk-ai-<?php echo esc_attr($key); ?>" class="large-text" rows="4" name="dstk_settings[ai_agents][<?php echo esc_attr($key); ?>]"><?php echo esc_textarea((string) ($ai[$key] ?? '')); ?></textarea>
                            </p>
                        <?php endforeach; ?>
                    </section>

                    <section class="dstk-help-card">
                        <h2><?php echo esc_html__('Источники', 'dr-slon-toolkit'); ?></h2>
                        <p><strong><?php echo esc_html__('Типы записей', 'dr-slon-toolkit'); ?></strong></p>
                        <?php
                        $selected_types = isset($ai['post_types']) && is_array($ai['post_types']) ? $ai['post_types'] : ['post', 'page'];
                        foreach (get_post_types([], 'objects') as $post_type => $object) {
                            if ($post_type === 'attachment' || ! is_post_type_viewable($object)) {
                                continue;
                            }
                            ?>
                            <label>
                                <input type="checkbox" name="dstk_settings[ai_agents][post_types][]" value="<?php echo esc_attr($post_type); ?>" <?php checked(in_array($post_type, $selected_types, true)); ?>>
                                <?php echo esc_html($post_type); ?>
                            </label>
                            <?php
                        }
                        ?>
                        <p>
                            <label>
                                <?php echo esc_html__('Лимит pulse', 'dr-slon-toolkit'); ?>
                                <input type="number" min="1" max="50" name="dstk_settings[ai_agents][pulse_limit]" value="<?php echo esc_attr((string) (int) ($ai['pulse_limit'] ?? 20)); ?>">
                            </label>
                        </p>
                        <p>
                            <label>
                                <?php echo esc_html__('Лимит llms-full', 'dr-slon-toolkit'); ?>
                                <input type="number" min="1" max="100" name="dstk_settings[ai_agents][full_posts_limit]" value="<?php echo esc_attr((string) (int) ($ai['full_posts_limit'] ?? 30)); ?>">
                            </label>
                        </p>
                        <p>
                            <label>
                                <input type="hidden" name="dstk_settings[ai_agents][exclude_noindex]" value="0">
                                <input type="checkbox" name="dstk_settings[ai_agents][exclude_noindex]" value="1" <?php checked(! empty($ai['exclude_noindex'])); ?>>
                                <?php echo esc_html__('Исключать noindex', 'dr-slon-toolkit'); ?>
                            </label>
                        </p>
                    </section>

                    <section class="dstk-help-card">
                        <h2><?php echo esc_html__('Просмотр', 'dr-slon-toolkit'); ?></h2>
                        <?php if (! $enabled) : ?>
                            <p><?php echo esc_html__('Включите модуль и сохраните настройки, затем откройте URL.', 'dr-slon-toolkit'); ?></p>
                        <?php else : ?>
                            <ul>
                                <?php foreach (['ai', 'llms', 'llms_full', 'agents', 'pulse'] as $kind) : ?>
                                    <?php $url = $builder->endpoint_url($kind); ?>
                                    <?php if ($url !== '') : ?>
                                        <li><a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($url); ?></a></li>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </section>

                    <div class="dstk-save-bar">
                        <?php submit_button(__('Сохранить изменения', 'dr-slon-toolkit'), 'primary', 'submit', false); ?>
                    </div>
                </form>
            </main>

            <?php (new InfoPanel())->render(); ?>
        </div>
        <?php
        unset($enabled);
    }

    /**
     * @return array<string, array{path:string}>
     */
    private function endpoint_catalog(): array
    {
        return [
            'ai'        => ['path' => '/ai.txt'],
            'llms'      => ['path' => '/llms.txt'],
            'llms_full' => ['path' => '/llms-full.txt'],
            'agents'    => ['path' => '/agents.md'],
            'pulse'     => ['path' => '/feed/ai-pulse.md'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function endpoint_toggles(): array
    {
        return [
            'ai_txt'     => '/ai.txt',
            'llms_txt'   => '/llms.txt',
            'llms_full'  => '/llms-full.txt',
            'agents_md'  => '/agents.md',
            'pulse_md'   => '/feed/ai-pulse.md (' . __('выкл. по умолчанию', 'dr-slon-toolkit') . ')',
            'html_links' => __('Ссылки describedby в HTML', 'dr-slon-toolkit'),
            'robots'     => __('Указатели в robots.txt', 'dr-slon-toolkit'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function textareas(): array
    {
        return [
            'site_blurb'    => __('Кто / что', 'dr-slon-toolkit'),
            'contacts'      => __('Контакты', 'dr-slon-toolkit'),
            'facts'         => __('Факты', 'dr-slon-toolkit'),
            'ai_policy'     => __('Политика для агентов', 'dr-slon-toolkit'),
            'do_not_invent' => __('Не выдумывать', 'dr-slon-toolkit'),
        ];
    }
}
