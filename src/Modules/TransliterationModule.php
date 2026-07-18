<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Modules;

use DrSlon\Toolkit\Core\ModuleInterface;

final class TransliterationModule implements ModuleInterface
{
    /**
     * Practical Russian URL profile. Shared letters deliberately use Russian
     * forms (Г => g, И => i). Ukrainian-only Ґ/Є/І/Ї use g/ye/i/yi; the
     * module does not attempt language detection.
     *
     * @var array<string, string>
     */
    private array $map = [
        'А' => 'a', 'Б' => 'b', 'В' => 'v', 'Г' => 'g', 'Ґ' => 'g', 'Д' => 'd', 'Е' => 'e', 'Є' => 'ye', 'Ё' => 'yo',
        'Ж' => 'zh', 'З' => 'z', 'И' => 'i', 'І' => 'i', 'Ї' => 'yi', 'Й' => 'y', 'К' => 'k', 'Л' => 'l', 'М' => 'm',
        'Н' => 'n', 'О' => 'o', 'П' => 'p', 'Р' => 'r', 'С' => 's', 'Т' => 't', 'У' => 'u', 'Ф' => 'f', 'Х' => 'kh',
        'Ц' => 'ts', 'Ч' => 'ch', 'Ш' => 'sh', 'Щ' => 'shch', 'Ъ' => '', 'Ы' => 'y', 'Ь' => '', 'Э' => 'e', 'Ю' => 'yu', 'Я' => 'ya',
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'ґ' => 'g', 'д' => 'd', 'е' => 'e', 'є' => 'ye', 'ё' => 'yo',
        'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'і' => 'i', 'ї' => 'yi', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
        'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'kh',
        'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];

    public function register(): void
    {
        add_filter('sanitize_title', [$this, 'filter_sanitize_title'], 9, 3);
        add_filter('pre_term_slug', [$this, 'filter_term_slug']);
        add_filter('sanitize_file_name', [$this, 'filter_file_name'], 10, 2);
    }

    public function filter_sanitize_title(string $title, string $raw_title, string $context): string
    {
        if ($context !== 'save') {
            return $title;
        }

        if (! $this->has_non_ascii_characters($raw_title)) {
            return $title;
        }

        if ($title !== '' && ! $this->has_non_ascii_characters($title)) {
            return $title;
        }

        $slug = $this->normalize_slug($raw_title);

        if ($slug !== '') {
            return $slug;
        }

        $fallback = sanitize_title_with_dashes($raw_title, '', 'save');

        return $fallback !== '' ? $fallback : $title;
    }

    /**
     * @param string|int $slug
     */
    public function filter_term_slug($slug): string
    {
        if (! is_string($slug)) {
            return (string) $slug;
        }

        if (! $this->has_non_ascii_characters($slug)) {
            return $slug;
        }

        $normalized = $this->normalize_slug($slug);

        return $normalized !== '' ? $normalized : sanitize_title_with_dashes($slug, '', 'save');
    }

    public function filter_file_name(string $filename, string $filename_raw): string
    {
        if (
            ! $this->has_non_ascii_characters($filename_raw)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $filename) === 1
        ) {
            return $filename;
        }

        $parts = pathinfo($filename);
        $name = isset($parts['filename']) ? (string) $parts['filename'] : '';
        $extension = isset($parts['extension']) ? (string) $parts['extension'] : '';

        $name = $this->normalize_slug($name);

        if ($name === '') {
            $name = 'file';
        }

        if ($extension === '') {
            return $name;
        }

        $extension = strtolower($extension);

        if (preg_match('/^[a-z0-9]+$/D', $extension) !== 1) {
            return $name;
        }

        return $name . '.' . $extension;
    }

    private function has_non_ascii_characters(string $value): bool
    {
        return preg_match('/[^\x00-\x7F]/', $value) === 1;
    }

    private function normalize_slug(string $value): string
    {
        $transliterated = remove_accents(strtr($value, $this->map));
        $ascii = preg_replace('/[^A-Za-z0-9]+/', '-', $transliterated);

        if (! is_string($ascii)) {
            return '';
        }

        return sanitize_title_with_dashes($ascii, '', 'save');
    }
}
