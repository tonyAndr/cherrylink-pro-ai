<?php

declare(strict_types=1);

namespace CherryLink\Statistics;

use CherryLink\Ajax\Security;
use CherryLink\Api\Client;
use CherryLink\Contracts\Registerable;
use CherryLink\Editor\ClassicPanel;
use CherryLink\Editor\SuggestionsResults;
use CherryLink\Support\Language;

/**
 * Orphan-fix donor suggestions for the Link health screen ("Find donors"). The CSV
 * exports and in-editor stats handlers were replaced by the admin app and the panel API.
 */
final class StatisticsService implements Registerable
{
    public function register(): void
    {
        \add_action('wp_ajax_linkate_orphan_donors', [$this, 'ajaxOrphanDonors']);
        \add_action('wp_ajax_linkate_post_targets', [$this, 'ajaxPostTargets']);
    }

    /**
     * "No outgoing" rows: posts this post could link to, with link text found in it.
     * The editor panel's own pipeline on the saved content: one search call, run on click.
     */
    public function ajaxPostTargets(): void
    {
        Security::verify('edit_posts');

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- gated by Security::verify()
        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        if ($post_id < 1 || ! \current_user_can('edit_post', $post_id)) {
            \wp_send_json(['donors' => []]);
        }
        $edit    = \admin_url('post.php?post=' . $post_id . '&action=edit');
        $targets = [];
        foreach (SuggestionsResults::rows(['post_id' => $post_id])['items'] as $item) {
            // One phrase can carry one link: take the first one no earlier target used.
            $phrases = \array_diff(\array_column($item['suggestions'], 'phrase'), \array_column($targets, 'anchor'), ['']);
            $phrase  = (string) \reset($phrases);
            if ($phrase === '') {
                continue; // nothing in this post to turn into the link
            }
            // Same shape as donors so the screen reuses one list; Edit opens this post.
            $targets[] = ['source_id' => $item['post_id'], 'source_title' => $item['title'], 'anchor' => $phrase, 'edit_url' => $edit];
            if (\count($targets) >= 5) {
                break;
            }
        }
        \wp_send_json(['donors' => $targets]);
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
        $hits = $api->search($orphan_title, '', 8, Language::forPost($post_id));
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

            $source_html = ClassicPanel::buildSourceHtml($src->post_content);

            // Best single anchor phrase inside this donor that points at the orphan.
            $suggestions = $api->suggest_anchors($source_html, $orphan_title, 1, Language::forPost($post_id), $post_id);
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
