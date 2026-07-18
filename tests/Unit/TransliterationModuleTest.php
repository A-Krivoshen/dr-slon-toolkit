<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Tests\Unit;

use DrSlon\Toolkit\Modules\TransliterationModule;
use PHPUnit\Framework\TestCase;

final class TransliterationModuleTest extends TestCase
{
    public function test_russian_slug_profile_and_unicode_cleanup(): void
    {
        $module = new TransliterationModule();

        self::assertSame('glavnaya-stranitsa', $module->filter_sanitize_title('', 'Главная страница', 'save'));
        self::assertSame('informatsiya', $module->filter_sanitize_title('', 'Информация', 'save'));
        self::assertSame('novosti-segodnya', $module->filter_sanitize_title('', 'Новости — сегодня 😀', 'save'));
    }

    public function test_ascii_slug_is_preserved(): void
    {
        $module = new TransliterationModule();

        self::assertSame('manual-slug', $module->filter_sanitize_title('manual-slug', 'manual-slug', 'save'));
    }

    public function test_unsupported_unicode_uses_wordpress_sanitized_fallback(): void
    {
        $module = new TransliterationModule();

        self::assertSame('%e6%9d%b1%e4%ba%ac', $module->filter_sanitize_title('東京', '東京', 'save'));
        self::assertSame('%f0%9f%98%80', $module->filter_term_slug('😀'));
    }

    public function test_filename_is_ascii_and_keeps_safe_extension(): void
    {
        $module = new TransliterationModule();

        self::assertSame('foto-leta.jpg', $module->filter_file_name('Фото Лета.JPG', 'Фото Лета.JPG'));
        self::assertSame('file.jpg', $module->filter_file_name('😀.JPG', '😀.JPG'));
        self::assertSame('Annual.Report-2026.JPG', $module->filter_file_name('Annual.Report-2026.JPG', 'Annual.Report-2026.JPG'));
    }
}
