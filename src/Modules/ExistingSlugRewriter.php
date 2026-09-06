<?php

declare(strict_types=1);

namespace DrSlon\Toolkit\Modules;

use DrSlon\Toolkit\Core\CacheVersion;
use DrSlon\Toolkit\Core\Transliterator;
use WP_Post;

final class ExistingSlugRewriter
{
    public const REDIRECTS_OPTION = 'dstk_translit_redirects';
    public const BATCH_SIZE = 100;
    public const MAX_REDIRECTS = 2000;

    private Transliterator $transliterator;

    public function __construct(?Transliterator $transliterator = null)
    {
        $this->transliterator = $transliterator ?? new Transliterator();
    }

    public function register(): void
    {
        add_action('template_redirect', [$this, 'maybe_redirect'], 2);
    }

    public function maybe_redirect(): void
    {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return;
        }

        $method = isset($_SERVER['REQUEST_METHOD'])
            ? strtoupper(sanitize_key(wp_unslash((string) $_SERVER['REQUEST_METHOD'])))
            : 'GET';

        if (! in_array($method, ['GET', 'HEAD'], true)) {
            return;
        }

        $match = $this->match($this->request_path());

        if ($match === null) {
            return;
        }

        wp_safe_redirect($match['to'], 301);
        exit;
    }

    /**
     * @return array{from:string,to:string,status:int}|null
     */
    public function match(string $path): ?array
    {
        $path = $this->normalize_path($path);

        if ($path === '') {
            return null;
        }

        foreach ($this->saved_redirects() as $rule) {
            if ($rule['from'] === $path) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @return array{items:list<array<string,mixed>>,batch:int,has_more:bool}
     */
    public function preview(int $sample = 12): array
    {
        $plan = $this->plan(self::BATCH_SIZE + 1);
        $has_more = count($plan) > self::BATCH_SIZE;
        $plan = array_slice($plan, 0, self::BATCH_SIZE);

        return [
            'items'    => array_slice($plan, 0, max(1, $sample)),
            'batch'    => count($plan),
            'has_more' => $has_more,
        ];
    }

    /**
     * @return array{updated:int,skipped:int,redirects_added:int,has_more:bool}
     */
    public function apply(bool $add_redirects): array
    {
        $plan = $this->plan(self::BATCH_SIZE + 1);
        $has_more = count($plan) > self::BATCH_SIZE;
        $plan = array_slice($plan, 0, self::BATCH_SIZE);

        $snapshots = $add_redirects ? $this->snapshot_urls($plan) : [];
        $updated = 0;
        $skipped = 0;

        foreach ($plan as $item) {
            $result = $item['kind'] === 'term'
                ? $this->update_term($item)
                : $this->update_post($item);

            if ($result === null) {
                ++$skipped;
                continue;
            }

            ++$updated;
        }

        $redirects_added = 0;

        if ($add_redirects && $updated > 0) {
            $redirects_added = $this->persist_redirects($snapshots);
        }

        if ($updated > 0) {
            CacheVersion::bump(CacheVersion::SITEMAP);
            CacheVersion::bump(CacheVersion::AI);
        }

        return [
            'updated'          => $updated,
            'skipped'          => $skipped,
            'redirects_added'  => $redirects_added,
            'has_more'         => $has_more,
        ];
    }

    public function proposed_slug(string $slug, string $fallback = ''): string
    {
        $next = $this->transliterator->normalize(rawurldecode($slug));

        if ($next === '' && $fallback !== '') {
            $next = $this->transliterator->normalize($fallback);
        }

        return $next;
    }

    /**
     * @return list<array{from:string,to:string,status:int}>
     */
    public function saved_redirects(): array
    {
        $raw = get_option(self::REDIRECTS_OPTION, []);

        if (! is_array($raw)) {
            return [];
        }

        $clean = [];

        foreach ($raw as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $parsed = RedirectManagerModule::sanitize_rule(
                (string) ($rule['from'] ?? ''),
                (string) ($rule['to'] ?? ''),
                301
            );

            if ($parsed !== null) {
                $clean[$parsed['from']] = $parsed;
            }
        }

        return array_values($clean);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function plan(int $limit): array
    {
        $limit = max(1, $limit);
        $terms = $this->collect_terms($limit);
        $remaining = $limit - count($terms);
        $posts = $remaining > 0 ? $this->collect_posts($remaining) : [];

        return array_merge($terms, $posts);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collect_posts(int $limit): array
    {
        $items = [];
        $offset = 0;
        $page_size = 100;
        $types = $this->target_post_types();

        if ($types === []) {
            return [];
        }

        for ($page = 0; $page < 40 && count($items) < $limit; ++$page) {
            $posts = get_posts(
                [
                    'numberposts'      => $page_size,
                    'offset'           => $offset,
                    'post_type'        => $types,
                    'post_status'      => ['publish', 'future', 'draft', 'pending', 'private'],
                    'orderby'          => 'parent ID',
                    'order'            => 'ASC',
                    'suppress_filters' => true,
                ]
            );

            if (! is_array($posts) || $posts === []) {
                break;
            }

            foreach ($posts as $post) {
                if (! $post instanceof WP_Post) {
                    continue;
                }

                $planned = $this->plan_post($post);

                if ($planned === null) {
                    continue;
                }

                $items[] = $planned;

                if (count($items) >= $limit) {
                    break;
                }
            }

            $offset += count($posts);

            if (count($posts) < $page_size) {
                break;
            }
        }

        usort(
            $items,
            static function (array $left, array $right): int {
                return $left['parent'] <=> $right['parent'] ?: $left['id'] <=> $right['id'];
            }
        );

        return $items;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function plan_post(WP_Post $post): ?array
    {
        $slug = (string) $post->post_name;

        if (! $this->transliterator->slug_has_cyrillic($slug)) {
            return null;
        }

        $proposed = $this->proposed_slug($slug, (string) $post->post_title);

        if ($proposed === '' || $proposed === $slug) {
            return null;
        }

        return [
            'kind'     => 'post',
            'id'       => (int) $post->ID,
            'title'    => (string) $post->post_title,
            'type'     => (string) $post->post_type,
            'status'   => (string) $post->post_status,
            'parent'   => (int) $post->post_parent,
            'old_slug' => $slug,
            'new_slug' => $proposed,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collect_terms(int $limit): array
    {
        $taxonomies = $this->target_taxonomies();

        if ($taxonomies === []) {
            return [];
        }

        $terms = get_terms(
            [
                'taxonomy'   => $taxonomies,
                'hide_empty' => false,
                'number'     => 400,
            ]
        );

        if (! is_array($terms)) {
            return [];
        }

        $items = [];

        foreach ($terms as $term) {
            if (! is_object($term)) {
                continue;
            }

            $planned = $this->plan_term($term);

            if ($planned === null) {
                continue;
            }

            $items[] = $planned;

            if (count($items) >= $limit) {
                break;
            }
        }

        usort(
            $items,
            static function (array $left, array $right): int {
                return $left['parent'] <=> $right['parent'] ?: $left['id'] <=> $right['id'];
            }
        );

        return $items;
    }

    /**
     * @param object $term
     * @return array<string, mixed>|null
     */
    public function plan_term(object $term): ?array
    {
        $slug = (string) ($term->slug ?? '');

        if (! $this->transliterator->slug_has_cyrillic($slug)) {
            return null;
        }

        $proposed = $this->proposed_slug($slug, (string) ($term->name ?? ''));

        if ($proposed === '' || $proposed === $slug) {
            return null;
        }

        return [
            'kind'     => 'term',
            'id'       => (int) ($term->term_id ?? 0),
            'title'    => (string) ($term->name ?? ''),
            'type'     => (string) ($term->taxonomy ?? ''),
            'taxonomy' => (string) ($term->taxonomy ?? ''),
            'parent'   => (int) ($term->parent ?? 0),
            'old_slug' => $slug,
            'new_slug' => $proposed,
        ];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function update_post(array $item): ?string
    {
        $post = get_post((int) $item['id']);

        if (! $post instanceof WP_Post) {
            return null;
        }

        $slug = wp_unique_post_slug(
            (string) $item['new_slug'],
            (int) $post->ID,
            (string) $post->post_status,
            (string) $post->post_type,
            (int) $post->post_parent
        );

        if ($slug === '' || $slug === (string) $post->post_name) {
            return null;
        }

        $result = wp_update_post(
            [
                'ID'        => (int) $post->ID,
                'post_name' => $slug,
            ],
            true
        );

        if (is_wp_error($result) || (int) $result === 0) {
            return null;
        }

        return $slug;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function update_term(array $item): ?string
    {
        $term = get_term((int) $item['id'], (string) ($item['taxonomy'] ?? ''));

        if (! is_object($term) || is_wp_error($term)) {
            return null;
        }

        $slug = wp_unique_term_slug((string) $item['new_slug'], $term);

        if ($slug === '' || $slug === (string) ($term->slug ?? '')) {
            return null;
        }

        $result = wp_update_term(
            (int) $item['id'],
            (string) ($item['taxonomy'] ?? ''),
            ['slug' => $slug]
        );

        if (is_wp_error($result) || ! is_array($result)) {
            return null;
        }

        return $slug;
    }

    /**
     * @param list<array<string, mixed>> $plan
     * @return list<array<string, mixed>>
     */
    private function snapshot_urls(array $plan): array
    {
        $seen = [];
        $snapshots = [];

        foreach ($plan as $item) {
            if (($item['kind'] ?? '') === 'term') {
                $this->push_snapshot(
                    $snapshots,
                    $seen,
                    [
                        'kind'     => 'term',
                        'id'       => (int) $item['id'],
                        'taxonomy' => (string) ($item['taxonomy'] ?? ''),
                    ],
                    $this->term_url((int) $item['id'], (string) ($item['taxonomy'] ?? ''))
                );

                $related = get_posts(
                    [
                        'numberposts'      => 80,
                        'post_type'        => $this->target_post_types(),
                        'post_status'      => 'publish',
                        'suppress_filters' => true,
                        'tax_query'        => [
                            [
                                'taxonomy' => (string) ($item['taxonomy'] ?? ''),
                                'field'    => 'term_id',
                                'terms'    => [(int) $item['id']],
                            ],
                        ],
                    ]
                );

                foreach (is_array($related) ? $related : [] as $post) {
                    if ($post instanceof WP_Post) {
                        $this->push_snapshot(
                            $snapshots,
                            $seen,
                            ['kind' => 'post', 'id' => (int) $post->ID],
                            $this->post_url((int) $post->ID)
                        );
                    }
                }

                continue;
            }

            $this->push_snapshot(
                $snapshots,
                $seen,
                ['kind' => 'post', 'id' => (int) $item['id']],
                $this->post_url((int) $item['id'])
            );

            $children = get_posts(
                [
                    'numberposts'      => 50,
                    'post_parent'      => (int) $item['id'],
                    'post_type'        => $this->target_post_types(),
                    'post_status'      => 'publish',
                    'suppress_filters' => true,
                ]
            );

            foreach (is_array($children) ? $children : [] as $child) {
                if ($child instanceof WP_Post) {
                    $this->push_snapshot(
                        $snapshots,
                        $seen,
                        ['kind' => 'post', 'id' => (int) $child->ID],
                        $this->post_url((int) $child->ID)
                    );
                }
            }
        }

        return $snapshots;
    }

    /**
     * @param list<array<string, mixed>> $snapshots
     * @param array<string, true> $seen
     * @param array<string, mixed> $identity
     */
    private function push_snapshot(array &$snapshots, array &$seen, array $identity, string $url): void
    {
        $from = $this->normalize_path($url);

        if ($from === '' || $from === '/' || isset($seen[$from])) {
            return;
        }

        if (! $this->transliterator->has_cyrillic(rawurldecode($from))) {
            return;
        }

        $seen[$from] = true;
        $identity['from'] = $from;
        $snapshots[] = $identity;
    }

    /**
     * @param list<array<string, mixed>> $snapshots
     */
    private function persist_redirects(array $snapshots): int
    {
        $incoming = [];

        foreach ($snapshots as $snapshot) {
            $new_url = ($snapshot['kind'] ?? '') === 'term'
                ? $this->term_url((int) $snapshot['id'], (string) ($snapshot['taxonomy'] ?? ''))
                : $this->post_url((int) $snapshot['id']);

            if ($new_url === '') {
                continue;
            }

            $rule = RedirectManagerModule::sanitize_rule((string) ($snapshot['from'] ?? ''), $new_url, 301);

            if ($rule !== null) {
                $incoming[] = $rule;
            }
        }

        return $this->merge_saved_redirects($incoming);
    }

    /**
     * @param list<array{from:string,to:string,status:int}> $incoming
     */
    private function merge_saved_redirects(array $incoming): int
    {
        $by_from = [];

        foreach ($this->saved_redirects() as $rule) {
            $by_from[$rule['from']] = $rule;
        }

        $added = 0;

        foreach ($incoming as $rule) {
            $is_new = ! isset($by_from[$rule['from']]) || $by_from[$rule['from']]['to'] !== $rule['to'];
            $by_from[$rule['from']] = $rule;

            if ($is_new) {
                ++$added;
            }
        }

        foreach ($by_from as $from => $rule) {
            $target = $this->normalize_path($rule['to']);
            $guard = 0;

            while ($target !== '' && isset($by_from[$target]) && $guard < 8) {
                $rule['to'] = $by_from[$target]['to'];
                $target = $this->normalize_path($rule['to']);
                ++$guard;
            }

            if ($this->normalize_path($rule['to']) === $from) {
                unset($by_from[$from]);
                continue;
            }

            $by_from[$from] = $rule;
        }

        $rules = array_values($by_from);

        if (count($rules) > self::MAX_REDIRECTS) {
            $rules = array_slice($rules, -self::MAX_REDIRECTS);
        }

        update_option(self::REDIRECTS_OPTION, $rules, false);

        return $added;
    }

    /**
     * @return list<string>
     */
    private function target_post_types(): array
    {
        $types = get_post_types(['public' => true], 'names');

        if (! is_array($types)) {
            return [];
        }

        unset($types['revision'], $types['nav_menu_item']);

        return array_values(array_map('strval', $types));
    }

    /**
     * @return list<string>
     */
    private function target_taxonomies(): array
    {
        $taxonomies = [];

        foreach (get_taxonomies([], 'objects') as $name => $object) {
            if (is_object($object) && is_taxonomy_viewable($object)) {
                $taxonomies[] = (string) $name;
            }
        }

        return $taxonomies;
    }

    private function post_url(int $post_id): string
    {
        $url = get_permalink($post_id);

        return is_string($url) ? $url : '';
    }

    private function term_url(int $term_id, string $taxonomy): string
    {
        $url = get_term_link($term_id, $taxonomy);

        return is_string($url) && ! is_wp_error($url) ? $url : '';
    }

    private function request_path(): string
    {
        $request_uri = isset($_SERVER['REQUEST_URI'])
            ? esc_url_raw(wp_unslash((string) $_SERVER['REQUEST_URI']))
            : '';
        $path = is_string($request_uri) ? (string) wp_parse_url($request_uri, PHP_URL_PATH) : '';

        return $this->normalize_path($path);
    }

    private function normalize_path(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $value) === 1) {
            $path = wp_parse_url($value, PHP_URL_PATH);
            $value = is_string($path) ? $path : '';
        }

        $rule = RedirectManagerModule::sanitize_rule($value, home_url('/dstk-translit-target'), 301);

        return is_array($rule) ? $rule['from'] : '';
    }
}
