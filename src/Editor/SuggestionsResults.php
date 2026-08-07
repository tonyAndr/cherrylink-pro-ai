<?php

declare(strict_types=1);

namespace CherryLink\Editor;

use CherryLink\Api\Client;
use CherryLink\CustomFields\Cf;
use CherryLink\Support\View;

/**
 * The suggestions pipeline: take the post being edited, ask the vector API for the
 * most semantically-related published posts, filter the candidates through WordPress
 * (status / type / category / author / max-incoming), then render the link cards for
 * the requesting editor (classic panel HTML or Gutenberg JSON).
 *
 * Ported verbatim from LinkatePosts::execute() + prepare_for_*() in the legacy
 * cherrylink_main.php (which now delegates here). Query helpers live in
 * \CherryLink\CustomFields\Cf and the per-result field map in \CherryLink\Editor\LinkFields.
 */
final class SuggestionsResults
{
    /**
     * @param string $args       'key=value&...' request string
     * @return array{links:mixed,count:int}
     */
    public static function execute(string $args = '', string $option_key = 'linkate-posts'): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */

        $arg_options = Cf::parseArgs($args);
        $is_term     = isset($arg_options['is_term'])     ? (int) $arg_options['is_term']    : 0;
        $offset      = isset($arg_options['offset'])      ? (int) $arg_options['offset']     : 0;
        $post_id     = isset($arg_options['manual_ID'])   ? (int) $arg_options['manual_ID']  : -1;
        $mode        = ! empty($arg_options['mode'])      ? $arg_options['mode']             : 'classic';
        $custom_text = isset($arg_options['custom_text']) ? \trim($arg_options['custom_text']) : '';

        $options = Cf::setOptions($option_key, $arg_options);
        $post_id = Cf::currentPostId($post_id);
        $limit   = (int) $options['limit_ajax'];

        if (! $post_id || $limit < 1) {
            return self::emptyResult($mode, $options);
        }

        // ── Build query text for the vector API ────────────────────────────────
        if (! empty($custom_text)) {
            $query_text = $custom_text;
        } else {
            $current_post = \get_post($post_id);
            if (! $current_post) {
                return self::emptyResult($mode, $options);
            }
            $query_text = $current_post->post_title . "\n" . \wp_strip_all_tags($current_post->post_content);
        }

        // ── Vector API search ──────────────────────────────────────────────────
        // Request extra results to have headroom after WP-level filtering.
        $api         = new Client();
        $api_results = $api->search($query_text, '', $limit * 3);

        if (empty($api_results)) {
            return self::emptyResult($mode, $options);
        }

        // Build a map: post_id → API row (for suggestions & score), preserving score order
        $api_map     = [];
        $ordered_ids = [];
        foreach ($api_results as $r) {
            $pid = (int) $r['post_id'];
            if ($pid === $post_id) {
                continue;
            }
            $api_map[$pid] = $r;
            $ordered_ids[] = $pid;
        }

        if (empty($ordered_ids)) {
            return self::emptyResult($mode, $options);
        }

        // ── Filter through WordPress (status, type, cats, age …) ────────────────
        $ids_in          = \implode(',', \array_map('intval', $ordered_ids));
        $match_category  = ($options['match_cat'] === 'true');
        $exclude_cats    = ($options['excluded_cats'] !== '');
        $exclude_authors = ($options['excluded_authors'] !== '');
        $hide_pass       = ($options['show_private'] === 'false');
        $consider_max    = (isset($options['consider_max_incoming_links']) && $options['consider_max_incoming_links'] !== 'false');

        $sql = "SELECT ID, post_content, post_title, post_author, post_excerpt, post_date, post_type FROM {$wpdb->posts} ";

        $where   = ["ID IN ($ids_in)"];
        $where[] = Cf::whereShowStatus($options['status']);
        if ($is_term === 0) {
            if ($match_category) {
                $where[] = Cf::whereMatchCategory($post_id);
            }
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

        $sql .= 'WHERE ' . \implode(' AND ', $where);
        // Preserve vector similarity ranking
        $sql .= " ORDER BY FIELD(ID, $ids_in)";
        $sql .= " LIMIT $offset, $limit";

        $results = $wpdb->get_results($sql);

        // Filter by max incoming links
        if ($consider_max && $results) {
            $max     = (int) $options['max_incoming_links'];
            $results = \array_values(\array_filter($results, static function ($row) use ($max) {
                return (int) \get_post_meta($row->ID, 'cherry_income', true) < $max;
            }));
        }

        // ── Attach API suggestions + score to each result ────────────────────────
        if ($results) {
            foreach ($results as $result) {
                $api_row = $api_map[(int) $result->ID] ?? [];
                // Store raw JSON; each renderer applies its own escaping:
                // Gutenberg needs \" (JSON-inside-JSON), Classic needs &quot; (HTML attr)
                $result->suggestions = ! empty($api_row['suggestions'])
                    ? \wp_json_encode($api_row['suggestions'], JSON_UNESCAPED_UNICODE)
                    : '';
                $result->score = isset($api_row['score'])
                    ? (int) \round($api_row['score'] * 100)
                    : 0;
            }
        }

        switch ($mode) {
            case 'gutenberg':
                return self::prepareForGutenberg($results, $option_key);
            case 'classic':
            default:
                return self::prepareForClassic($results, $option_key, $options);
        }
    }

    /**
     * @param array<string,mixed> $options
     * @return array{links:mixed,count:int}
     */
    private static function emptyResult(string $mode, array $options): array
    {
        if ($mode === 'gutenberg') {
            return ['links' => [], 'count' => -1];
        }
        $output = '<p>' . $options['none_text'] . "\n</p>";
        return ['links' => \trim($output), 'count' => 0];
    }

    /**
     * @param array<int,\stdClass>|null $results
     * @return array{links:mixed,count:int}
     */
    private static function prepareForGutenberg($results, string $option_key): array
    {
        $output_template = (new View())->string('link-card/gutenberg');

        if ($results) {
            $items = [];
            foreach ($results as $result) {
                // JSON-inside-JSON: escape quotes so the value is valid inside "data-suggestions":"{suggestions}"
                $result->suggestions = \str_replace('"', '\\"', $result->suggestions);
                $items[]             = '{' . \strtr($output_template, LinkFields::forResult($option_key, $result)) . '}';
            }
            $output = '[' . \implode(',', $items) . ']';

            return ['links' => $output, 'count' => \count($results)];
        }

        return ['links' => [], 'count' => -1];
    }

    /**
     * @param array<int,\stdClass>|null $results
     * @param array<string,mixed>       $options
     * @return array{links:mixed,count:int}
     */
    private static function prepareForClassic($results, string $option_key, array $options): array
    {
        $results_count = 0;
        if ($results) {
            $out_final = (new View())->string('link-card/classic');
            $items     = [];
            foreach ($results as $result) {
                // HTML attribute context: encode quotes so they don't break data-suggestions="..."
                $result->suggestions = \htmlspecialchars($result->suggestions, ENT_COMPAT, 'UTF-8');
                $items[]             = \strtr($out_final, LinkFields::forResult($option_key, $result)) . "\n";
            }
            $output        = \implode(($options['divider']) ? $options['divider'] : "\n", $items);
            $results_count = \count($results);
        } else {
            $output = '<p>' . $options['none_text'] . "\n</p>";
        }

        return ['links' => \trim($output), 'count' => $results_count];
    }
}
