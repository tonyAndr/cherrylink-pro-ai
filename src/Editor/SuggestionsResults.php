<?php

declare(strict_types=1);

namespace CherryLink\Editor;

use CherryLink\Api\Client;
use CherryLink\CustomFields\Cf;
use CherryLink\Support\KeyPages;
use CherryLink\Support\Language;

/**
 * The suggestions pipeline: take the post being edited, ask the vector API for the
 * most semantically-related published posts and filter the candidates through WordPress
 * (status / type / category / author / max-incoming) into structured rows for the
 * unified panel (see rows(), served by PanelApi). Query helpers live in
 * \CherryLink\CustomFields\Cf and the per-result fields in \CherryLink\Editor\LinkFields.
 */
final class SuggestionsResults
{
    /**
     * Structured rows for the unified panel (same filtering pipeline as execute()).
     *
     * @param array{post_id:int,is_term?:bool,source_html?:string,query?:string,category?:string,offset?:int,taxonomy?:string} $p
     * @return array{items:array<int,array<string,mixed>>,has_more:bool}
     */
    public static function rows(array $p, string $option_key = 'linkate-posts'): array
    {
        $empty   = ['items' => [], 'has_more' => false];
        $post_id = (int) $p['post_id'];
        $is_term = ! empty($p['is_term']);
        $offset  = \max(0, (int) ($p['offset'] ?? 0));
        $html    = (string) ($p['source_html'] ?? '');
        $query   = \trim((string) ($p['query'] ?? ''));

        $options = Cf::setOptions($option_key, []);
        $limit   = (int) $options['limit_ajax'];
        if ($post_id < 1 || $limit < 1) {
            return $empty;
        }

        if ($is_term) {
            $term  = \get_term($post_id, (string) ($p['taxonomy'] ?? ''));
            $title = ($term && ! \is_wp_error($term)) ? (string) $term->name : '';
            $saved = ($term && ! \is_wp_error($term)) ? (string) $term->description : '';
            $lang  = Language::forSite();
        } else {
            $post = \get_post($post_id);
            if (! $post) {
                return $empty;
            }
            $title = (string) $post->post_title;
            $saved = (string) $post->post_content;
            $lang  = Language::forPost($post_id);
            if ($html === '') {
                $html = $saved;
            }
        }

        // Live editor HTML too: a Classic block sends wpautop-style text without <p>.
        $html = ClassicPanel::buildSourceHtml($html);
        if ($query === '') {
            $body  = $html !== '' ? $html : $saved;
            $query = \trim($title . "\n" . \wp_strip_all_tags($body));
        }
        if ($query === '') {
            return $empty;
        }

        // Unedited post: let the API reuse its stored vector (same ranking quality on the
        // benchmark fixture, one embedding call fewer). Edited content is embedded fresh.
        $saved_post  = (! $is_term && self::sameText($html, $saved)) ? $post : null;
        $api         = new Client();
        $api_results = $api->search($query, $html, $limit * 3, $lang, \min(10, $limit), $saved_post);
        if (empty($api_results)) {
            return $empty;
        }

        $api_map     = [];
        $ordered_ids = [];
        foreach ($api_results as $r) {
            $pid = (int) $r['post_id'];
            if ($pid === $post_id && ! $is_term) {
                continue;
            }
            $api_map[$pid] = $r;
            $ordered_ids[] = $pid;
        }
        if (! $ordered_ids) {
            return $empty;
        }

        // Key pages rank slightly above equally scored posts; the displayed score stays raw.
        $key_pages = new KeyPages();
        $key_ids   = $key_pages->keyIds();
        if ($key_ids) {
            $scores = [];
            foreach ($ordered_ids as $oid) {
                $scores[$oid] = (float) ($api_map[$oid]['score'] ?? 0);
            }
            $ordered_ids = KeyPages::rank($scores, $key_ids);
        }

        $extra = self::categoryWhere((string) ($p['category'] ?? ''));
        [$results, $fetched] = self::fetchPosts($ordered_ids, $post_id, $is_term, $options, $offset, $limit, $extra);

        $link_options = (array) \get_option($option_key, []);
        $items        = [];
        foreach ($results as $result) {
            $id      = (int) $result->ID;
            $api_row = $api_map[$id] ?? [];
            $markup  = LinkMarkup::build(
                LinkFields::url($option_key, $result),
                \html_entity_decode((string) \get_the_title($id), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                $link_options
            );
            $sugg    = [];
            foreach ((array) ($api_row['suggestions'] ?? []) as $s) {
                $sugg[] = [
                    'phrase'      => (string) ($s['phrase'] ?? ''),
                    'context'     => (string) ($s['context'] ?? ''),
                    'block_index' => (int) ($s['block_index'] ?? 0),
                ];
            }
            $items[] = [
                'post_id'     => $id,
                'score'       => \round((float) ($api_row['score'] ?? 0), 4),
                'title'       => \html_entity_decode((string) \get_the_title($id), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'url'         => LinkFields::url($option_key, $result),
                'view_url'    => (string) \get_permalink($id),
                'edit_url'    => \admin_url('post.php?post=' . $id . '&action=edit'),
                'out'         => (int) \get_post_meta($id, 'cherry_outgoing', true),
                'in'          => (int) \get_post_meta($id, 'cherry_income', true),
                'saturated'   => false,
                'is_key'      => \in_array($id, $key_ids, true),
                'open'        => $markup['open'],
                'close'       => $markup['close'],
                'suggestions' => $sugg,
            ];
        }

        return ['items' => $items, 'has_more' => $fetched > $limit];
    }

    /**
     * Run the WordPress-side filter over the ranked API ids.
     *
     * @param int[]               $ordered_ids
     * @param array<string,mixed> $options
     * @return array{0:array<int,\stdClass>,1:int} [rows after max-incoming filter, rows fetched by SQL]
     */
    private static function fetchPosts(array $ordered_ids, int $post_id, bool $is_term, array $options, int $offset, int $limit, string $extra_where = ''): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $ids_in          = \implode(',', \array_map('intval', $ordered_ids));
        $match_category  = ($options['match_cat'] === 'true');
        $exclude_cats    = ($options['excluded_cats'] !== '');
        $exclude_authors = ($options['excluded_authors'] !== '');
        $hide_pass       = ($options['show_private'] === 'false');
        $consider_max    = (isset($options['consider_max_incoming_links']) && $options['consider_max_incoming_links'] !== 'false');

        $sql = "SELECT ID, post_content, post_title, post_author, post_excerpt, post_date, post_type FROM {$wpdb->posts} ";

        $where   = ["ID IN ($ids_in)"];
        $where[] = Cf::whereShowStatus($options['status']);
        if (! $is_term && $match_category) {
            $where[] = Cf::whereMatchCategory($post_id);
        }
        $where[] = Cf::whereShowPages($options['show_pages'], $options['show_customs']);
        if ($exclude_cats) {
            $where[] = Cf::whereExcludedCats($options['excluded_cats']);
        }
        if ($exclude_authors) {
            $where[] = Cf::whereExcludedAuthors($options['excluded_authors']);
        }
        if ($hide_pass) {
            $where[] = Cf::whereHidePass();
        }
        if ($extra_where !== '') {
            $where[] = $extra_where;
        }

        $sql .= 'WHERE ' . \implode(' AND ', $where);
        // Preserve vector similarity ranking; fetch one extra row to learn whether more exist.
        $sql .= " ORDER BY FIELD(ID, $ids_in)";
        $sql .= ' LIMIT ' . (int) $offset . ', ' . ((int) $limit + 1);

        $results = (array) $wpdb->get_results($sql);
        $fetched = \count($results);
        $results = \array_slice($results, 0, $limit);

        if ($consider_max && $results) {
            $max     = (int) $options['max_incoming_links'];
            $results = \array_values(\array_filter($results, static function ($row) use ($max) {
                return (int) \get_post_meta($row->ID, 'cherry_income', true) < $max;
            }));
        }

        return [$results, $fetched];
    }

    /** WHERE fragment restricting to a category (id or name) and its children; '' for none. */
    private static function categoryWhere(string $category): string
    {
        $category = \trim($category);
        if ($category === '' || $category === '0') {
            return '';
        }
        $term_id = 0;
        if (\ctype_digit($category)) {
            $term_id = (int) $category;
        } else {
            $term = \get_term_by('name', $category, 'category');
            $term_id = $term ? (int) $term->term_id : 0;
        }
        if ($term_id < 1) {
            return '1 = 2';
        }
        $terms = \array_merge([$term_id], (array) \get_term_children($term_id, 'category'));
        $ids   = \array_unique(\array_map('intval', (array) \get_objects_in_term($terms, 'category')));
        return $ids ? 'ID IN (' . \implode(',', $ids) . ')' : '1 = 2';
    }

    /** Whether the editor HTML carries the same text as the saved content (markup/whitespace ignored). */
    public static function sameText(string $html, string $saved): bool
    {
        $norm = static function (string $v): string {
            return \trim((string) \preg_replace('/\s+/u', ' ', \wp_strip_all_tags($v)));
        };
        return $html === '' || $norm($html) === $norm($saved);
    }
}
