<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Admin;

use DrSlon\Toolkit\Core\Settings;
use DrSlon\Toolkit\Integrations\SeoFrameworkDetector;
use DrSlon\Toolkit\Modules\IndexNowModule;

final class SettingsPage
{
    private const UPDATE_CONTROLS_CAPABILITY = 'update_core';
    private const PAGE_SLUG = 'dr-slon-toolkit';
    private const HELP_PAGE_SLUG = 'dr-slon-toolkit-help';

    private InfoPanel $info_panel;

    public function __construct()
    {
        $this->info_panel = new InfoPanel();
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_post_dstk_indexnow_manual_submit', [$this, 'handle_indexnow_manual_submit']);
        $this->info_panel->register();
    }

    public function register_menu(): void
    {
        add_menu_page(
            __('Dr.Slon Toolkit', 'dr-slon-toolkit'),
            __('Dr.Slon Toolkit', 'dr-slon-toolkit'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page'],
            'dashicons-admin-tools',
            58
        );

        add_submenu_page(
            self::PAGE_SLUG,
            __('Настройки Dr.Slon Toolkit', 'dr-slon-toolkit'),
            __('Настройки', 'dr-slon-toolkit'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );

        add_submenu_page(
            self::PAGE_SLUG,
            __('Помощь Dr.Slon Toolkit', 'dr-slon-toolkit'),
            __('Помощь', 'dr-slon-toolkit'),
            'manage_options',
            self::HELP_PAGE_SLUG,
            [$this, 'render_help_page']
        );
    }

    public function enqueue_assets(string $hook_suffix): void
    {
        if (! str_contains($hook_suffix, self::PAGE_SLUG)) {
            return;
        }

        wp_enqueue_style(
            'dstk-admin-settings',
            plugins_url('assets/admin/settings.css', DSTK_PLUGIN_FILE),
            [],
            DSTK_VERSION
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

        add_settings_section(
            'dstk_indexnow_section',
            __('Параметры IndexNow', 'dr-slon-toolkit'),
            [$this, 'render_indexnow_section_description'],
            'dr-slon-toolkit'
        );

        add_settings_field(
            'dstk_indexnow',
            __('Настройки IndexNow', 'dr-slon-toolkit'),
            [$this, 'render_indexnow_fields'],
            'dr-slon-toolkit',
            'dstk_indexnow_section'
        );

        add_settings_section(
            'dstk_sitemap_section',
            __('Параметры Sitemap', 'dr-slon-toolkit'),
            [$this, 'render_sitemap_section_description'],
            'dr-slon-toolkit'
        );

        add_settings_field(
            'dstk_sitemap',
            __('Настройки Sitemap', 'dr-slon-toolkit'),
            [$this, 'render_sitemap_fields'],
            'dr-slon-toolkit',
            'dstk_sitemap_section'
        );

        add_settings_section(
            'dstk_update_controls_section',
            __('Параметры Update Controls', 'dr-slon-toolkit'),
            [$this, 'render_update_controls_section_description'],
            'dr-slon-toolkit'
        );

        add_settings_field(
            'dstk_update_controls',
            __('Настройки обновлений', 'dr-slon-toolkit'),
            [$this, 'render_update_controls_fields'],
            'dr-slon-toolkit',
            'dstk_update_controls_section'
        );
    }

    /**
     * @param mixed $input
     * @return array<string, mixed>
     */
    public function sanitize_settings($input): array
    {
        $previous = Settings::all();

        if (! is_array($input)) {
            return $previous;
        }

        $sanitized = Settings::merge_with_defaults($input, true);

        $requested_slug = isset($input['hide_login']['slug']) ? (string) $input['hide_login']['slug'] : '';
        $normalized_requested_slug = sanitize_title_with_dashes($requested_slug);

        if ($normalized_requested_slug !== '' && $normalized_requested_slug !== $sanitized['hide_login']['slug']) {
            add_settings_error(
                Settings::OPTION_KEY,
                'dstk_hide_login_slug',
                __('Slug скрытого входа зарезервирован или содержит недопустимые символы. Сохранено безопасное значение «login».', 'dr-slon-toolkit')
            );
        }

        if (! $this->can_manage_update_controls()) {
            $sanitized['modules']['update_controls'] = ! empty($previous['modules']['update_controls']);
            $sanitized['update_controls'] = $previous['update_controls'];
        }

        $rewrite_settings_changed = (
            ! empty($previous['modules']['hide_login']) !== ! empty($sanitized['modules']['hide_login'])
        ) || (
            (string) ($previous['hide_login']['slug'] ?? '') !== (string) ($sanitized['hide_login']['slug'] ?? '')
        ) || (
            ! empty($previous['modules']['sitemap']) !== ! empty($sanitized['modules']['sitemap'])
        ) || (
            ! empty($previous['sitemap']['enabled']) !== ! empty($sanitized['sitemap']['enabled'])
        );

        if ($rewrite_settings_changed) {
            update_option(Settings::REWRITE_FLUSH_PENDING_OPTION, 1, false);
        }

        return $sanitized;
    }

    public function render_module_fields(): void
    {
        $settings = Settings::all();
        $modules = $settings['modules'];
        $can_manage_update_controls = $this->can_manage_update_controls();
        $definitions = [
            'transliteration' => [
                'title'       => __('Транслитерация URL', 'dr-slon-toolkit'),
                'description' => __('Русские заголовки превращаются в понятные латинские slug и имена файлов.', 'dr-slon-toolkit'),
                'icon'        => 'dashicons-editor-spellcheck',
            ],
            'disable_comments' => [
                'title'       => __('Отключение комментариев', 'dr-slon-toolkit'),
                'description' => __('Закрывает комментарии и убирает связанные пункты интерфейса.', 'dr-slon-toolkit'),
                'icon'        => 'dashicons-admin-comments',
            ],
            'cleanup' => [
                'title'       => __('Очистка WordPress', 'dr-slon-toolkit'),
                'description' => __('Убирает выбранные emoji, embed, XML-RPC и служебные теги head.', 'dr-slon-toolkit'),
                'icon'        => 'dashicons-filter',
            ],
            'hide_login' => [
                'title'       => __('Скрытый вход', 'dr-slon-toolkit'),
                'description' => __('Переносит форму входа на собственный URL. Перед включением прочитайте помощь.', 'dr-slon-toolkit'),
                'icon'        => 'dashicons-lock',
            ],
            'rest_api_control' => [
                'title'       => __('REST API Control', 'dr-slon-toolkit'),
                'description' => __('Ограничивает REST API, сохраняя системные маршруты редактора.', 'dr-slon-toolkit'),
                'icon'        => 'dashicons-rest-api',
            ],
            'indexnow' => [
                'title'       => __('IndexNow', 'dr-slon-toolkit'),
                'description' => __('Асинхронно сообщает поисковым системам об изменениях публичных URL.', 'dr-slon-toolkit'),
                'icon'        => 'dashicons-megaphone',
            ],
            'sitemap' => [
                'title'       => __('XML Sitemap', 'dr-slon-toolkit'),
                'description' => __('Создаёт карту сайта, если эту задачу не выполняет The SEO Framework.', 'dr-slon-toolkit'),
                'icon'        => 'dashicons-networking',
            ],
            'update_controls' => [
                'title'       => __('Update Controls', 'dr-slon-toolkit'),
                'description' => __('Управляет политикой автообновлений, не отменяя защитные решения WordPress.', 'dr-slon-toolkit'),
                'icon'        => 'dashicons-update',
            ],
        ];
        ?>
        <fieldset class="dstk-module-grid">
            <?php foreach ($definitions as $slug => $definition) : ?>
                <?php $disabled = $slug === 'update_controls' && ! $can_manage_update_controls; ?>
                <label class="dstk-module-card<?php echo ! empty($modules[$slug]) ? ' is-active' : ''; ?><?php echo $disabled ? ' is-disabled' : ''; ?>">
                    <input type="checkbox" name="dstk_settings[modules][<?php echo esc_attr($slug); ?>]" value="1" <?php checked(! empty($modules[$slug])); ?> <?php disabled($disabled); ?>>
                    <span class="dashicons <?php echo esc_attr($definition['icon']); ?>" aria-hidden="true"></span>
                    <span class="dstk-module-card__body">
                        <strong><?php echo esc_html($definition['title']); ?></strong>
                        <span><?php echo esc_html($definition['description']); ?></span>
                    </span>
                    <span class="dstk-module-card__state"><?php echo esc_html(! empty($modules[$slug]) ? __('Включён', 'dr-slon-toolkit') : __('Выключен', 'dr-slon-toolkit')); ?></span>
                </label>
            <?php endforeach; ?>
            <?php if (! $can_manage_update_controls) : ?>
                <p class="description dstk-module-grid__notice"><?php echo esc_html__('Для управления обновлениями требуется право обновлять ядро WordPress.', 'dr-slon-toolkit'); ?></p>
            <?php endif; ?>
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
            <input type="hidden" name="dstk_settings[cleanup][_submitted]" value="1">
            <input type="hidden" name="dstk_settings[cleanup][disable_emojis]" value="0">
            <label>
                <input type="checkbox" name="dstk_settings[cleanup][disable_emojis]" value="1" <?php checked(! empty($cleanup['disable_emojis'])); ?>>
                <?php echo esc_html__('Отключить скрипты и стили emoji', 'dr-slon-toolkit'); ?>
            </label>
            <br>
            <input type="hidden" name="dstk_settings[cleanup][disable_wp_embed]" value="0">
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
            <input type="hidden" name="dstk_settings[cleanup][clean_head]" value="0">
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
                <select id="dstk-rest-capability" name="dstk_settings[rest_api][trusted_capability]">
                    <?php foreach (Settings::trusted_capabilities() as $capability) : ?>
                        <option value="<?php echo esc_attr($capability); ?>" <?php selected($trusted_capability, $capability); ?>>
                            <?php echo esc_html($capability); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <span class="description"><?php echo esc_html__('Пользователи с этой capability обходят whitelist. Маршруты редактора (posts/pages/media) доступны только авторизованным.', 'dr-slon-toolkit'); ?></span>
            </p>

            <p>
                <label for="dstk-rest-system-routes"><strong><?php echo esc_html__('Дополнительные маршруты редактора (только для авторизованных)', 'dr-slon-toolkit'); ?></strong></label><br>
                <textarea id="dstk-rest-system-routes" name="dstk_settings[rest_api][system_routes]" rows="5" class="large-text code"><?php echo esc_textarea($system_routes); ?></textarea>
                <span class="description"><?php echo esc_html__('Публично остаются только oembed и корень API. Whitelist-маршруты ниже — явный публичный доступ. Это поле расширяет allowlist редактора (нужен вход).', 'dr-slon-toolkit'); ?></span>
            </p>
        </fieldset>
        <?php
    }

    public function render_indexnow_section_description(): void
    {
        echo '<p>';
        echo esc_html__('IndexNow отправляет URL в поисковые системы после публикации/обновления. Укажите ключ и проверьте работу на тестовой записи.', 'dr-slon-toolkit');
        echo '</p>';
    }

    public function render_indexnow_fields(): void
    {
        $settings = Settings::all();
        $indexnow = isset($settings['indexnow']) && is_array($settings['indexnow']) ? $settings['indexnow'] : [];
        $key = isset($indexnow['key']) ? (string) $indexnow['key'] : '';
        $endpoint = isset($indexnow['endpoint']) ? (string) $indexnow['endpoint'] : 'https://api.indexnow.org/indexnow';
        $selected_post_types = isset($indexnow['post_types']) && is_array($indexnow['post_types']) ? $indexnow['post_types'] : ['post', 'page'];
        $viewable_post_types = array_filter(
            get_post_types([], 'objects'),
            static fn ($object): bool => $object->name !== 'attachment' && is_post_type_viewable($object)
        );
        ?>
        <fieldset>
            <input type="hidden" name="dstk_settings[indexnow][_submitted]" value="1">
            <p>
                <label for="dstk-indexnow-key"><strong><?php echo esc_html__('Ключ IndexNow', 'dr-slon-toolkit'); ?></strong></label><br>
                <input id="dstk-indexnow-key" type="text" name="dstk_settings[indexnow][key]" value="<?php echo esc_attr($key); ?>" class="regular-text code" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                <span class="description"><?php echo esc_html__('Разрешены латиница, цифры и дефис. Если ключ пустой, отправка не выполняется.', 'dr-slon-toolkit'); ?></span>
            </p>

            <p>
                <label for="dstk-indexnow-endpoint"><strong><?php echo esc_html__('Endpoint IndexNow', 'dr-slon-toolkit'); ?></strong></label><br>
                <select id="dstk-indexnow-endpoint" name="dstk_settings[indexnow][endpoint]">
                    <option value="https://api.indexnow.org/indexnow" <?php selected($endpoint, 'https://api.indexnow.org/indexnow'); ?>>IndexNow (универсальный)</option>
                    <option value="https://www.bing.com/indexnow" <?php selected($endpoint, 'https://www.bing.com/indexnow'); ?>>Bing</option>
                    <option value="https://yandex.com/indexnow" <?php selected($endpoint, 'https://yandex.com/indexnow'); ?>>Yandex</option>
                </select>
            </p>

            <p><strong><?php echo esc_html__('Типы записей для автоотправки', 'dr-slon-toolkit'); ?></strong></p>
            <?php foreach ($viewable_post_types as $post_type => $object) : ?>
                <label style="display:block;margin-bottom:4px;">
                    <input type="checkbox" name="dstk_settings[indexnow][post_types][]" value="<?php echo esc_attr($post_type); ?>" <?php checked(in_array($post_type, $selected_post_types, true)); ?>>
                    <?php echo esc_html($object->labels->singular_name); ?> (<code><?php echo esc_html($post_type); ?></code>)
                </label>
            <?php endforeach; ?>

            <?php if ($key !== '') : ?>
                <p class="description">
                    <?php echo esc_html__('Проверочный ключ-файл будет доступен по адресу:', 'dr-slon-toolkit'); ?>
                    <code><?php echo esc_html(IndexNowModule::key_location_url($key)); ?></code>
                </p>
            <?php endif; ?>
        </fieldset>
        <?php
    }

    public function render_sitemap_section_description(): void
    {
        echo '<p>';
        echo esc_html__('MVP Sitemap отдаётся только при включённом модуле и отдельном флаге ниже. При активном The SEO Framework runtime Dr.Slon Toolkit отключается для защиты от дублей.', 'dr-slon-toolkit');
        echo '</p>';
    }

    public function render_sitemap_fields(): void
    {
        $settings = Settings::all();
        $sitemap = isset($settings['sitemap']) && is_array($settings['sitemap']) ? $settings['sitemap'] : [];
        $enabled = ! empty($sitemap['enabled']);
        $selected_post_types = isset($sitemap['post_types']) && is_array($sitemap['post_types']) ? $sitemap['post_types'] : ['post', 'page'];
        $selected_taxonomies = isset($sitemap['taxonomies']) && is_array($sitemap['taxonomies']) ? $sitemap['taxonomies'] : ['category', 'post_tag'];
        $viewable_post_types = array_filter(
            get_post_types([], 'objects'),
            static fn ($object): bool => $object->name !== 'attachment' && is_post_type_viewable($object)
        );
        $public_taxonomies = array_filter(get_taxonomies([], 'objects'), 'is_taxonomy_viewable');
        ?>
        <fieldset>
            <input type="hidden" name="dstk_settings[sitemap][_submitted]" value="1">
            <p>
                <input type="hidden" name="dstk_settings[sitemap][enabled]" value="0">
                <label>
                    <input type="checkbox" name="dstk_settings[sitemap][enabled]" value="1" <?php checked($enabled); ?>>
                    <?php echo esc_html__('Включить runtime XML Sitemap', 'dr-slon-toolkit'); ?>
                </label>
            </p>

            <p><strong><?php echo esc_html__('Типы записей в sitemap', 'dr-slon-toolkit'); ?></strong></p>
            <?php foreach ($viewable_post_types as $post_type => $object) : ?>
                <label style="display:block;margin-bottom:4px;">
                    <input type="checkbox" name="dstk_settings[sitemap][post_types][]" value="<?php echo esc_attr($post_type); ?>" <?php checked(in_array($post_type, $selected_post_types, true)); ?>>
                    <?php echo esc_html($object->labels->singular_name); ?> (<code><?php echo esc_html($post_type); ?></code>)
                </label>
            <?php endforeach; ?>

            <p><strong><?php echo esc_html__('Таксономии в sitemap', 'dr-slon-toolkit'); ?></strong></p>
            <?php foreach ($public_taxonomies as $taxonomy => $object) : ?>
                <label style="display:block;margin-bottom:4px;">
                    <input type="checkbox" name="dstk_settings[sitemap][taxonomies][]" value="<?php echo esc_attr($taxonomy); ?>" <?php checked(in_array($taxonomy, $selected_taxonomies, true)); ?>>
                    <?php echo esc_html($object->labels->singular_name); ?> (<code><?php echo esc_html($taxonomy); ?></code>)
                </label>
            <?php endforeach; ?>

            <p class="description">
                <?php echo esc_html__('Исключаются записи со статусом не publish и записи с паролем. Для noindex доступен фильтр dstk_sitemap_is_noindex. Маршруты MVP: /sitemap.xml, /sitemap-pt-{post_type}.xml, /sitemap-tax-{taxonomy}.xml.', 'dr-slon-toolkit'); ?>
            </p>
        </fieldset>
        <?php
    }

    public function render_update_controls_section_description(): void
    {
        echo '<p>';
        echo esc_html__('Модуль управляет автообновлениями через нативные фильтры WordPress. Полное отключение обновлений повышает риски безопасности.', 'dr-slon-toolkit');
        echo '</p>';
    }

    public function render_update_controls_fields(): void
    {
        if (! $this->can_manage_update_controls()) {
            echo '<p class="description">';
            echo esc_html__('Для изменения этих параметров требуется право обновлять ядро WordPress.', 'dr-slon-toolkit');
            echo '</p>';
            return;
        }

        $settings = Settings::all();
        $update_controls = isset($settings['update_controls']) && is_array($settings['update_controls']) ? $settings['update_controls'] : [];
        $core_mode = isset($update_controls['core_mode']) ? (string) $update_controls['core_mode'] : 'minor';
        $plugins = ! array_key_exists('plugins', $update_controls) || ! empty($update_controls['plugins']);
        $themes = ! array_key_exists('themes', $update_controls) || ! empty($update_controls['themes']);
        $translations = ! array_key_exists('translations', $update_controls) || ! empty($update_controls['translations']);
        $email_notifications = ! array_key_exists('email_notifications', $update_controls) || ! empty($update_controls['email_notifications']);
        ?>
        <fieldset>
            <input type="hidden" name="dstk_settings[update_controls][_submitted]" value="1">
            <p>
                <label for="dstk-core-update-mode"><strong><?php echo esc_html__('Обновления ядра WordPress', 'dr-slon-toolkit'); ?></strong></label><br>
                <select id="dstk-core-update-mode" name="dstk_settings[update_controls][core_mode]">
                    <option value="all" <?php selected($core_mode, 'all'); ?>><?php echo esc_html__('Все автообновления (major + minor)', 'dr-slon-toolkit'); ?></option>
                    <option value="minor" <?php selected($core_mode, 'minor'); ?>><?php echo esc_html__('Только minor (рекомендуется)', 'dr-slon-toolkit'); ?></option>
                    <option value="off" <?php selected($core_mode, 'off'); ?>><?php echo esc_html__('Полностью отключить автообновления ядра', 'dr-slon-toolkit'); ?></option>
                </select>
            </p>

            <p>
                <input type="hidden" name="dstk_settings[update_controls][plugins]" value="0">
                <label>
                    <input type="checkbox" name="dstk_settings[update_controls][plugins]" value="1" <?php checked($plugins); ?>>
                    <?php echo esc_html__('Включить автообновления плагинов', 'dr-slon-toolkit'); ?>
                </label>
                <br>
                <input type="hidden" name="dstk_settings[update_controls][themes]" value="0">
                <label>
                    <input type="checkbox" name="dstk_settings[update_controls][themes]" value="1" <?php checked($themes); ?>>
                    <?php echo esc_html__('Включить автообновления тем', 'dr-slon-toolkit'); ?>
                </label>
                <br>
                <input type="hidden" name="dstk_settings[update_controls][translations]" value="0">
                <label>
                    <input type="checkbox" name="dstk_settings[update_controls][translations]" value="1" <?php checked($translations); ?>>
                    <?php echo esc_html__('Включить автообновления переводов', 'dr-slon-toolkit'); ?>
                </label>
                <br>
                <input type="hidden" name="dstk_settings[update_controls][email_notifications]" value="0">
                <label>
                    <input type="checkbox" name="dstk_settings[update_controls][email_notifications]" value="1" <?php checked($email_notifications); ?>>
                    <?php echo esc_html__('Включить e-mail уведомления об автообновлениях', 'dr-slon-toolkit'); ?>
                </label>
            </p>

            <p class="description">
                <?php echo esc_html__('Внимание: полное отключение автообновлений может увеличить риски безопасности. Используйте этот режим только при наличии собственного процесса ручных обновлений. Режим minor блокирует major и dev; legacy-значение «security» автоматически приводится к minor.', 'dr-slon-toolkit'); ?>
            </p>
        </fieldset>
        <?php
    }

    private function render_indexnow_manual_form(): void
    {
        if (! Settings::module_enabled('indexnow')) {
            return;
        }

        ?>
        <hr>
        <h2><?php echo esc_html__('Ручная отправка URL', 'dr-slon-toolkit'); ?></h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="dstk_indexnow_manual_submit">
            <?php wp_nonce_field('dstk_indexnow_manual_submit', 'dstk_indexnow_manual_nonce'); ?>
            <input type="url" name="dstk_indexnow_manual_url" class="regular-text" placeholder="<?php echo esc_attr(home_url('/sample-page/')); ?>" required>
            <?php submit_button(__('Отправить URL в IndexNow', 'dr-slon-toolkit'), 'secondary', 'submit', false); ?>
        </form>
        <?php
    }

    public function handle_indexnow_manual_submit(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(esc_html__('Недостаточно прав для выполнения действия.', 'dr-slon-toolkit'));
        }

        check_admin_referer('dstk_indexnow_manual_submit', 'dstk_indexnow_manual_nonce');

        if (! Settings::module_enabled('indexnow')) {
            $result = [
                'success' => false,
                'message' => __('Модуль IndexNow отключён.', 'dr-slon-toolkit'),
            ];
        } else {
            $url = isset($_POST['dstk_indexnow_manual_url'])
                ? esc_url_raw(wp_unslash((string) $_POST['dstk_indexnow_manual_url']), ['http', 'https'])
                : '';
            $module = new IndexNowModule();
            $result = $module->submit_manual_url($url);
        }

        $notice = ! empty($result['success']) ? 'success' : 'error';
        $message = isset($result['message']) ? (string) $result['message'] : __('Не удалось отправить URL.', 'dr-slon-toolkit');

        $redirect = add_query_arg(
            [
                'page'                  => 'dr-slon-toolkit',
                'dstk_indexnow_notice'  => $notice,
                'dstk_indexnow_message' => $message,
            ],
            admin_url('admin.php')
        );

        wp_safe_redirect($redirect);
        exit;
    }

    public function render_page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        // Read-only flash message populated by the nonce-protected admin-post handler.
        $notice_type = isset($_GET['dstk_indexnow_notice']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ? sanitize_key((string) wp_unslash($_GET['dstk_indexnow_notice'])) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            : '';
        $notice_message = isset($_GET['dstk_indexnow_message']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ? sanitize_text_field((string) wp_unslash($_GET['dstk_indexnow_message'])) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            : '';
        ?>
        <div class="wrap dstk-admin">
            <?php $this->render_admin_header('settings'); ?>
            <?php settings_errors(); ?>

            <?php if ($notice_message !== '' && in_array($notice_type, ['success', 'error'], true)) : ?>
                <div class="notice notice-<?php echo esc_attr($notice_type === 'success' ? 'success' : 'error'); ?>"><p><?php echo esc_html($notice_message); ?></p></div>
            <?php endif; ?>

            <main class="dstk-main-card">
                <div class="dstk-card-heading">
                    <div>
                        <span class="dstk-eyebrow"><?php echo esc_html__('Конфигурация сайта', 'dr-slon-toolkit'); ?></span>
                        <h2><?php echo esc_html__('Инструменты и правила', 'dr-slon-toolkit'); ?></h2>
                    </div>
                    <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=' . self::HELP_PAGE_SLUG)); ?>"><?php echo esc_html__('Открыть помощь', 'dr-slon-toolkit'); ?></a>
                </div>

                <form class="dstk-settings-form" method="post" action="options.php">
                    <?php
                    settings_fields('dstk_settings_group');
                    do_settings_sections(self::PAGE_SLUG);
                    ?>
                    <div class="dstk-save-bar">
                        <span><?php echo esc_html__('Изменения применяются сразу после сохранения.', 'dr-slon-toolkit'); ?></span>
                        <?php submit_button(__('Сохранить изменения', 'dr-slon-toolkit'), 'primary', 'submit', false); ?>
                    </div>
                </form>

                <?php $this->render_indexnow_manual_form(); ?>
            </main>

            <?php $this->info_panel->render(); ?>
        </div>
        <?php
    }

    public function render_help_page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $queue_status = get_option('dstk_indexnow_queue_status', []);
        $queue_status = is_array($queue_status) ? $queue_status : [];
        ?>
        <div class="wrap dstk-admin">
            <?php $this->render_admin_header('help'); ?>

            <main class="dstk-help-layout">
                <section class="dstk-help-card dstk-help-card--wide">
                    <span class="dstk-eyebrow"><?php echo esc_html__('Быстрый старт', 'dr-slon-toolkit'); ?></span>
                    <h2><?php echo esc_html__('Настройка без риска', 'dr-slon-toolkit'); ?></h2>
                    <ol class="dstk-steps">
                        <li><?php echo esc_html__('Включайте по одному модулю и сохраняйте настройки.', 'dr-slon-toolkit'); ?></li>
                        <li><?php echo esc_html__('Проверяйте публичную страницу, редактор и вход в отдельном приватном окне.', 'dr-slon-toolkit'); ?></li>
                        <li><?php echo esc_html__('Перед ограничением REST API или сменой URL входа сделайте резервную копию.', 'dr-slon-toolkit'); ?></li>
                    </ol>
                </section>

                <section class="dstk-help-card">
                    <span class="dashicons dashicons-editor-spellcheck" aria-hidden="true"></span>
                    <h2><?php echo esc_html__('Транслитерация URL', 'dr-slon-toolkit'); ?></h2>
                    <p><?php echo esc_html__('Работает при создании новых slug и имён файлов. Уже опубликованные URL автоматически не меняются, поэтому внешние ссылки и SEO не ломаются.', 'dr-slon-toolkit'); ?></p>
                    <code>Главная страница → glavnaya-stranitsa</code>
                </section>

                <section class="dstk-help-card dstk-help-card--warning">
                    <span class="dashicons dashicons-lock" aria-hidden="true"></span>
                    <h2><?php echo esc_html__('Скрытый вход', 'dr-slon-toolkit'); ?></h2>
                    <p><?php echo esc_html__('После включения проверьте вход, выход и восстановление пароля в приватном окне. Reset Password и Recovery Mode намеренно используют нативный endpoint WordPress.', 'dr-slon-toolkit'); ?></p>
                    <p><strong><?php echo esc_html__('Аварийное отключение в wp-config.php:', 'dr-slon-toolkit'); ?></strong></p>
                    <code>define('KRV_DSTK_DISABLE_HIDE_LOGIN', true);</code>
                </section>

                <section class="dstk-help-card">
                    <span class="dashicons dashicons-megaphone" aria-hidden="true"></span>
                    <h2><?php echo esc_html__('IndexNow и TSF', 'dr-slon-toolkit'); ?></h2>
                    <p><?php echo esc_html__('Автоматические уведомления выполняются через WP-Cron и не задерживают сохранение записи. При активном The SEO Framework URL с noindex или отличающимся canonical не отправляется.', 'dr-slon-toolkit'); ?></p>
                    <p class="dstk-status-line">
                        <span><?php echo esc_html__('В очереди:', 'dr-slon-toolkit'); ?></span>
                        <strong><?php echo esc_html((string) (int) ($queue_status['queued'] ?? 0)); ?></strong>
                    </p>
                </section>

                <section class="dstk-help-card">
                    <span class="dashicons dashicons-networking" aria-hidden="true"></span>
                    <h2><?php echo esc_html__('XML Sitemap', 'dr-slon-toolkit'); ?></h2>
                    <p><?php echo esc_html__('Карта поддерживает пагинацию, HTTP-кеширование и подкаталоги. Если обнаружен The SEO Framework, Sitemap Toolkit отключается и не дублирует SEO-плагин.', 'dr-slon-toolkit'); ?></p>
                    <code><?php echo esc_html(home_url('/sitemap.xml')); ?></code>
                </section>

                <section class="dstk-help-card dstk-help-card--warning">
                    <span class="dashicons dashicons-rest-api" aria-hidden="true"></span>
                    <h2><?php echo esc_html__('REST API Control', 'dr-slon-toolkit'); ?></h2>
                    <p><?php echo esc_html__('В режиме whitelist публичный контент REST (posts/pages/media) закрыт для гостей; редактор работает после входа. Сначала проверьте Gutenberg и интеграции.', 'dr-slon-toolkit'); ?></p>
                </section>

                <section class="dstk-help-card">
                    <span class="dashicons dashicons-update" aria-hidden="true"></span>
                    <h2><?php echo esc_html__('Обновления из GitHub', 'dr-slon-toolkit'); ?></h2>
                    <p><?php echo esc_html__('WordPress проверяет официальный GitHub Release и устанавливает только готовый ZIP-asset (dr-slon-toolkit-x.y.z.zip). Source archives с кнопки Code не используются. Контрольная сумма и структура пакета проверяются до замены файлов.', 'dr-slon-toolkit'); ?></p>
                    <p><?php echo esc_html__('Переход с версии 0.8.2 потребует одной ручной установки. Следующие выпуски появляются в стандартном разделе обновлений WordPress.', 'dr-slon-toolkit'); ?></p>
                </section>
            </main>

            <?php $this->info_panel->render(); ?>
        </div>
        <?php
    }

    private function render_admin_header(string $current): void
    {
        $settings = Settings::all();
        $modules = isset($settings['modules']) && is_array($settings['modules']) ? $settings['modules'] : [];
        $active_modules = count(array_filter($modules));
        $tsf_active = (new SeoFrameworkDetector())->is_active();
        ?>
        <header class="dstk-hero">
            <div class="dstk-hero__content">
                <span class="dstk-eyebrow"><?php echo esc_html__('WordPress operations toolkit', 'dr-slon-toolkit'); ?></span>
                <h1><?php echo esc_html__('Dr.Slon Toolkit', 'dr-slon-toolkit'); ?></h1>
                <p><?php echo esc_html__('Практические инструменты для обслуживания, индексации и защиты клиентских сайтов.', 'dr-slon-toolkit'); ?></p>
            </div>
            <div class="dstk-hero__metrics" aria-label="<?php echo esc_attr__('Состояние плагина', 'dr-slon-toolkit'); ?>">
                <span><strong><?php echo esc_html((string) $active_modules); ?></strong> <?php echo esc_html__('модулей активно', 'dr-slon-toolkit'); ?></span>
                <span><strong><?php echo esc_html('v' . DSTK_VERSION); ?></strong> <?php echo esc_html__('GitHub Releases', 'dr-slon-toolkit'); ?></span>
                <span class="<?php echo $tsf_active ? 'is-positive' : ''; ?>"><strong>TSF</strong> <?php echo esc_html($tsf_active ? __('совместимость активна', 'dr-slon-toolkit') : __('не обнаружен', 'dr-slon-toolkit')); ?></span>
            </div>
            <nav class="dstk-tabs" aria-label="<?php echo esc_attr__('Разделы Dr.Slon Toolkit', 'dr-slon-toolkit'); ?>">
                <a class="<?php echo $current === 'settings' ? 'is-current' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>"><?php echo esc_html__('Настройки', 'dr-slon-toolkit'); ?></a>
                <a class="<?php echo $current === 'help' ? 'is-current' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=' . self::HELP_PAGE_SLUG)); ?>"><?php echo esc_html__('Помощь', 'dr-slon-toolkit'); ?></a>
            </nav>
        </header>
        <?php
    }

    private function can_manage_update_controls(): bool
    {
        return current_user_can('manage_options') && current_user_can(self::UPDATE_CONTROLS_CAPABILITY);
    }
}
