<?php

declare(strict_types=1);

namespace CherryLink\Indexing;

use CherryLink\Ajax\Security;
use CherryLink\Api\Client;
use CherryLink\Contracts\Registerable;
use WP_Post;

/**
 * Keeps the vector index in step with content changes, and owns the readiness-status
 * payload the settings UI renders.
 *
 * Ported verbatim from the legacy cherrylink_index.php (now a thin shim that keeps
 * cl_index_status_data()/cl_index_status_local() as back-compat delegators, since the
 * readiness card and statistics subpage still call them).
 *
 * All API/DB work runs inside the hook callbacks at request time, so the global
 * license shims (cl_is_licensed / cl_mark_index_dirty / cl_clear_index_dirty) are
 * loaded by the time they are called.
 */
final class IndexHooks implements Registerable
{
    /** Transient holding the last readiness snapshot. */
    public const STATUS_CACHE_KEY = 'cl_status_cache';

    /** Counts older than this (seconds) get a background refresh. */
    public const STATUS_STALE_S = 300;

    public function register(): void
    {
        \add_action('wp_insert_post', [$this, 'onSave'], 100000, 3);
        \add_action('delete_post', [$this, 'onDelete']);

        // Background readiness refresh — hits the API + updates the cache.
        \add_action('wp_ajax_linkate_index_status_data', [$this, 'ajaxStatusData']);
        // Server connectivity diagnostics for the "Состояние сервера" card.
        \add_action('wp_ajax_cherrylink_server_status', [$this, 'ajaxServerStatus']);
    }

    // ── Post save / delete hooks ─────────────────────────────────────────────

    public function onSave(int $post_id, WP_Post $post, bool $update): void
    {
        // Skip revisions, autosaves, and non-public statuses
        if (\wp_is_post_revision($post_id) || \wp_is_post_autosave($post_id)) {
            return;
        }

        $allowed = IndexHelpers::get_allowed_types_list();
        if (! \in_array($post->post_type, $allowed, true)) {
            return;
        }

        // No valid license → do no API work; record that the index is now out of sync.
        if (! \cl_is_licensed()) {
            \cl_mark_index_dirty();
            return;
        }

        $api = new Client();

        if ($post->post_status === 'publish') {
            $api->upsert_post($post);

            // Update link graph for this post
            $source_url = \get_permalink($post_id);
            if (! empty($source_url) && ! empty($post->post_content)) {
                $count = $api->parse_links($post_id, 0, $source_url, $post->post_content);
                \update_post_meta($post_id, 'cherry_outgoing', $count);

                // Refresh this post's own incoming count (1 API call, not N on page load)
                $incoming = $api->get_incoming_links($source_url);
                \update_post_meta($post_id, 'cherry_income', (int) ($incoming['count'] ?? 0));
            }
        } else {
            // Unpublished / trashed — remove from both vector index and link graph
            $api->delete_post($post_id);
            $api->delete_links($post_id, 0);
        }
    }

    public function onDelete(int $post_id): void
    {
        if (! \cl_is_licensed()) {
            \cl_mark_index_dirty();
            return;
        }
        $api = new Client();
        $api->delete_post($post_id);
        $api->delete_links($post_id, 0);
    }

    // ── AJAX ─────────────────────────────────────────────────────────────────

    public function ajaxStatusData(): void
    {
        Security::verify('edit_posts');
        \wp_send_json(self::statusData(true));
    }

    public function ajaxServerStatus(): void
    {
        Security::verify('edit_posts');
        $api = new Client();
        \wp_send_json($api->diagnostics());
    }

    // ── Readiness status payload (shared by settings render + AJAX refresh) ────
    //
    // Single source of truth for the "Готовность к работе" card: doc count, link
    // count, the process badge, and the last-scan summary.

    /**
     * Local-only parts of the card (badge + last-scan line) — built from the
     * linkate_posts_meta option, no API call.
     *
     * @param array<string,mixed> $meta
     * @return array{last_scan_text:string,badge_html:string}
     */
    public static function statusLocal(array $meta): array
    {
        $last_scan_text = '';
        if (! empty($meta['last_scan_gmt'])) {
            $up  = (int) ($meta['last_upserted'] ?? 0);
            $del = (int) ($meta['last_deleted'] ?? 0);
            // Machine-readable UTC instant + site-tz fallback text. cherry-admin-index.js
            // rewrites the <time> to the viewer's browser-local time on load and after AJAX.
            $iso       = \esc_attr(\str_replace(' ', 'T', $meta['last_scan_gmt']) . 'Z');
            $when      = \esc_html(\get_date_from_gmt($meta['last_scan_gmt']));
            $time_html = "<time class=\"cl-localtime\" datetime=\"{$iso}\">{$when}</time>";
            $last_scan_text = ' ' . \sprintf(
                /* translators: 1: <time> element with the formatted scan date, 2: upserted count, 3: deleted count */
                \esc_html__('Последнее сканирование: %1$s (обновлено: %2$d, удалено: %3$d).', 'cherrylink'),
                $time_html,
                $up,
                $del
            );
        }
        switch ($meta['indexing_process'] ?? 'VALUE_NOT_EXIST') {
            case 'IN_PROGRESS':
                $badge = '<code class="bad-index">[' . \esc_html__('Сканирование не завершено', 'cherrylink') . ']</code>';
                break;
            case 'DONE':
                $badge = '<code class="good-index">[' . \esc_html__('Все хорошо', 'cherrylink') . ']</code>';
                break;
            case 'VALUE_NOT_EXIST':
                $badge = '<code class="bad-index">[' . \esc_html__('Нужно просканировать сайт', 'cherrylink') . ']</code>';
                break;
            default:
                $badge = '';
                break;
        }
        return ['last_scan_text' => $last_scan_text, 'badge_html' => $badge];
    }

    /**
     * Readiness-card data. Default returns the cached snapshot with NO API call, so admin
     * pages render instantly and navigation never blocks on a slow/unreachable server.
     * Pass $refresh=true (the background AJAX) to hit the API, refresh the cache, and detect
     * reachability. Counts are null when the API can't be reached, so the card says
     * "нет связи" instead of falsely reporting an empty index of 0.
     *
     * @return array<string,mixed>
     */
    public static function statusData(bool $refresh = false): array
    {
        $meta  = \get_option('linkate_posts_meta', []);
        $local = self::statusLocal($meta);

        if (! $refresh) {
            $cached = \get_transient(self::STATUS_CACHE_KEY);
            if (\is_array($cached)) {
                $stale = empty($cached['checked_gmt'])
                    || (\time() - \strtotime($cached['checked_gmt'] . ' UTC')) > self::STATUS_STALE_S;
                return \array_merge($cached, $local, ['stale' => $stale]);
            }
            // No snapshot yet — placeholder, no API call; the JS refresh will fill it in.
            return \array_merge($local, [
                'index_rows'         => null,
                'scheme_rows'        => null,
                'index_status_text'  => \esc_html__(' загрузка данных…', 'cherrylink'),
                'scheme_status_text' => \esc_html__(' загрузка данных…', 'cherrylink'),
                'reachable'          => null,
                'checked_gmt'        => '',
                'stale'              => true,
            ]);
        }

        $api         = new Client();
        $index_rows  = $api->get_count();        // ?int — null when unreachable
        $scheme_rows = $api->get_links_count();  // ?int
        $reachable   = ($index_rows !== null && $scheme_rows !== null);

        // On a failed read, keep the last known value rather than flashing "0".
        $prev = \get_transient(self::STATUS_CACHE_KEY);
        if ($index_rows === null) {
            $index_rows = \is_array($prev) ? ($prev['index_rows'] ?? null) : null;
        }
        if ($scheme_rows === null) {
            $scheme_rows = \is_array($prev) ? ($prev['scheme_rows'] ?? null) : null;
        }

        if ($index_rows === null) {
            $index_status_text = \esc_html__(' не удалось получить данные (нет связи с сервером).', 'cherrylink');
        } else {
            $index_status_text = $index_rows > 0
                /* translators: %d: number of indexed articles */
                ? \sprintf(\esc_html__(' проиндексировано %d статей в Vector API.', 'cherrylink'), $index_rows)
                : \esc_html__(' статьи не проиндексированы, запустите сканирование.', 'cherrylink');
        }
        if ($scheme_rows === null) {
            $scheme_status_text = \esc_html__(' не удалось получить данные (нет связи с сервером).', 'cherrylink');
        } else {
            $scheme_status_text = $scheme_rows
                /* translators: %d: number of links found */
                ? \sprintf(\esc_html__(' найдено %d ссылок в записях и страницах.', 'cherrylink'), $scheme_rows)
                : \esc_html__(' ссылки не найдены.', 'cherrylink');
        }

        $data = \array_merge($local, [
            'index_rows'         => $index_rows === null ? null : (int) $index_rows,
            'scheme_rows'        => $scheme_rows === null ? null : (int) $scheme_rows,
            'index_status_text'  => $index_status_text,
            'scheme_status_text' => $scheme_status_text,
            'reachable'          => $reachable,
            'checked_gmt'        => \gmdate('Y-m-d H:i:s'),
            'stale'              => false,
        ]);
        \set_transient(self::STATUS_CACHE_KEY, $data, HOUR_IN_SECONDS);
        return $data;
    }
}
