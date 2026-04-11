<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Core;

final class Plugin
{
    private static ?self $instance = null;

    private bool $booted = false;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        if (is_admin()) {
            add_action('admin_menu', [$this, 'register_admin_menu']);
            add_action('admin_init', [$this, 'register_settings']);
        }
    }

    public function register_admin_menu(): void
    {
        add_menu_page(
            __('Dr.Slon Toolkit', 'dr-slon-toolkit'),
            __('Dr.Slon Toolkit', 'dr-slon-toolkit'),
            'manage_options',
            'dr-slon-toolkit',
            [$this, 'render_settings_page'],
            'dashicons-admin-tools',
            58
        );
    }

    public function register_settings(): void
    {
        register_setting(
            'dstk_settings_group',
            'dstk_settings',
            [
                'type'              => 'array',
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default'           => $this->get_default_settings(),
            ]
        );

        add_settings_section(
            'dstk_general_section',
            __('Initial modules', 'dr-slon-toolkit'),
            '__return_false',
            'dr-slon-toolkit'
        );

        add_settings_field(
            'dstk_modules',
            __('Enable modules', 'dr-slon-toolkit'),
            [$this, 'render_modules_field'],
            'dr-slon-toolkit',
            'dstk_general_section'
        );
    }

    public function sanitize_settings($input): array
    {
        $defaults = $this->get_default_settings();

        if (! is_array($input)) {
            return $defaults;
        }

        return [
            'transliteration'  => ! empty($input['transliteration']),
            'disable_comments' => ! empty($input['disable_comments']),
            'cleanup'          => ! empty($input['cleanup']),
        ];
    }

    public function render_modules_field(): void
    {
        $settings = wp_parse_args(
            (array) get_option('dstk_settings', []),
            $this->get_default_settings()
        );
        ?>
        <fieldset>
            <label>
                <input type="checkbox" name="dstk_settings[transliteration]" value="1" <?php checked(! empty($settings['transliteration'])); ?>>
                <?php echo esc_html__('Transliteration', 'dr-slon-toolkit'); ?>
            </label>
            <br>

            <label>
                <input type="checkbox" name="dstk_settings[disable_comments]" value="1" <?php checked(! empty($settings['disable_comments'])); ?>>
                <?php echo esc_html__('Disable Comments', 'dr-slon-toolkit'); ?>
            </label>
            <br>

            <label>
                <input type="checkbox" name="dstk_settings[cleanup]" value="1" <?php checked(! empty($settings['cleanup'])); ?>>
                <?php echo esc_html__('Cleanup', 'dr-slon-toolkit'); ?>
            </label>

            <p class="description">
                <?php echo esc_html__('This is the initial bootstrap screen. Functional modules will be connected step by step.', 'dr-slon-toolkit'); ?>
            </p>
        </fieldset>
        <?php
    }

    public function render_settings_page(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Dr.Slon Toolkit', 'dr-slon-toolkit'); ?></h1>

            <p><?php echo esc_html__('Modular WordPress toolkit for client websites.', 'dr-slon-toolkit'); ?></p>

            <form method="post" action="options.php">
                <?php
                settings_fields('dstk_settings_group');
                do_settings_sections('dr-slon-toolkit');
                submit_button(__('Save Changes', 'dr-slon-toolkit'));
                ?>
            </form>
        </div>
        <?php
    }

    private function get_default_settings(): array
    {
        return [
            'transliteration'  => false,
            'disable_comments' => false,
            'cleanup'          => false,
        ];
    }
}
