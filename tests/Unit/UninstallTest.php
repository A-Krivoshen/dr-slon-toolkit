<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class UninstallTest extends TestCase
{
    public function test_uninstall_script_cleans_ai_and_sitemap_cache_keys(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/uninstall.php');

        self::assertStringContainsString("'dstk_ai_cache_version'", $source);
        self::assertStringContainsString("'dstk_login_lockouts'", $source);
        self::assertStringContainsString("'dstk_sitemap_cache_version'", $source);
        self::assertStringContainsString('_transient_dstk_ai_doc_', $source);
        self::assertStringContainsString('_transient_dstk_sitemap_', $source);
        self::assertStringContainsString('WP_UNINSTALL_PLUGIN', $source);
    }
}
