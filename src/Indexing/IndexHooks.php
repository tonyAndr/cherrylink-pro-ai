<?php

declare(strict_types=1);

namespace CherryLink\Indexing;

use CherryLink\Ajax\Security;
use CherryLink\Api\Client;
use CherryLink\Contracts\Registerable;
use CherryLink\Indexing\Outbox\Capture;
use CherryLink\Indexing\Outbox\Delivery;
use CherryLink\Indexing\Outbox\Repository;
use CherryLink\Support\Capabilities;
use CherryLink\Support\License;
use CherryLink\Support\Options;
use CherryLink\Support\RequestLock;
use WP_Post;

/**
 * Keeps the vector index in step with content changes, and owns the readiness-status
 * payload the settings UI renders.
 *
 * Ported from the legacy cherrylink_index.php (now deleted). The readiness payload lives
 * on the static statusData(), read directly by the settings page; the license gate and
 * out-of-sync flag go through the injected License / Options services (the old cl_*
 * globals are gone).
 */
final class IndexHooks implements Registerable
{
    /** Transient holding the last readiness snapshot. */
    public const STATUS_CACHE_KEY = 'cl_status_cache';

    /** Transient holding the manually requested server diagnostics. */
    public const SERVER_STATUS_CACHE_KEY = 'cl_server_status_cache';

    /** Counts older than this (seconds) get a background refresh. */
    public const STATUS_STALE_S = 300;

    /** @var Options */
    private $options;

    /** @var License */
    private $license;

    /** @var callable|null Lazily built; only touched when the tenant is async-capable. */
    private $capture_factory;

    /** @var callable|null */
    private $delivery_factory;

    /** @var callable|null Async-capability probe (returns bool). */
    private $async_probe;


    /**
     * @param callable|null $capture_factory  Returns a Capture; built on demand.
     * @param callable|null $delivery_factory Returns a Delivery; built on demand.
     * @param callable|null $async_probe      Returns whether this tenant uses the async outbox.
     */
    public function __construct(
        Options $options,
        License $license,
        ?callable $capture_factory = null,
        ?callable $delivery_factory = null,
        ?callable $async_probe = null,
        ?callable $mutations_probe = null
    ) {
        unset($mutations_probe); // Retained as a backwards-compatible constructor argument.
        $this->options          = $options;
        $this->license          = $license;
        $this->capture_factory  = $capture_factory;
        $this->delivery_factory = $delivery_factory;
        $this->async_probe      = $async_probe;
        // Retain the legacy argument for constructor compatibility. Capture is
        // unconditional for established async tenants; availability is enforced
        // by Delivery, which defers tenant-state rejection without losing rows.
    }

    /** Async outbox path is opt-in per tenant; default is the existing synchronous adapter. */
    private function usesAsyncOutbox(): bool
    {
        $probe = $this->async_probe;
        if ($probe === null) {
            $probe = static function (): bool {
                // Routing follows the durable tenant mode, not current availability.
                return (new Capabilities())->asyncEnabled();
            };
        }

        try {
            return (bool) \call_user_func($probe);
        } catch (\Throwable $error) {
            return false;   // fail closed to the synchronous adapter
        }
    }

    private function capture(): Capture
    {
        if ($this->capture_factory !== null) {
            $capture = \call_user_func($this->capture_factory);
            if ($capture instanceof Capture) {
                return $capture;
            }
        }

        return new Capture(new Repository(), new Client(null, Client::CONTEXT_BACKGROUND));
    }

    private function delivery(): Delivery
    {
        if ($this->delivery_factory !== null) {
            $delivery = \call_user_func($this->delivery_factory);
            if ($delivery instanceof Delivery) {
                return $delivery;
            }
        }

        return new Delivery();
    }

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
        if (! $this->license->isLicensed()) {
            $this->options->markIndexDirty();
            return;
        }

        // Async tenants persist an immutable outbox event and schedule delivery. The
        // save request performs no embedding/link/API call, and a failed capture keeps
        // the dirty marker rather than silently dropping the change.
        if ($this->usesAsyncOutbox()) {
            $this->captureAsync($post);
            return;
        }

        $api = new Client(null, Client::CONTEXT_BACKGROUND);

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
        if (! $this->license->isLicensed()) {
            $this->options->markIndexDirty();
            return;
        }

        if ($this->usesAsyncOutbox()) {
            $row = $this->capture()->tryDelete($post_id);
            if ($row === null) {
                $this->options->markIndexDirty();
                return;
            }
            $this->delivery()->schedule();
            return;
        }

        $api = new Client(null, Client::CONTEXT_BACKGROUND);
        $api->delete_post($post_id);
        $api->delete_links($post_id, 0);
    }

    /**
     * Persist the async event for a save (upsert or tombstone) and queue delivery.
     * Never performs an API call inside the WordPress request.
     */
    private function captureAsync(WP_Post $post): void
    {
        // Capture always re-reads the post after allocating the revision, so a
        // stale request cannot win with an older body or an outdated unpublish.
        $row = $this->capture()->tryCapture((int) $post->ID);

        if ($row === null) {
            $this->options->markIndexDirty();
            return;
        }

        $this->delivery()->schedule();
    }

    // ── AJAX ─────────────────────────────────────────────────────────────────

    public function ajaxStatusData(): void
    {
        Security::verify('edit_posts');
        // The manual "Проверить связь" path sends force=1. It must not be short-circuited
        // by the 30-second negative cache; an administrator expects a real probe.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- gated by Security::verify()
        $force = !empty($_POST['force']);
        \wp_send_json(self::statusData(true, $force));
    }

    public function ajaxServerStatus(): void
    {
        Security::verify('edit_posts');
        // The button is an explicit manual check, so always discard the previous
        // diagnostics/readiness snapshots and probe now. A negative diagnostic cached
        // from a transient network failure must not survive the user's retry.
        self::clearStatusCaches();
        $token = RequestLock::acquire('cl_server_probe_lock', 15);
        if ($token === null) {
            $data = ['pending' => true];
        } else {
            try {
                $data = (new Client())->diagnostics();
                \set_transient(self::SERVER_STATUS_CACHE_KEY, $data, HOUR_IN_SECONDS);
            } finally {
                RequestLock::release('cl_server_probe_lock', $token);
            }
        }
        \wp_send_json($data);
    }

    /** Drop both readiness and diagnostics snapshots after a manual retry or update. */
    public static function clearStatusCaches(): void
    {
        \delete_transient(self::STATUS_CACHE_KEY);
        \delete_transient(self::SERVER_STATUS_CACHE_KEY);
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
    public static function statusData(bool $refresh = false, bool $force = false): array
    {
        $meta  = \get_option('linkate_posts_meta', []);
        $local = self::statusLocal($meta);

        if ($refresh && $force) {
            self::clearStatusCaches();
        }

        if (! $refresh) {
            $cached = \get_transient(self::STATUS_CACHE_KEY);
            if (\is_array($cached)) {
                $stale = empty($cached['checked_gmt'])
                    || (\time() - \strtotime($cached['checked_gmt'] . ' UTC')) > self::STATUS_STALE_S;
                // Re-format the status lines from the cached COUNTS for the current locale;
                // never return the transient's frozen text (it was built in whatever
                // language was active at scan time — see statusTexts()).
                $cidx = isset($cached['index_rows'])  && $cached['index_rows']  !== null ? (int) $cached['index_rows']  : null;
                $csch = isset($cached['scheme_rows']) && $cached['scheme_rows'] !== null ? (int) $cached['scheme_rows'] : null;
                return \array_merge($cached, $local, self::statusTexts($cidx, $csch), ['stale' => $stale]);
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

        $previous = \get_transient(self::STATUS_CACHE_KEY);
        if (
            ! $force
            && \is_array($previous) && ($previous['reachable'] ?? null) === false
            && !empty($previous['checked_gmt'])
            && \time() - (int) \strtotime($previous['checked_gmt'] . ' UTC') < 30
        ) {
            return self::statusData(false);
        }
        $token = RequestLock::acquire('cl_status_refresh_lock', 10);
        if ($token === null) {
            return \array_merge(self::statusData(false), ['refresh_pending' => true]);
        }
        try {
            return self::refreshStatus($local);
        } finally {
            RequestLock::release('cl_status_refresh_lock', $token);
        }
    }

    /** @param array<string,mixed> $local */
    private static function refreshStatus(array $local): array
    {
        $api         = new Client(null, Client::CONTEXT_STATUS);
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

        $data = \array_merge($local, self::statusTexts(
            $index_rows === null ? null : (int) $index_rows,
            $scheme_rows === null ? null : (int) $scheme_rows
        ), [
            'index_rows'         => $index_rows === null ? null : (int) $index_rows,
            'scheme_rows'        => $scheme_rows === null ? null : (int) $scheme_rows,
            'reachable'          => $reachable,
            'checked_gmt'        => \gmdate('Y-m-d H:i:s'),
            'stale'              => false,
        ]);
        \set_transient(self::STATUS_CACHE_KEY, $data, HOUR_IN_SECONDS);
        return $data;
    }

    /**
     * Format the readiness-card status lines for the CURRENT locale from raw counts.
     * Kept separate from the cached snapshot so switching site language re-localizes
     * the text — translated strings must never be frozen in a transient. A null count
     * means the API was unreachable.
     *
     * @return array{index_status_text:string,scheme_status_text:string}
     */
    private static function statusTexts(?int $index_rows, ?int $scheme_rows): array
    {
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
        return ['index_status_text' => $index_status_text, 'scheme_status_text' => $scheme_status_text];
    }
}
