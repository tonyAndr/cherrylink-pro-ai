<?php

declare(strict_types=1);

namespace CherryLink\Statistics;

use CherryLink\Ajax\Security;
use CherryLink\Api\Client;
use CherryLink\Contracts\Registerable;
use CherryLink\Csv\CsvService;

/**
 * The in-editor link statistics + the admin link-scheme reports (forward/backward
 * CSV exports, problem analysis, and orphan-fix donor suggestions). Ported verbatim
 * from the legacy cherrylink_statistics.php (now a shim); CSV file writing lives in
 * \CherryLink\Csv\CsvService.
 *
 * Every handler gates on Security::verify() (nonce + capability) before touching
 * request data; the post-verify() input reads carry a scoped nonce-sniff disable.
 */
final class StatisticsService implements Registerable
{
    public function register(): void
    {
        \add_action('wp_ajax_linkate_generate_json', [$this, 'ajaxIncomingLinks']);
        \add_action('wp_ajax_linkate_get_all_posts_count', [$this, 'ajaxPostsCount']);
        \add_action('wp_ajax_linkate_generate_csv_or_json_prettyfied', [$this, 'ajaxForward']);
        \add_action('wp_ajax_linkate_generate_csv_or_json_prettyfied_backwards', [$this, 'ajaxBackward']);
        \add_action('wp_ajax_linkate_orphan_donors', [$this, 'ajaxOrphanDonors']);
    }

    // ── In-editor incoming links ───────────────────────────────────────────────

    public function ajaxIncomingLinks(): void
    {
        Security::verify('edit_posts');

        $gutenberg_data = \json_decode(\file_get_contents('php://input'), true);
        // phpcs:disable WordPress.Security.NonceVerification -- gated by Security::verify()
        $this_id   = isset($gutenberg_data['this_id'])
            ? $gutenberg_data['this_id']
            : (isset($_POST['this_id']) ? \sanitize_text_field(\wp_unslash($_POST['this_id'])) : '');
        $this_type = isset($gutenberg_data['this_type'])
            ? $gutenberg_data['this_type']
            : (isset($_POST['this_type']) ? \sanitize_text_field(\wp_unslash($_POST['this_type'])) : '');
        // phpcs:enable WordPress.Security.NonceVerification

        $target_url = $this_type === 'post' ? \get_permalink((int) $this_id) : \get_term_link((int) $this_id);

        if (\is_wp_error($target_url) || empty($target_url)) {
            \wp_send_json(['links' => '', 'count' => 0]);
        }

        $api      = new Client();
        $response = $api->get_incoming_links($target_url);

        if (! $response || empty($response['links'])) {
            \wp_send_json(['links' => '', 'count' => 0]);
        }

        $output = [];
        foreach ($response['links'] as $link) {
            $output[] = [
                'source_id'  => $link['source_id'],
                'source_url' => $link['source_url'],
                'ankor'      => $link['anchor_text'],
            ];
        }

        \wp_send_json(['links' => $output, 'count' => $response['count']]);
    }

    // ── Count of posts for export batch sizing ──────────────────────────────────

    public function ajaxPostsCount(): void
    {
        Security::verify('edit_posts');

        global $wpdb, $table_prefix;
        // phpcs:disable WordPress.Security.NonceVerification -- gated by Security::verify()
        $export_types = \array_map('sanitize_text_field', \wp_unslash((array) ($_POST['export_types'] ?? [])));
        // phpcs:enable WordPress.Security.NonceVerification
        $types = \implode(',', \array_map(static function ($el) {
            return "'" . \esc_sql($el) . "'";
        }, $export_types));
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_prefix}posts WHERE post_type IN ($types)");
        CsvService::removeOld(false);
        echo \esc_html((string) (int) $count);
        \wp_die();
    }

    // ── Forward stats (workhorse) ───────────────────────────────────────────────

    public function ajaxForward(): void
    {
        Security::verify('edit_posts');

        $gutenberg_data = \json_decode(\file_get_contents('php://input'), true);

        // phpcs:disable WordPress.Security.NonceVerification -- gated by Security::verify()
        $admin_preview   = isset($_POST['admin_preview_stats']);
        $duplicate       = isset($_POST['duplicate_fields']);
        $offset          = isset($_POST['stats_offset']) ? (int) $_POST['stats_offset'] : 0;
        $limit           = isset($_POST['stats_limit']) ? (int) $_POST['stats_limit'] : 200;
        $include_types   = \array_map('sanitize_text_field', \wp_unslash((array) ($_POST['export_types'] ?? [])));
        $direction       = \sanitize_text_field(\wp_unslash($_POST['links_direction'] ?? 'outgoing'));
        $from_editor     = isset($_POST['post_ids']) || isset($gutenberg_data['post_ids']);
        $post_ids_raw    = isset($_POST['post_ids'])
            ? \sanitize_text_field(\wp_unslash($_POST['post_ids']))
            : ($gutenberg_data['post_ids'] ?? '');
        // phpcs:enable WordPress.Security.NonceVerification

        // Editor badge request: read counts directly from post meta — fast and
        // independent of whether the link-graph scan has been run.
        if ($from_editor) {
            $requested_ids = \array_filter(\array_map('intval', \explode(',', (string) $post_ids_raw)));
            $output = [];
            foreach ($requested_ids as $pid) {
                $output['"id_' . $pid . '"'] = [
                    (int) \get_post_meta($pid, 'cherry_outgoing', true),
                    (int) \get_post_meta($pid, 'cherry_income', true),
                ];
            }
            \wp_send_json($output);
        }

        $api      = new Client();
        $response = $api->get_links_stats('forward', $offset, $limit);

        if ($response === null) {
            \wp_send_json(['status' => 'ERROR']);
        }

        $results      = $response['results'] ?? [];
        $output_array = $this->resultsToArrayForward($results, false, $include_types, $duplicate);

        if ($admin_preview) {
            \wp_send_json($this->adminPreviewStats());
        }

        CsvService::queryToCsv($output_array, 'cherrylink_stats_' . $offset . '.csv', $direction);
        \wp_send_json([
            'status' => 'OK',
            'url'    => CHERRYLINK_DIR_URL . 'stats/cherrylink_stats_' . $offset . '.csv',
        ]);
    }

    /**
     * @param array<int,array<string,mixed>> $results
     * @param string[]                       $include_types
     * @return array<int,array<int,mixed>>
     */
    private function resultsToArrayForward(array $results, bool $from_editor, array $include_types, bool $duplicate): array
    {
        $output = [];

        foreach ($results as $row) {
            $source_id  = $row['source_id'];
            $source_url = $row['source_url'];
            $post_type  = \get_post_type($source_id);

            if (! $from_editor && ! empty($include_types) && ! \in_array($post_type, $include_types)) {
                continue;
            }

            if ($from_editor) {
                $output['"id_' . $source_id . '"'] = [
                    $row['count_targets'],
                    $row['count_sources'],
                ];
                continue;
            }

            $categories = [];
            $terms      = \get_the_terms($source_id, 'category');
            if (! empty($terms) && ! \is_wp_error($terms)) {
                $categories = \wp_list_pluck($terms, 'name');
            }
            $cats_str = \implode(', ', $categories);

            $links = $row['links'];
            if (empty($links)) {
                // Post with no outgoing links — one row with blanks
                $output[] = [
                    $source_id, $post_type, $cats_str, $source_url,
                    '', '', $row['count_targets'], $row['count_sources'], 0,
                ];
                continue;
            }

            foreach ($links as $i => $link) {
                $is_broken = $link['link_type'] === 255 ? 1 : 0;
                if ($i === 0 || $duplicate) {
                    $output[] = [
                        $source_id, $post_type, $cats_str, $source_url,
                        $link['target_url'], $link['anchor_text'],
                        $row['count_targets'], $row['count_sources'], $is_broken,
                    ];
                } else {
                    $output[] = [
                        '', '', '', '',
                        $link['target_url'], $link['anchor_text'],
                        '', '', $is_broken,
                    ];
                }
            }
        }

        return $output;
    }

    // ── Backward stats ──────────────────────────────────────────────────────────

    public function ajaxBackward(): void
    {
        Security::verify('edit_posts');

        // phpcs:disable WordPress.Security.NonceVerification -- gated by Security::verify()
        $offset        = isset($_POST['stats_offset']) ? (int) $_POST['stats_offset'] : 0;
        $limit         = isset($_POST['stats_limit']) ? (int) $_POST['stats_limit'] : 200;
        $duplicate     = isset($_POST['duplicate_fields']);
        $include_types = \array_map('sanitize_text_field', \wp_unslash((array) ($_POST['export_types'] ?? [])));
        $direction     = \sanitize_text_field(\wp_unslash($_POST['links_direction'] ?? 'outgoing'));
        // phpcs:enable WordPress.Security.NonceVerification

        $api      = new Client();
        $response = $api->get_links_stats('backward', $offset, $limit);

        if ($response === null) {
            \wp_send_json(['status' => 'ERROR']);
        }

        $results      = $response['results'] ?? [];
        $output_array = $this->resultsToArrayBackward($results, $include_types, $duplicate);

        CsvService::queryToCsv($output_array, 'cherrylink_stats_' . $offset . '.csv', $direction);
        \wp_send_json([
            'status' => 'OK',
            'url'    => CHERRYLINK_DIR_URL . 'stats/cherrylink_stats_' . $offset . '.csv',
        ]);
    }

    /**
     * @param array<int,array<string,mixed>> $results
     * @param string[]                       $include_types
     * @return array<int,array<int,mixed>>
     */
    private function resultsToArrayBackward(array $results, array $include_types, bool $duplicate): array
    {
        $output = [];

        foreach ($results as $row) {
            $target_url = $row['target_url'];

            // Resolve target post for type/category filtering
            $target_id  = \url_to_postid($target_url);
            $post_type  = $target_id ? \get_post_type($target_id) : '';
            $categories = [];
            if ($target_id) {
                $terms = \get_the_terms($target_id, 'category');
                if (! empty($terms) && ! \is_wp_error($terms)) {
                    $categories = \wp_list_pluck($terms, 'name');
                }
            }
            $cats_str = \implode(', ', $categories);

            if (! empty($include_types) && ! empty($post_type) && ! \in_array($post_type, $include_types)) {
                continue;
            }

            $sources = $row['sources'];
            if (empty($sources)) {
                $output[] = [
                    $target_id, $post_type, $cats_str, $target_url,
                    '', '', $row['count_targets'], $row['count_sources'],
                ];
                continue;
            }

            foreach ($sources as $i => $source) {
                if ($i === 0 || $duplicate) {
                    $output[] = [
                        $target_id, $post_type, $cats_str, $target_url,
                        $source['source_url'], $source['anchor_text'],
                        $row['count_targets'], $row['count_sources'],
                    ];
                } else {
                    $output[] = [
                        '', '', '', '',
                        $source['source_url'], $source['anchor_text'],
                        '', '',
                    ];
                }
            }
        }

        return $output;
    }

    // ── Problems analysis ─────────────────────────────────────────────────────────

    /**
     * Transform the API /v1/links/problems response into the shape the admin UI expects,
     * keyed by source_url.
     *
     * @return array<string,array<string,mixed>>
     */
    private function adminPreviewStats(): array
    {
        $api      = new Client();
        $response = $api->get_link_problems();

        if (! $response || empty($response['problems'])) {
            return [];
        }

        $result = [];
        foreach ($response['problems'] as $p) {
            $url = $p['source_url'];

            // Resolve the real WP post id + title so the UI can show a readable row.
            $post_id = (int) ($p['source_id'] ?? 0);
            if (! $post_id) {
                $post_id = \url_to_postid($url);
            }
            $title = $post_id ? \get_the_title($post_id) : '';

            $targets = [];
            foreach ($p['repeated_targets'] ?? [] as $rt) {
                $targets[$rt['target_url']] = $rt['count'];
            }

            $err_404 = [];
            foreach ($p['broken_links'] ?? [] as $bl) {
                $err_404[$bl['target_url']] = $bl['anchor'];
            }

            $result[$url] = [
                'url'          => $url,
                'post_id'      => $post_id,
                'title'        => $title,
                'targets'      => $targets,
                'recursion'    => $p['recursive_anchors'] ?? [],
                'err_404'      => $err_404,
                'has_outgoing' => ! $p['no_outgoing'],
                'has_incoming' => ! $p['no_incoming'],
                'has_repeats'  => $p['has_repeats'],
            ];
        }

        return $result;
    }

    // ── Orphan-fix donor suggestions ────────────────────────────────────────────

    /**
     * For one orphan (a post with no incoming links), find the best "donor" posts that
     * could link TO it, and a suggested anchor phrase in each. Reuses the semantic search
     * + on-demand anchor ranking. No new API endpoint — pure orchestration.
     */
    public function ajaxOrphanDonors(): void
    {
        Security::verify('edit_posts');

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- gated by Security::verify()
        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        $orphan  = $post_id ? \get_post($post_id) : null;
        if (! $orphan) {
            \wp_send_json(['donors' => []]);
        }

        $orphan_title = $orphan->post_title;
        if ($orphan_title === '') {
            \wp_send_json(['donors' => []]);
        }

        $api = new Client();
        // Ask for a few extra hits — we drop the orphan itself and any non-published posts.
        $hits = $api->search($orphan_title, '', 8);
        if (empty($hits)) {
            \wp_send_json(['donors' => []]);
        }

        $donors = [];
        foreach ($hits as $hit) {
            if (\count($donors) >= 5) {
                break;
            }
            $src_id = (int) ($hit['post_id'] ?? 0);
            if (! $src_id || $src_id === $post_id) {
                continue;
            }
            $src = \get_post($src_id);
            if (! $src || $src->post_status !== 'publish') {
                continue;
            }

            $source_html = \function_exists('linkate_build_source_html')
                ? \linkate_build_source_html($src->post_content)
                : $src->post_content;

            // Best single anchor phrase inside this donor that points at the orphan.
            $suggestions = $api->suggest_anchors($source_html, $orphan_title, 1);
            $anchor      = ! empty($suggestions) ? ($suggestions[0]['phrase'] ?? '') : '';
            $context     = ! empty($suggestions) ? ($suggestions[0]['context'] ?? '') : '';

            $donors[] = [
                'source_id'    => $src_id,
                'source_title' => \get_the_title($src_id) ?: ($hit['title'] ?? $hit['url'] ?? ''),
                'source_url'   => $hit['url'] ?? \get_permalink($src_id),
                'anchor'       => $anchor,
                'context'      => $context,
                'edit_url'     => \admin_url('post.php?post=' . $src_id . '&action=edit'),
            ];
        }

        \wp_send_json(['donors' => $donors]);
    }
}
