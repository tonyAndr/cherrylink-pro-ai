<?php

declare(strict_types=1);

namespace CherryLink\Terms;

use WP_Term;

/**
 * Builds the taxonomy term tree (JSON) the editor panel links into, plus the flat
 * category list for its filter.
 */
final class TermsService
{
    // ── Classic panel ──────────────────────────────────────────────────────────

    /**
     * Flat category list (for the classic quick-filter select).
     *
     * @param int|string $category
     * @return array<int,array<int,string>>
     */
    public static function allCategories($category = 0, int $level = 0): array
    {
        $r    = [];
        $next = \get_terms([
            'parent'     => $category,
            'taxonomy'   => 'category',
            'hide_empty' => false,
        ]);

        if (\is_array($next) && $next) {
            foreach ($next as $cat) {
                if (! $cat instanceof WP_Term) {
                    continue;
                }

                $r[] = [$level => $cat->name];

                $r = \array_merge($r, self::allCategories($cat->term_id, $level + 1));
            }
        }

        return $r;
    }

    // ── Gutenberg sidebar ────────────────────────────────────────────────────────

    /**
     * Hierarchical term tree (nested arrays) for the Gutenberg sidebar.
     *
     * @param int|string          $category
     * @param string|array<mixed> $taxonomy
     * @return array<int,array<string,mixed>>
     */
    public static function gutenbergTree($category = 0, $taxonomy = []): array
    {
        $r    = [];
        $next = \get_terms([
            'parent'     => $category,
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'orderby'    => 'taxonomy',
            'order'      => 'ASC',
        ]);

        if (\is_array($next) && $next) {
            foreach ($next as $cat) {
                if (! $cat instanceof WP_Term || $cat->taxonomy === 'nav_menu') {
                    continue;
                }

                $cat_tax = \get_taxonomy($cat->taxonomy);

                if ($cat_tax === false || (\is_object($cat_tax) && ! $cat_tax->public)) {
                    continue;
                }

                if ($taxonomy !== $cat->taxonomy) { // if next type of taxonomy - add header/divider
                    $taxonomy = $cat->taxonomy;
                    // $cat_tax is guaranteed a WP_Taxonomy object past the === false guard above.
                    $label    = $cat_tax->label;
                    $r[]      = [
                        'name'       => $label,
                        'is_divider' => 'yes',
                    ];
                }

                $r[] = [
                    'url'        => \get_term_link($cat),
                    'name'       => $cat->name,
                    'taxonomy'   => $cat->taxonomy,
                    'post_count' => $cat->count,
                    'is_divider' => 'no',
                    'children'   => [],
                ];

                $r[\count($r) - 1]['children'] = self::gutenbergTree($cat->term_id, $taxonomy);
            }
        }

        return $r;
    }

    /**
     * Flat category tree (for the Gutenberg quick-filter).
     *
     * @param int|string $category
     * @return array<int,array<string,mixed>>
     */
    public static function gutenbergCategories($category = 0): array
    {
        $r    = [];
        $next = \get_terms([
            'parent'     => $category,
            'taxonomy'   => 'category',
            'hide_empty' => false,
        ]);

        if (\is_array($next) && $next) {
            foreach ($next as $cat) {
                if (! $cat instanceof WP_Term) {
                    continue;
                }

                $r[] = ['name' => $cat->name, 'children' => []];

                $r[\count($r) - 1]['children'] = self::gutenbergCategories($cat->term_id);
            }
        }

        return $r;
    }
}
