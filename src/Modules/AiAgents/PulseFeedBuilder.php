<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Modules\AiAgents;

use DrSlon\Toolkit\Core\DiscoveryIndexability;
use DrSlon\Toolkit\Core\Utf8Response;
use WP_Post;
use WP_Query;

final class PulseFeedBuilder
{
    /**
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function build(): string
    {
        $name = $this->plain_text(function_exists('get_bloginfo') ? (string) get_bloginfo('name') : 'WordPress');
        $lines = [
            '# AI Pulse · ' . ($name !== '' ? $name : 'WordPress'),
            'Generated: ' . gmdate('c'),
            'Home: ' . home_url('/'),
            '',
        ];

        foreach ($this->posts() as $post) {
            $title = $this->plain_text((string) ($post->post_title ?? ''));
            $url = get_permalink($post);
            $url = is_string($url) ? $url : '';

            if ($title === '' || $url === '') {
                continue;
            }

            $date = (string) ($post->post_date_gmt ?? '');
            $timestamp = $date !== '' && $date !== '0000-00-00 00:00:00' ? strtotime($date . ' UTC') : false;
            $excerpt = $this->excerpt($post);

            $lines[] = '## ' . $title;
            $lines[] = '- Date: ' . ($timestamp === false ? '' : gmdate('c', $timestamp));
            $lines[] = '- URL: ' . $url;

            if ($excerpt !== '') {
                $lines[] = $excerpt;
            }

            $lines[] = '';
        }

        return Utf8Response::normalize(implode("\n", $lines) . "\n");
    }

    /**
     * @return array<int, WP_Post>
     */
    private function posts(): array
    {
        $limit = isset($this->config['pulse_limit']) ? (int) $this->config['pulse_limit'] : 20;
        $limit = max(1, min(50, $limit));
        $types = isset($this->config['post_types']) && is_array($this->config['post_types'])
            ? array_values(array_filter(array_map('sanitize_key', $this->config['post_types'])))
            : ['post', 'page'];

        if ($types === []) {
            return [];
        }

        $query = new WP_Query(
            [
                'post_type'              => $types,
                'post_status'            => 'publish',
                'posts_per_page'         => $limit * 2,
                'orderby'                => 'date',
                'order'                  => 'DESC',
                'has_password'           => false,
                'ignore_sticky_posts'    => true,
                'no_found_rows'          => true,
                'update_post_meta_cache' => ! empty($this->config['exclude_noindex']),
                'update_post_term_cache' => false,
            ]
        );

        $posts = [];

        foreach ($query->posts as $post) {
            if (! ($post instanceof WP_Post)) {
                continue;
            }

            $url = get_permalink($post);
            $url = is_string($url) ? $url : '';

            if (
                ! DiscoveryIndexability::is_post_indexable(
                    $post,
                    $url,
                    [
                        'exclude_noindex' => ! empty($this->config['exclude_noindex']),
                        'noindex_filter'  => 'dstk_ai_is_noindex',
                    ]
                )
            ) {
                continue;
            }

            $posts[] = $post;

            if (count($posts) >= $limit) {
                break;
            }
        }

        return $posts;
    }

    private function excerpt(WP_Post $post): string
    {
        $source = (string) ($post->post_excerpt ?? '');

        if ($source === '') {
            $source = (string) ($post->post_content ?? '');
        }

        $text = $this->plain_text($source);

        if ($text === '') {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr') && mb_strlen($text, 'UTF-8') > 500) {
            return rtrim(mb_substr($text, 0, 500, 'UTF-8')) . '…';
        }

        if (strlen($text) > 500) {
            return rtrim(substr($text, 0, 500)) . '…';
        }

        return $text;
    }

    private function plain_text(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($value) : strip_tags($value);
        $value = preg_replace('/[ \t]+/', ' ', $value) ?? $value;

        return trim($value);
    }
}
