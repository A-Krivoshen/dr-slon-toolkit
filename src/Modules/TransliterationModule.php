<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Modules;

use DrSlon\Toolkit\Core\ModuleInterface;

final class TransliterationModule implements ModuleInterface
{
    /**
     * @var array<string, string>
     */
    private array $map = [
        'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'H', 'Ґ' => 'G', 'Д' => 'D', 'Е' => 'E', 'Є' => 'Ye', 'Ё' => 'Yo',
        'Ж' => 'Zh', 'З' => 'Z', 'И' => 'Y', 'І' => 'I', 'Ї' => 'Yi', 'Й' => 'Y', 'К' => 'K', 'Л' => 'L', 'М' => 'M',
        'Н' => 'N', 'О' => 'O', 'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T', 'У' => 'U', 'Ф' => 'F', 'Х' => 'Kh',
        'Ц' => 'Ts', 'Ч' => 'Ch', 'Ш' => 'Sh', 'Щ' => 'Shch', 'Ъ' => '', 'Ы' => 'Y', 'Ь' => '', 'Э' => 'E', 'Ю' => 'Yu', 'Я' => 'Ya',
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'h', 'ґ' => 'g', 'д' => 'd', 'е' => 'e', 'є' => 'ye', 'ё' => 'yo',
        'ж' => 'zh', 'з' => 'z', 'и' => 'y', 'і' => 'i', 'ї' => 'yi', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
        'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'kh',
        'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch', 'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];

    public function register(): void
    {
        add_filter('sanitize_title', [$this, 'filter_sanitize_title'], 9, 3);
        add_filter('wp_unique_post_slug', [$this, 'filter_post_slug'], 10, 6);
        add_filter('pre_term_slug', [$this, 'filter_term_slug']);
        add_filter('sanitize_file_name', [$this, 'filter_file_name'], 10, 2);
    }

    public function filter_sanitize_title(string $title, string $raw_title, string $context): string
    {
        if ($context !== 'save') {
            return $title;
        }

        if (! $this->has_non_latin_characters($raw_title)) {
            return $title;
        }

        if ($title !== '' && ! $this->has_non_latin_characters($title)) {
            return $title;
        }

        return $this->normalize_slug($raw_title);
    }

    public function filter_post_slug(string $slug, int $post_id, string $post_status, string $post_type, int $post_parent, string $original_slug): string
    {
        if ($this->has_non_latin_characters($slug)) {
            return $this->normalize_slug($slug);
        }

        if ($slug === '' && $this->has_non_latin_characters($original_slug)) {
            return $this->normalize_slug($original_slug);
        }

        return $slug;
    }

    /**
     * @param string|int $slug
     */
    public function filter_term_slug($slug): string
    {
        if (! is_string($slug)) {
            return (string) $slug;
        }

        if (! $this->has_non_latin_characters($slug)) {
            return $slug;
        }

        return $this->normalize_slug($slug);
    }

    public function filter_file_name(string $filename, string $filename_raw): string
    {
        if (! $this->has_non_latin_characters($filename_raw)) {
            return $filename;
        }

        $parts = pathinfo($filename_raw);
        $name = isset($parts['filename']) ? (string) $parts['filename'] : '';
        $extension = isset($parts['extension']) ? (string) $parts['extension'] : '';

        $name = $this->normalize_slug($name);

        if ($name === '') {
            return $filename;
        }

        if ($extension === '') {
            return $name;
        }

        return $name . '.' . strtolower(sanitize_key($extension));
    }

    private function has_non_latin_characters(string $value): bool
    {
        return (bool) preg_match('/[^\x20-\x7E]/u', $value);
    }

    private function normalize_slug(string $value): string
    {
        $transliterated = strtr($value, $this->map);
        $slug = sanitize_title($transliterated);

        return $slug !== '' ? $slug : sanitize_title($value);
    }
}
