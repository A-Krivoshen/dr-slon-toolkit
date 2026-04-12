<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Admin;

use DrSlon\Toolkit\Core\Settings;

final class SettingsPage
{
    private InfoPanel $info_panel;

    public function __construct()
    {
        $this->info_panel = new InfoPanel();
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        $this->info_panel->register_assets();
    }

    public function register_menu(): void
    {
        add_menu_page(
            __('Dr.Slon Toolkit', 'dr-slon-toolkit'),
            __('Dr.Slon Toolkit', 'dr-slon-toolkit'),
            'manage_options',
            'dr-slon-toolkit',
            [$this, 'render_page'],
            'dashicons-admin-tools',
            58
        );
    }

    public function register_settings(): void
    {
        register_setting(
            'dstk_settings_group',
            Settings::OPTION_KEY,
            [
                'type'              => 'array',
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default'           => Settings::defaults(),
            ]
        );

        add_settings_section(
            'dstk_modules_section',
            __('Переключатели модулей', 'dr-slon-toolkit'),
            '__return_false',
            'dr-slon-toolkit'
        );

        add_settings_field(
            'dstk_modules',
            __('Включить модули', 'dr-slon-toolkit'),
            [$this, 'render_module_fields'],
            'dr-slon-toolkit',
            'dstk_modules_section'
        );

        add_settings_section(
            'dstk_cleanup_section',
            __('Параметры очистки', 'dr-slon-toolkit'),
            [$this, 'render_cleanup_section_description'],
            'dr-slon-toolkit'
        );

        add_settings_field(
            'dstk_cleanup',
            __('Настройки очистки', 'dr-slon-toolkit'),
            [$this, 'render_cleanup_fields'],
            'dr-slon-toolkit',
            'dstk_cleanup_section'
        );

        add_settings_section(
            'dstk_hide_login_section',
            __('Параметры скрытого входа', 'dr-slon-toolkit'),
            [$this, 'render_hide_login_section_description'],
            'dr-slon-toolkit'
        );

        add_settings_field(
            'dstk_hide_login_slug',
            __('Slug страницы входа', 'dr-slon-toolkit'),
            [$this, 'render_hide_login_slug_field'],
            'dr-slon-toolkit',
            'dstk_hide_login_section'
        );

        add_settings_section(
            'dstk_rest_api_section',
            __('Параметры REST API Control', 'dr-slon-toolkit'),
            [$this, 'render_rest_api_section_description'],
            'dr-slon-toolkit'
        );

        add_settings_field(
            'dstk_rest_api',
            __('Настройки REST API', 'dr-slon-toolkit'),
            [$this, 'render_rest_api_fields'],
            'dr-slon-toolkit',
            'dstk_rest_api_section'
        );
    }

    /**
     * @param mixed $input
     * @return array<string, mixed>
     */
    public function sanitize_settings($input): array
    {
        if (! is_array($input)) {
            return Settings::defaults();
        }

        $previous = Settings::all();
        $sanitized = Settings::merge_with_defaults($input);

        $hide_login_changed = (
            ! empty($previous['modules']['hide_login']) !== ! empty($sanitized['modules']['hide_login'])
        ) || (
            (string) ($previous['hide_login']['slug'] ?? '') !== (string) ($sanitized['hide_login']['slug'] ?? '')
        );

        if ($hide_login_changed) {
            flush_rewrite_rules();
        }

        return $sanitized;
    }

    public function render_module_fields(): void
    {
        $settings = Settings::all();
        $modules = $settings['modules'];
        ?>
        <fieldset>
            <label>
                <input type="checkbox" name="dstk_settings[modules][transliteration]" value="1" <?php checked(! empty($modules['transliteration'])); ?>>
                <?php echo esc_html__('Транслитерация', 'dr-slon-toolkit'); ?>
            </label>
            <br>
            <label>
                <input type="checkbox" name="dstk_settings[modules][disable_comments]" value="1" <?php checked(! empty($modules['disable_comments'])); ?>>
                <?php echo esc_html__('Отключение комментариев', 'dr-slon-toolkit'); ?>
            </label>
            <br>
            <label>
                <input type="checkbox" name="dstk_settings[modules][cleanup]" value="1" <?php checked(! empty($modules['cleanup'])); ?>>
                <?php echo esc_html__('Очистка', 'dr-slon-toolkit'); ?>
            </label>
            <br>
            <label>
                <input type="checkbox" name="dstk_settings[modules][hide_login]" value="1" <?php checked(! empty($modules['hide_login'])); ?>>
                <?php echo esc_html__('Скрытый вход', 'dr-slon-toolkit'); ?>
            </label>
            <br>
            <label>
                <input type="checkbox" name="dstk_settings[modules][rest_api_control]" value="1" <?php checked(! empty($modules['rest_api_control'])); ?>>
                <?php echo esc_html__('REST API Control', 'dr-slon-toolkit'); ?>
            </label>
        </fieldset>
        <?php
    }

    public function render_cleanup_section_description(): void
    {
        echo '<p>';
        echo esc_html__('Эти параметры работают только при включённом модуле «Очистка».', 'dr-slon-toolkit');
        echo '</p>';
    }

    public function render_cleanup_fields(): void
    {
        $settings = Settings::all();
        $cleanup = $settings['cleanup'];
        ?>
        <fieldset>
            <label>
                <input type="checkbox" name="dstk_settings[cleanup][disable_emojis]" value="1" <?php checked(! empty($cleanup['disable_emojis'])); ?>>
                <?php echo esc_html__('Отключить скрипты и стили emoji', 'dr-slon-toolkit'); ?>
            </label>
            <br>
            <label>
                <input type="checkbox" name="dstk_settings[cleanup][disable_wp_embed]" value="1" <?php checked(! empty($cleanup['disable_wp_embed'])); ?>>
                <?php echo esc_html__('Отключить скрипт wp-embed на сайте', 'dr-slon-toolkit'); ?>
            </label>
            <br>
            <label>
                <input type="checkbox" name="dstk_settings[cleanup][disable_xmlrpc]" value="1" <?php checked(! empty($cleanup['disable_xmlrpc'])); ?>>
                <?php echo esc_html__('Отключить XML-RPC', 'dr-slon-toolkit'); ?>
            </label>
            <br>
            <label>
                <input type="checkbox" name="dstk_settings[cleanup][clean_head]" value="1" <?php checked(! empty($cleanup['clean_head'])); ?>>
                <?php echo esc_html__('Удалять выбранные теги в блоке head', 'dr-slon-toolkit'); ?>
            </label>
        </fieldset>
        <?php
    }

    public function render_hide_login_section_description(): void
    {
        echo '<p>';
        echo esc_html__('Модуль «Скрытый вход» скрывает прямой доступ к wp-login.php для неавторизованных пользователей.', 'dr-slon-toolkit');
        echo '<br>';
        echo esc_html__('После изменения slug нажмите «Сохранить изменения»: правила маршрутизации обновятся автоматически.', 'dr-slon-toolkit');
        echo '<br>';
        echo esc_html__('Аварийное отключение: добавьте в wp-config.php константу KRV_DSTK_DISABLE_HIDE_LOGIN = true.', 'dr-slon-toolkit');
        echo '</p>';
    }

    public function render_hide_login_slug_field(): void
    {
        $settings = Settings::all();
        $hide_login = isset($settings['hide_login']) && is_array($settings['hide_login']) ? $settings['hide_login'] : [];
        $slug = isset($hide_login['slug']) ? (string) $hide_login['slug'] : 'login';
        ?>
        <fieldset>
            <label for="dstk-hide-login-slug" class="screen-reader-text">
                <?php echo esc_html__('Slug страницы входа', 'dr-slon-toolkit'); ?>
            </label>
            <input
                id="dstk-hide-login-slug"
                type="text"
                name="dstk_settings[hide_login][slug]"
                value="<?php echo esc_attr($slug); ?>"
                class="regular-text"
                placeholder="my-login"
            >
            <p class="description">
                <?php echo esc_html__('Пример: my-login. Итоговый адрес входа: /my-login/.', 'dr-slon-toolkit'); ?>
            </p>
        </fieldset>
        <?php
    }

    public function render_rest_api_section_description(): void
    {
        echo '<p>';
        echo esc_html__('Ограничение REST API может повлиять на редактор и интеграции. Начните с мягкого режима и проверяйте сайт после изменений.', 'dr-slon-toolkit');
        echo '</p>';
    }

    public function render_rest_api_fields(): void
    {
        $settings = Settings::all();
        $rest_api = isset($settings['rest_api']) && is_array($settings['rest_api']) ? $settings['rest_api'] : [];

        $mode = isset($rest_api['mode']) ? (string) $rest_api['mode'] : 'allow_all';
        $whitelist_routes = isset($rest_api['whitelist_routes']) ? (string) $rest_api['whitelist_routes'] : '';
        $whitelist_namespaces = isset($rest_api['whitelist_namespaces']) ? (string) $rest_api['whitelist_namespaces'] : '';
        $trusted_capability = isset($rest_api['trusted_capability']) ? (string) $rest_api['trusted_capability'] : 'edit_posts';
        $system_routes = isset($rest_api['system_routes']) ? (string) $rest_api['system_routes'] : '';
        ?>
        <fieldset>
            <p>
                <label for="dstk-rest-mode"><strong><?php echo esc_html__('Режим REST API', 'dr-slon-toolkit'); ?></strong></label><br>
                <select id="dstk-rest-mode" name="dstk_settings[rest_api][mode]">
                    <option value="allow_all" <?php selected($mode, 'allow_all'); ?>><?php echo esc_html__('Разрешить всем', 'dr-slon-toolkit'); ?></option>
                    <option value="authenticated_only" <?php selected($mode, 'authenticated_only'); ?>><?php echo esc_html__('Только авторизованным', 'dr-slon-toolkit'); ?></option>
                    <option value="whitelist" <?php selected($mode, 'whitelist'); ?>><?php echo esc_html__('Ограниченный режим по whitelist', 'dr-slon-toolkit'); ?></option>
                </select>
            </p>

            <p>
                <label for="dstk-rest-whitelist-routes"><strong><?php echo esc_html__('Whitelist маршрутов (точные пути)', 'dr-slon-toolkit'); ?></strong></label><br>
                <textarea id="dstk-rest-whitelist-routes" name="dstk_settings[rest_api][whitelist_routes]" rows="4" class="large-text code"><?php echo esc_textarea($whitelist_routes); ?></textarea>
                <span class="description"><?php echo esc_html__('По одному маршруту в строке. Пример: /wp/v2/posts', 'dr-slon-toolkit'); ?></span>
            </p>

            <p>
                <label for="dstk-rest-whitelist-namespaces"><strong><?php echo esc_html__('Whitelist namespace', 'dr-slon-toolkit'); ?></strong></label><br>
                <textarea id="dstk-rest-whitelist-namespaces" name="dstk_settings[rest_api][whitelist_namespaces]" rows="3" class="large-text code"><?php echo esc_textarea($whitelist_namespaces); ?></textarea>
                <span class="description"><?php echo esc_html__('По одному namespace в строке. Пример: wp/v2', 'dr-slon-toolkit'); ?></span>
            </p>

            <p>
                <label for="dstk-rest-capability"><strong><?php echo esc_html__('Доверенная capability для обхода ограничений', 'dr-slon-toolkit'); ?></strong></label><br>
                <input id="dstk-rest-capability" type="text" name="dstk_settings[rest_api][trusted_capability]" value="<?php echo esc_attr($trusted_capability); ?>" class="regular-text code">
                <span class="description"><?php echo esc_html__('Пример: edit_posts. В режиме whitelist пользователи с этой capability получают полный доступ.', 'dr-slon-toolkit'); ?></span>
            </p>

            <p>
                <label for="dstk-rest-system-routes"><strong><?php echo esc_html__('Дополнительные безопасные маршруты (расширение встроенного allowlist)', 'dr-slon-toolkit'); ?></strong></label><br>
                <textarea id="dstk-rest-system-routes" name="dstk_settings[rest_api][system_routes]" rows="5" class="large-text code"><?php echo esc_textarea($system_routes); ?></textarea>
                <span class="description"><?php echo esc_html__('Встроенный базовый allowlist WordPress всегда активен и не зависит от этого поля. Здесь можно только добавить дополнительные маршруты.', 'dr-slon-toolkit'); ?></span>
            </p>
        </fieldset>
        <?php
    }

    public function render_page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Dr.Slon Toolkit', 'dr-slon-toolkit'); ?></h1>
            <p><?php echo esc_html__('Модульный плагин для практических задач клиентских сайтов.', 'dr-slon-toolkit'); ?></p>

            <form method="post" action="options.php">
                <?php
                settings_fields('dstk_settings_group');
                do_settings_sections('dr-slon-toolkit');
                submit_button(__('Сохранить изменения', 'dr-slon-toolkit'));
                ?>
            </form>

            <?php $this->info_panel->render(); ?>
        </div>
        <?php
    }
}
