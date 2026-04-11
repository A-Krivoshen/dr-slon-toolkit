<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Integrations;

final class SeoFrameworkDetector
{
    public function is_active(): bool
    {
        return defined('THE_SEO_FRAMEWORK_PRESENT')
            || defined('THE_SEO_FRAMEWORK_VERSION')
            || class_exists('The_SEO_Framework\\Load', false)
            || function_exists('the_seo_framework');
    }

    public function render_notice(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        echo '<div class="notice notice-info"><p>';
        echo esc_html__('Обнаружен плагин The SEO Framework. SEO-функции в Dr.Slon Toolkit будут добавляться с учётом совместимости.', 'dr-slon-toolkit');
        echo '</p></div>';
    }
}
