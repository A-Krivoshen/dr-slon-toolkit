<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Core;

final class Transliterator
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

    public function has_non_ascii(string $value): bool
    {
        return preg_match('/[^\x00-\x7F]/', $value) === 1;
    }

    public function has_cyrillic(string $value): bool
    {
        return preg_match('/\p{Cyrillic}/u', $value) === 1;
    }

    public function slug_has_cyrillic(string $slug): bool
    {
        return $this->has_cyrillic(rawurldecode($slug));
    }

    public function normalize(string $value): string
    {
        $transliterated = remove_accents(strtr($value, $this->map));
        $ascii = preg_replace('/[^A-Za-z0-9]+/', '-', $transliterated);

        if (! is_string($ascii)) {
            return '';
        }

        return sanitize_title_with_dashes($ascii, '', 'save');
    }
}
