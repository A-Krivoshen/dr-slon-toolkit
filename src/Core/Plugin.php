<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Core;

use DrSlon\Toolkit\Admin\SettingsPage;
use DrSlon\Toolkit\Integrations\GitHubReleaseUpdater;
use DrSlon\Toolkit\Integrations\SeoFrameworkDetector;
use DrSlon\Toolkit\Modules\CleanupModule;
use DrSlon\Toolkit\Modules\DisableCommentsModule;
use DrSlon\Toolkit\Modules\HideLoginModule;
use DrSlon\Toolkit\Modules\IndexNowModule;
use DrSlon\Toolkit\Modules\RestApiControlModule;
use DrSlon\Toolkit\Modules\SitemapModule;
use DrSlon\Toolkit\Modules\TransliterationModule;
use DrSlon\Toolkit\Modules\UpdateControlsModule;

final class Plugin
{
    private bool $booted = false;

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        $this->maybe_upgrade();

        $rewrite_manager = new RewriteManager();
        $rewrite_manager->register();

        $updater = new GitHubReleaseUpdater(DSTK_PLUGIN_FILE, DSTK_VERSION);
        $updater->register();

        if (is_admin()) {
            $settings_page = new SettingsPage();
            $settings_page->register();
        }

        $this->register_modules();
    }

    private function maybe_upgrade(): void
    {
        $installed_version = (string) get_option('dstk_version', '');

        if ($installed_version === DSTK_VERSION) {
            return;
        }

        update_option('dstk_version', DSTK_VERSION);
        RewriteManager::schedule();
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
            'sitemap'          => new SitemapModule(),
            'update_controls'  => new UpdateControlsModule(),
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
