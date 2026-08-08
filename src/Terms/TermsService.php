<?php

declare(strict_types=1);

namespace CherryLink\Terms;

use CherryLink\Ajax\Security;
use CherryLink\Contracts\Registerable;
use WP_Term;

/**
 * Builds the taxonomy term trees the editor panels link into — the classic panel's
 * nested HTML list and the Gutenberg sidebar's JSON tree — plus the quick category
 * filters. Ported verbatim from the legacy cherrylink_terms.php (now a shim that
 * keeps hierarchical_term_tree()/linkate_get_all_categories() as delegators, since
 * the classic-panel template and the editor UI call them directly).
 */
final class TermsService implements Registerable
{
    public function register(): void
    {
        \add_action('wp_ajax_linkate_gutenberg_hierarchical_terms_json', [$this, 'ajaxGutenbergTree']);
        \add_action('wp_ajax_linkate_get_all_categories_json', [$this, 'ajaxGutenbergCategories']);
    }

    // ── AJAX (Gutenberg) ───────────────────────────────────────────────────────

    public function ajaxGutenbergTree(): void
    {
        Security::verify('edit_posts');
        \wp_send_json(self::gutenbergTree());
    }

    public function ajaxGutenbergCategories(): void
    {
        Security::verify('edit_posts');
        \wp_send_json(self::gutenbergCategories());
    }

    // ── Classic panel ──────────────────────────────────────────────────────────

    /**
     * Hierarchical term list (HTML) for the classic CherryLink panel, including tags
     * and custom taxonomies.
     *
     * @param int|string       $category
     * @param string|array<mixed> $taxonomy
     */
    public static function classicTree($category = 0, $taxonomy = []): string
    {
        $output_template_item_prefix = '<li><span class="link-counter"  title="' . \esc_attr__('Найдено в тексте / переход к ссылке', 'cherrylink') . '">[ 0 ]</span><div  title="' . \esc_attr__('Нажмите для вставки в текст', 'cherrylink') . '" class="linkate-link link-term" data-url="{url}" data-title="{title}" data-taxonomy="{taxonomy}"><span class="link-title">';
        $output_template_item_suffix = '</span></div></li>';
        $list_prefix                 = '<ul class="linkate-terms-list">';
        $list_suffix                 = '</ul>';
        $output_tepmlate_devider     = '<li class="linkate-terms-devider">{taxonomy}</li>';
        $r                           = ''; // tax item

        $next = \get_terms([
            'parent'     => $category,
            'taxonomy'   => $taxonomy, // if not empty - looking for children
            'hide_empty' => false,
            'orderby'    => 'taxonomy',
            'order'      => 'ASC',
        ]);

        if (\is_array($next) && $next) {
            $r .= $list_prefix;

            foreach ($next as $cat) {
                if (! $cat instanceof WP_Term || $cat->taxonomy === 'nav_menu') {
                    continue;
                }

                $cat_tax = \get_taxonomy($cat->taxonomy);

                // Don't show terms w/o taxonomies or if taxonomy isn't public
                if ($cat_tax === false || (\is_object($cat_tax) && ! $cat_tax->public)) {
                    continue;
                }

                if ($taxonomy !== $cat->taxonomy) { // if next type of taxonomy - add header/divider
                    $taxonomy = $cat->taxonomy;
                    // $cat_tax is guaranteed a WP_Taxonomy object past the === false guard above.
                    $label    = $cat_tax->label;
                    $r       .= \str_replace('{taxonomy}', $label, $output_tepmlate_devider);
                }
                $link = \get_term_link($cat);
                $r   .= \str_replace(  // item template with values
                    ['{url}', '{title}', '{taxonomy}'],
                    [$link, $cat->name, $cat->taxonomy],
                    $output_template_item_prefix
                ) . $cat->name . ' (' . $cat->count . ')' . $output_template_item_suffix;
                // term_id is always a positive int for a real term, so recurse for children.
                $r   .= self::classicTree($cat->term_id, $taxonomy);
            }

            $r .= $list_suffix;
        }

        return $r;
    }

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
