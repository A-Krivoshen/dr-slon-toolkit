<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Modules;

use DrSlon\Toolkit\Core\ModuleInterface;
use DrSlon\Toolkit\Core\Transliterator;

final class TransliterationModule implements ModuleInterface
{
    private Transliterator $transliterator;

    public function __construct(?Transliterator $transliterator = null)
    {
        $this->transliterator = $transliterator ?? new Transliterator();
    }

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

        if (! $this->transliterator->has_non_ascii($raw_title)) {
            return $title;
        }

        if ($title !== '' && ! $this->transliterator->has_non_ascii($title)) {
            return $title;
        }

        $slug = $this->transliterator->normalize($raw_title);

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

        if (! $this->transliterator->has_non_ascii($slug)) {
            return $slug;
        }

        $normalized = $this->transliterator->normalize($slug);

        return $normalized !== '' ? $normalized : sanitize_title_with_dashes($slug, '', 'save');
    }

    public function filter_file_name(string $filename, string $filename_raw): string
    {
        if (
            ! $this->transliterator->has_non_ascii($filename_raw)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $filename) === 1
        ) {
            return $filename;
        }

        $parts = pathinfo($filename);
        $name = isset($parts['filename']) ? (string) $parts['filename'] : '';
        $extension = isset($parts['extension']) ? (string) $parts['extension'] : '';

        $name = $this->transliterator->normalize($name);

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
}
