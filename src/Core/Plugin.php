<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Core;

use DrSlon\Toolkit\Admin\SettingsPage;
use DrSlon\Toolkit\Integrations\SeoFrameworkDetector;
use DrSlon\Toolkit\Modules\CleanupModule;
use DrSlon\Toolkit\Modules\DisableCommentsModule;
use DrSlon\Toolkit\Modules\HideLoginModule;
use DrSlon\Toolkit\Modules\IndexNowModule;
use DrSlon\Toolkit\Modules\RestApiControlModule;
use DrSlon\Toolkit\Modules\TransliterationModule;

final class Plugin
{
    private bool $booted = false;

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        if (is_admin()) {
            $settings_page = new SettingsPage();
            $settings_page->register();
        }

        $this->register_modules();
    }

    private function register_modules(): void
    {
        $modules = [
            'transliteration'  => new TransliterationModule(),
            'disable_comments' => new DisableCommentsModule(),
            'cleanup'          => new CleanupModule(),
            'hide_login'       => new HideLoginModule(),
            'rest_api_control' => new RestApiControlModule(),
            'indexnow'         => new IndexNowModule(),
        ];

        foreach ($modules as $slug => $module) {
            if (! Settings::module_enabled($slug)) {
                continue;
            }

            $module->register();
        }

        if (is_admin()) {
            $detector = new SeoFrameworkDetector();

            if ($detector->is_active()) {
                add_action('admin_notices', [$detector, 'render_notice']);
            }
        }
    }
}
