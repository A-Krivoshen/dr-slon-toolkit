<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Modules\AiAgents;

use DrSlon\Toolkit\Core\DiscoveryIndexability;
use DrSlon\Toolkit\Core\Settings;
use DrSlon\Toolkit\Core\Utf8Response;
use DrSlon\Toolkit\Integrations\SeoFrameworkDetector;
use WP_Post;
use WP_Query;

final class DocumentBuilder
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

    public function build(string $kind): string
    {
        $body = match ($kind) {
            'ai'        => $this->build_ai(),
            'llms'      => $this->build_llms(false),
            'llms_full' => $this->build_llms(true),
            'agents'    => $this->build_agents(),
            default     => '',
        };

        return Utf8Response::normalize($body);
    }

    private function build_ai(): string
    {
        $name = $this->site_name();
        $home = $this->home_url();
        $lines = [
            '# ' . $name,
            '# ' . $home,
        ];

        if ($this->endpoint_enabled('llms_full')) {
            $lines[] = '# Full: ' . $this->endpoint_url('llms_full');
        }

        if ($this->endpoint_enabled('agents_md')) {
            $lines[] = '# Instructions: ' . $this->endpoint_url('agents');
        }

        $lines[] = '';
        $lines[] = '> ' . $this->blurb();
        $lines[] = '';
        $lines[] = '## Quick links';

        foreach ($this->quick_links() as $label => $url) {
            $lines[] = '- ' . $label . ': ' . $url;
        }

        $contacts = $this->text_field('contacts');

        if ($contacts !== '') {
            $lines[] = '';
            $lines[] = '## Contacts';
            $lines[] = $contacts;
        }

        $lines[] = '';
        $lines[] = 'language: ' . $this->language();

        return implode("\n", $lines) . "\n";
    }

    private function build_llms(bool $full): string
    {
        $name = $this->site_name();
        $home = $this->home_url();
        $lines = [
            '# ' . $name,
            '',
            $this->blurb(),
            '',
            'Site: ' . $home . ' · Language: ' . $this->language(),
        ];

        foreach (['facts' => 'Facts', 'contacts' => 'Contacts', 'ai_policy' => 'Policy', 'do_not_invent' => 'Do not invent'] as $key => $heading) {
            $text = $this->text_field($key);

            if ($text === '') {
                continue;
            }

            $lines[] = '';
            $lines[] = '## ' . $heading;
            $lines[] = $text;
        }

        $pages = $this->collect_posts('page', $full ? min(40, $this->full_limit()) : 20);
        $posts = $this->collect_posts('post', $full ? $this->full_limit() : 10);

        if ($pages !== []) {
            $lines[] = '';
            $lines[] = '## Pages';
            $lines = array_merge($lines, $this->render_post_list($pages, $full));
        }

        if ($posts !== []) {
            $lines[] = '';
            $lines[] = '## Posts';
            $lines = array_merge($lines, $this->render_post_list($posts, $full));
        }

        $lines[] = '';
        $lines[] = '## Documents';

        foreach ($this->document_links() as $label => $url) {
            $lines[] = '- [' . $label . '](' . $url . ')';
        }

        $sitemap = $this->sitemap_url();

        if ($sitemap !== '') {
            $lines[] = '- [Sitemap](' . $sitemap . ')';
        }

        return implode("\n", $lines) . "\n";
    }

    private function build_agents(): string
    {
        $lines = [
            '# Agent instructions · ' . $this->site_name(),
            '',
            'Load public context in this order. Prefer UTF-8 text/markdown over HTML scrape.',
            '',
        ];

        $order = [];

        foreach (
            [
                'ai'        => 'Short index',
                'llms'      => 'Site facts',
                'llms_full' => 'Extended dump',
                'pulse'     => 'Recent pulse',
            ] as $kind => $label
        ) {
            $url = $this->endpoint_url($kind);

            if ($url === '') {
                continue;
            }

            $order[] = '- ' . $label . ': ' . $url;
        }

        $sitemap = $this->sitemap_url();

        if ($sitemap !== '') {
            $order[] = '- Sitemap: ' . $sitemap;
        }

        $order[] = '- Single URL: fetch only a specific public page when the documents above are not enough';

        $lines = array_merge($lines, $order);

        $policy = $this->text_field('ai_policy');
        $invent = $this->text_field('do_not_invent');

        if ($policy !== '') {
            $lines[] = '';
            $lines[] = '## Policy';
            $lines[] = $policy;
        }

        if ($invent !== '') {
            $lines[] = '';
            $lines[] = '## Do not invent';
            $lines[] = $invent;
        }

        $lines[] = '';
        $lines[] = '## Public endpoints';

        foreach ($this->document_links() as $label => $url) {
            $lines[] = '- ' . $label . ' — ' . $url;
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return array<int, WP_Post>
     */
    private function collect_posts(string $preferred_type, int $limit): array
    {
        $types = $this->post_types();

        if ($limit < 1 || ! in_array($preferred_type, $types, true)) {
            return [];
        }

        $orderby = $preferred_type === 'page' ? 'menu_order title' : 'date';
        $query = new WP_Query(
            [
                'post_type'              => $preferred_type,
                'post_status'            => 'publish',
                'posts_per_page'         => $limit * 2,
                'orderby'                => $orderby,
                'order'                  => $preferred_type === 'page' ? 'ASC' : 'DESC',
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

            if (! $this->is_allowed_post($post)) {
                continue;
            }

            $posts[] = $post;

            if (count($posts) >= $limit) {
                break;
            }
        }

        return $posts;
    }

    /**
     * @param array<int, WP_Post> $posts
     * @return array<int, string>
     */
    private function render_post_list(array $posts, bool $full): array
    {
        $lines = [];

        foreach ($posts as $post) {
            $url = $this->post_url($post);
            $title = $this->post_title($post);

            if ($url === '' || $title === '') {
                continue;
            }

            if (! $full) {
                $lines[] = '- [' . $title . '](' . $url . ')';
                continue;
            }

            $lines[] = '### ' . $title;
            $lines[] = '- Date: ' . $this->post_date($post);
            $lines[] = '- URL: ' . $url;
            $excerpt = $this->post_excerpt($post, 800);

            if ($excerpt !== '') {
                $lines[] = $excerpt;
            }

            $lines[] = '';
        }

        return $lines;
    }

    private function is_allowed_post(WP_Post $post): bool
    {
        $url = $this->post_url($post);

        return DiscoveryIndexability::is_post_indexable(
            $post,
            $url,
            [
                'exclude_noindex' => ! empty($this->config['exclude_noindex']),
                'noindex_filter'  => 'dstk_ai_is_noindex',
            ]
        );
    }

    private function post_url(WP_Post $post): string
    {
        $url = get_permalink($post);

        return is_string($url) ? $url : '';
    }

    private function post_title(WP_Post $post): string
    {
        $title = isset($post->post_title) ? (string) $post->post_title : '';

        return $this->plain_text($title);
    }

    private function post_date(WP_Post $post): string
    {
        $raw = isset($post->post_date_gmt) ? (string) $post->post_date_gmt : '';

        if ($raw === '' || $raw === '0000-00-00 00:00:00') {
            $raw = isset($post->post_modified_gmt) ? (string) $post->post_modified_gmt : '';
        }

        $timestamp = $raw !== '' ? strtotime($raw . ' UTC') : false;

        return $timestamp === false ? '' : gmdate('c', $timestamp);
    }

    private function post_excerpt(WP_Post $post, int $max_length): string
    {
        $source = isset($post->post_excerpt) ? (string) $post->post_excerpt : '';

        if ($source === '') {
            $source = isset($post->post_content) ? (string) $post->post_content : '';
        }

        $text = $this->plain_text($source);

        if ($text === '') {
            return '';
        }

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') > $max_length) {
                $text = rtrim(mb_substr($text, 0, $max_length, 'UTF-8')) . '…';
            }

            return $text;
        }

        if (strlen($text) > $max_length) {
            $text = rtrim(substr($text, 0, $max_length)) . '…';
        }

        return $text;
    }

    private function plain_text(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($value) : strip_tags($value);
        $value = preg_replace('/[ \t]+/', ' ', $value) ?? $value;
        $value = preg_replace('/\n{3,}/', "\n\n", $value) ?? $value;

        return trim($value);
    }

    private function text_field(string $key): string
    {
        $value = isset($this->config[$key]) ? (string) $this->config[$key] : '';

        return $this->plain_text($value);
    }

    private function blurb(): string
    {
        $manual = $this->text_field('site_blurb');

        if ($manual !== '') {
            return $manual;
        }

        $description = $this->bloginfo('description');

        return $description !== '' ? $description : $this->site_name();
    }

    private function site_name(): string
    {
        $name = $this->plain_text($this->bloginfo('name'));

        return $name !== '' ? $name : 'WordPress';
    }

    private function language(): string
    {
        $language = $this->plain_text($this->bloginfo('language'));

        return $language !== '' ? $language : 'en';
    }

    private function bloginfo(string $show): string
    {
        if (function_exists('get_bloginfo')) {
            return (string) get_bloginfo($show);
        }

        return '';
    }

    private function home_url(): string
    {
        return home_url('/');
    }

    /**
     * @return array<string, string>
     */
    private function quick_links(): array
    {
        $links = ['Home' => $this->home_url()];

        foreach ($this->document_links() as $label => $url) {
            $links[$label] = $url;
        }

        $sitemap = $this->sitemap_url();

        if ($sitemap !== '') {
            $links['Sitemap'] = $sitemap;
        }

        return $links;
    }

    /**
     * @return array<string, string>
     */
    private function document_links(): array
    {
        $map = [
            'ai'        => 'ai.txt',
            'llms'      => 'llms.txt',
            'llms_full' => 'llms-full.txt',
            'agents'    => 'agents.md',
            'pulse'     => 'AI pulse',
        ];
        $links = [];

        foreach ($map as $kind => $label) {
            $url = $this->endpoint_url($kind);

            if ($url !== '') {
                $links[$label] = $url;
            }
        }

        return $links;
    }

    public function endpoint_url(string $kind): string
    {
        $toggle = match ($kind) {
            'ai'        => 'ai_txt',
            'llms'      => 'llms_txt',
            'llms_full' => 'llms_full',
            'agents'    => 'agents_md',
            'pulse'     => 'pulse_md',
            default     => '',
        };

        if ($toggle === '' || ! $this->endpoint_enabled($toggle)) {
            return '';
        }

        return match ($kind) {
            'ai'        => home_url('/ai.txt'),
            'llms'      => home_url('/llms.txt'),
            'llms_full' => home_url('/llms-full.txt'),
            'agents'    => home_url('/agents.md'),
            'pulse'     => home_url('/feed/ai-pulse.md'),
            default     => '',
        };
    }

    public function endpoint_enabled(string $toggle): bool
    {
        return ! empty($this->config[$toggle]);
    }

    /**
     * @return array<int, string>
     */
    private function post_types(): array
    {
        $types = isset($this->config['post_types']) && is_array($this->config['post_types'])
            ? $this->config['post_types']
            : ['post', 'page'];

        return array_values(array_filter(array_map('sanitize_key', $types)));
    }

    private function full_limit(): int
    {
        $limit = isset($this->config['full_posts_limit']) ? (int) $this->config['full_posts_limit'] : 30;

        return max(1, min(100, $limit));
    }

    private function sitemap_url(): string
    {
        $settings = Settings::all();
        $detector = new SeoFrameworkDetector();

        if (! empty($settings['modules']['sitemap']) && ! empty($settings['sitemap']['enabled']) && ! $detector->is_sitemap_served()) {
            return home_url('/sitemap.xml');
        }

        if ($detector->is_sitemap_served()) {
            return home_url('/sitemap.xml');
        }

        return '';
    }
}
