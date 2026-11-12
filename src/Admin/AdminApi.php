<?php

declare(strict_types=1);

namespace CherryLink\Admin;

use CherryLink\Ajax\Security;
use CherryLink\Api\Client;
use CherryLink\Contracts\Registerable;
use CherryLink\Editor\PanelConfig;
use CherryLink\Indexing\IndexHelpers;
use CherryLink\Indexing\IndexHooks;
use CherryLink\Indexing\SyncService;
use CherryLink\Support\Capabilities;
use CherryLink\Support\License;
use CherryLink\Support\Options;

/**
 * admin-ajax endpoints of the React admin app (contract: specs/admin-ui.md) plus the
 * state/settings/link-health builders. Every action is nonce + `cherrylink_settings` gated
 * and answers with wp_send_json_success / wp_send_json_error({message}).
 */
final class AdminApi implements Registerable
{
    public const HEALTH_OPTION = 'cherrylink_link_health';
    public const SETUP_OPTION  = 'cherrylink_setup_done';

    private const ACTIONS = ['state', 'link_health', 'save_settings', 'license', 'remove_data', 'import_settings', 'reset_settings', 'setup_done'];
    private const RELATIVE = ['full', 'no_proto', 'no_domain'];
    private const MAX_IMPORT_BYTES = 262144;

    /** @var Options */
    private $options;

    /** @var License */
    private $license;

    /** @var SyncService */
    private $sync;

    public function __construct(Options $options, License $license, SyncService $sync)
    {
        $this->options = $options;
        $this->license = $license;
        $this->sync    = $sync;
    }

    public function register(): void
    {
        foreach (self::ACTIONS as $action) {
            \add_action('wp_ajax_cherrylink_admin_' . $action, [$this, 'ajax' . \str_replace('_', '', \ucwords($action, '_'))]);
        }
    }

    // ── AJAX ───────────────────────────────────────────────────────────────

    public function ajaxState(): void
    {
        $this->guard();
        \wp_send_json_success($this->state());
    }

    public function ajaxLinkHealth(): void
    {
        $this->guard();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- gated by guard()
        if (empty($_POST['refresh'])) {
            \wp_send_json_success($this->cachedHealth());
        }
        // The explicit "Check links" action uses the background client: the problem analysis
        // can take far longer than the 5 s interactive budget, and the admin asked for it.
        $api      = new Client(null, Client::CONTEXT_BACKGROUND);
        $response = $api->get_link_problems();
        if (! \is_array($response)) {
            $this->fail(\__('Could not reach the CherryLink server. Try again in a moment.', 'cherrylink'));
        }
        $health = $this->buildHealth($response);
        \update_option(self::HEALTH_OPTION, $health, false);
        \wp_send_json_success($health);
    }

    public function ajaxSaveSettings(): void
    {
        $this->guard();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- gated by guard(); decoded then sanitized key by key
        $raw = \json_decode((string) \wp_unslash($_POST['settings'] ?? ''), true);
        if (! \is_array($raw)) {
            $this->fail(\__('Invalid settings.', 'cherrylink'));
        }
        $this->saveSettings($raw);
        \wp_send_json_success(['state' => $this->state()]);
    }

    public function ajaxLicense(): void
    {
        $this->guard();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- gated by guard()
        $op = isset($_POST['op']) ? \sanitize_key(\wp_unslash($_POST['op'])) : '';
        switch ($op) {
            case 'set_key':
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- gated by guard()
                $key = \trim(\sanitize_text_field(\wp_unslash($_POST['key'] ?? '')));
                if ($key === '') {
                    $this->fail(\__('Enter a license key.', 'cherrylink'));
                }
                $previous = (string) $this->options->get('hash_field', '');
                $this->options->set('hash_field', $key);
                IndexHooks::clearStatusCaches();
                $this->license->scheduleValidation(true, $previous !== $key);
                break;
            case 'recheck':
                $this->license->scheduleValidation();
                break;
            case 'disconnect':
                $this->license->disconnect();
                IndexHooks::clearStatusCaches();
                break;
            default:
                $this->fail(\__('Unknown action.', 'cherrylink'));
        }
        \wp_send_json_success(['state' => $this->state()]);
    }

    public function ajaxRemoveData(): void
    {
        $this->guard();
        $api      = new Client();
        $ok_docs  = $api->delete_all_documents();
        $ok_links = $api->delete_all_links();
        if (! $ok_docs || ! $ok_links) {
            $this->fail(\__('Could not delete the site data on the CherryLink server. Check the connection and try again.', 'cherrylink'));
        }
        $meta = $this->options->meta();
        unset($meta['indexing_process']);
        $this->options->saveMeta($meta);
        \delete_option(self::HEALTH_OPTION);
        IndexHooks::clearStatusCaches();
        \wp_send_json_success(['state' => $this->state()]);
    }

    public function ajaxImportSettings(): void
    {
        $this->guard();
        $file = $_FILES['file'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput -- gated by guard(); name/tmp_name checked below
        $name = \is_array($file) ? \sanitize_file_name((string) ($file['name'] ?? '')) : '';
        $valid = \is_array($file) && \strtolower((string) \pathinfo($name, PATHINFO_EXTENSION)) === 'txt'
            && \is_uploaded_file((string) $file['tmp_name']) && (int) ($file['size'] ?? 0) <= self::MAX_IMPORT_BYTES;
        if (! $valid || ! \is_array($file)) {
            $this->fail(\__('Could not import: choose a .txt file exported from CherryLink.', 'cherrylink'));
        }
        \parse_str((string) \file_get_contents((string) $file['tmp_name']), $parsed);
        $this->saveSettings(self::importToSettings($parsed));
        \wp_send_json_success(['state' => $this->state()]);
    }

    public function ajaxResetSettings(): void
    {
        $this->guard();
        Options::fill(null); // keeps the license key
        \wp_send_json_success(['state' => $this->state()]);
    }

    public function ajaxSetupDone(): void
    {
        $this->guard();
        \update_option(self::SETUP_OPTION, 1, false);
        \wp_send_json_success([]);
    }

    private function guard(): void
    {
        Security::verify('cherrylink_settings');
    }

    /** @return never */
    private function fail(string $message): void
    {
        \wp_send_json_error(['message' => $message]);
    }

    // ── Settings ───────────────────────────────────────────────────────────

    /**
     * Old exports are stored-form (`show_pages`, `show_customs`, link templates): migrate the
     * link templates and express content types as `post_types`, then the normal whitelist applies.
     *
     * @param array<string,mixed> $parsed
     * @return array<string,mixed>
     */
    public static function importToSettings(array $parsed): array
    {
        $parsed = \CherryLink\Editor\LinkMarkup::migrate($parsed);
        if (! isset($parsed['post_types']) && (isset($parsed['show_pages']) || isset($parsed['show_customs']))) {
            $parsed['post_types'] = \array_merge(
                ($parsed['show_pages'] ?? '') === 'true' ? ['page'] : [],
                self::ids((string) ($parsed['show_customs'] ?? ''), false)
            );
        }

        return $parsed;
    }

    /**
     * Whitelist + sanitize a settings object into the stored string forms. Unknown keys are
     * ignored and absent keys are left out (partial saves).
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public static function sanitizeSettings(array $in): array
    {
        $out  = [];
        $bool = static function ($v): string {
            return \filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 'true' : 'false';
        };
        if (isset($in['limit_ajax'])) {
            $out['limit_ajax'] = \max(1, \min(100, (int) $in['limit_ajax']));
        }
        if (isset($in['max_incoming_links'])) {
            $out['max_incoming_links'] = \max(1, (int) $in['max_incoming_links']);
        }
        foreach (['show_cat_filter', 'match_cat', 'consider_max_incoming_links', 'link_title_attr', 'link_new_tab'] as $key) {
            if (isset($in[$key])) {
                $out[$key] = $bool($in[$key]);
            }
        }
        if (isset($in['excluded_authors'])) {
            $out['excluded_authors'] = \implode(',', self::ids($in['excluded_authors'], true));
        }
        if (isset($in['excluded_cats'])) {
            // Stored with the whole subtree, as the engine filters on this list directly.
            $cats = self::ids($in['excluded_cats'], true);
            foreach ($cats as $cat) {
                $cats = \array_merge($cats, \array_map('intval', (array) \get_term_children((int) $cat, 'category')));
            }
            $out['excluded_cats'] = \implode(',', \array_unique($cats));
        }
        if (isset($in['relative_links']) && \in_array($in['relative_links'], self::RELATIVE, true)) {
            $out['relative_links'] = $in['relative_links'];
        }
        if (isset($in['link_class'])) {
            $tokens = [];
            foreach ((array) \preg_split('/\s+/', \trim((string) $in['link_class'])) as $token) {
                $token = \sanitize_html_class((string) $token);
                if ($token !== '') {
                    $tokens[] = $token;
                }
            }
            $out['link_class'] = \implode(' ', \array_unique($tokens));
        }
        if (isset($in['post_types'])) {
            $types = self::ids($in['post_types'], false);
            $known = \array_diff(IndexHelpers::get_public_types_list(), ['post', 'page']);
            $out['show_pages']   = \in_array('page', $types, true) ? 'true' : 'false';
            $out['show_customs'] = \implode(',', \array_values(\array_intersect($types, $known)));
        }
        if (isset($in['vector_custom_fields'])) {
            $out['vector_custom_fields'] = \sanitize_textarea_field((string) $in['vector_custom_fields']);
        }

        return $out;
    }

    /** @param array<string,mixed> $in */
    private function saveSettings(array $in): void
    {
        $this->options->saveMain(\array_merge($this->options->main(), self::sanitizeSettings($in)));
        \delete_transient(PanelConfig::TOTAL_TRANSIENT); // content types change the eligible total
    }

    /**
     * List from an array or a comma string; ints (>0) or non-empty strings.
     *
     * @param mixed $value
     * @return array<int,int|string>
     */
    private static function ids($value, bool $numeric): array
    {
        $list = \is_array($value) ? $value : \explode(',', (string) $value);
        $out  = [];
        foreach ($list as $item) {
            if (! \is_scalar($item) || \trim((string) $item) === '') {
                continue;
            }
            if ($numeric) {
                if ((int) $item > 0) {
                    $out[] = (int) $item;
                }
            } else {
                $out[] = \sanitize_key((string) $item);
            }
        }

        return \array_values(\array_unique($out));
    }

    /** @return array<string,mixed> */
    private function typedSettings(): array
    {
        $main = $this->options->main();
        $bool = static function (string $key, bool $default = false) use ($main): bool {
            return \array_key_exists($key, $main) ? ($main[$key] === true || $main[$key] === 'true') : $default;
        };
        $relative = (string) ($main['relative_links'] ?? 'full');

        return [
            'limit_ajax'                  => (int) ($main['limit_ajax'] ?? 50),
            'show_cat_filter'             => $bool('show_cat_filter'),
            'match_cat'                   => $bool('match_cat'),
            'excluded_authors'            => self::ids((string) ($main['excluded_authors'] ?? ''), true),
            'excluded_cats'               => self::ids((string) ($main['excluded_cats'] ?? ''), true),
            'consider_max_incoming_links' => $bool('consider_max_incoming_links'),
            'max_incoming_links'          => (int) ($main['max_incoming_links'] ?? 20),
            'relative_links'              => \in_array($relative, self::RELATIVE, true) ? $relative : 'full',
            'link_title_attr'             => $bool('link_title_attr', true),
            'link_new_tab'                => $bool('link_new_tab'),
            'link_class'                  => (string) ($main['link_class'] ?? ''),
            'post_types'                  => \array_merge(
                $bool('show_pages') ? ['page'] : [],
                self::ids((string) ($main['show_customs'] ?? ''), false)
            ),
            'vector_custom_fields'        => (string) ($main['vector_custom_fields'] ?? ''),
        ];
    }

    // ── State ──────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function state(): array
    {
        $settings  = $this->typedSettings();
        $status    = IndexHooks::statusData(false);
        $snapshot  = (new Capabilities())->snapshot();
        $health    = $this->cachedHealth();
        $connected = $this->license->isConnected();
        $meta      = $this->options->meta();
        $learned   = $snapshot['plan']['indexed'] ?? ($status['index_rows'] ?? null);
        $total     = 0;
        $types     = [];
        foreach (IndexHelpers::get_public_types_list() as $name) {
            $obj   = \get_post_type_object($name);
            $counts = (array) \wp_count_posts($name);
            $count   = (int) ($counts['publish'] ?? 0);
            $enabled = $name === 'post' || \in_array($name, $settings['post_types'], true);
            $total  += $enabled ? $count : 0; // only analyzed types count toward the site total
            $types[] = [
                'name'    => $name,
                'label'   => $obj ? (string) $obj->labels->name : $name,
                'count'   => $count,
                'enabled' => $enabled,
            ];
        }

        return [
            'connected'  => $connected,
            'has_key'    => $this->license->hasKey(),
            'setup_done' => (bool) \get_option(self::SETUP_OPTION, false),
            'license'    => $this->licenseState($connected),
            'counts'     => [
                'total'   => $total,
                'types'   => $types,
                'learned' => $learned === null ? null : (int) $learned,
                'checked' => $health['summary']['posts_checked'] ?? null,
                'links'   => isset($status['scheme_rows']) ? (int) $status['scheme_rows'] : null,
            ],
            'sync'       => $this->sync->getState() + ['running' => $this->sync->isRunning()],
            'last_scan'  => empty($meta['last_scan_gmt']) ? null : [
                'gmt'      => (string) $meta['last_scan_gmt'],
                'upserted' => (int) ($meta['last_upserted'] ?? 0),
                'deleted'  => (int) ($meta['last_deleted'] ?? 0),
            ],
            'route'      => Client::route(),
            'settings'   => $settings,
            'settings_choices' => [
                'authors'    => $this->authors(),
                'categories' => PanelConfig::categories(),
            ],
        ];
    }

    /** @return array<int,array{id:int,name:string}> Users who can publish (the "hide authors" picker). */
    private function authors(): array
    {
        $out = [];
        foreach ((array) \get_users(['capability' => ['publish_posts'], 'fields' => ['ID', 'display_name'], 'orderby' => 'display_name', 'number' => 500]) as $user) {
            $out[] = ['id' => (int) $user->ID, 'name' => (string) $user->display_name];
        }

        return $out;
    }

    /** @return array<string,mixed> */
    private function licenseState(bool $connected): array
    {
        $meta   = $this->options->meta();
        $key    = (string) $this->options->get('hash_field', '');
        $reason = (string) ($meta['validation_status'] ?? '') === 'pending' ? 'pending' : (string) ($meta['key_error_reason'] ?? '');
        $plan   = null;
        if ($connected) {
            $plan = isset($meta['plan']) && \in_array($meta['plan'], ['free', 'pro'], true) ? (string) $meta['plan'] : 'legacy';
        }
        if ($connected) {
            $status = 'active';
        } elseif ($key === '') {
            $status = 'none';
        } else {
            $status = $reason === 'pending' ? 'pending' : 'error';
        }

        return [
            'plan'       => $plan,
            'post_limit' => $this->license->postLimit(),
            'key_masked' => $key === '' ? '' : (\strpos($key, 'clp_') === 0 ? 'clp_' : '') . '••••' . \substr($key, -4),
            'domain'     => (string) \wp_parse_url(\home_url(), PHP_URL_HOST),
            'expires_at' => ! empty($meta['expires_at']) ? (string) $meta['expires_at'] : null,
            'status'     => $status,
            'error'      => $status === 'active' ? '' : self::licenseError($reason),
        ];
    }

    private static function licenseError(string $reason): string
    {
        switch ($reason) {
            case 'no_connection':
                return \__('Could not reach the license server. Try again later or contact support.', 'cherrylink');
            case 'pending':
                return \__('The key is being checked in the background. Refresh in a few seconds.', 'cherrylink');
            case 'missing_key':
                return \__('Enter a license key.', 'cherrylink');
            case 'validation_schedule_failed':
                return \__('Could not schedule the key check. Try again.', 'cherrylink');
            case 'bad_domain':
                return \__('This key is bound to another domain.', 'cherrylink');
            case 'key_expired':
                return \__('This key has expired.', 'cherrylink');
            case 'wrong_key':
                return \__('This key is not valid.', 'cherrylink');
            default:
                return '';
        }
    }

    /** Translated message for a failed Connect redirect (cl_connect=<code>), or ''. */
    public function connectError(): string
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only outcome code, mapped to fixed strings
        $code = isset($_GET['cl_connect']) ? \sanitize_key(\wp_unslash($_GET['cl_connect'])) : '';
        $wait = isset($_GET['cl_retry']) ? (int) $_GET['cl_retry'] : 0;
        // phpcs:enable
        switch ($code) {
            case '':
            case 'ok':
                return '';
            case 'domain_proof_failed':
                return \__('Could not verify the domain: the CherryLink server could not open your site over HTTPS. The site must be reachable from the internet; for private sites enter a key manually.', 'cherrylink');
            case 'rate_limited':
                return $wait > 0
                    /* translators: %d: seconds to wait */
                    ? \sprintf(\__('Too many connection attempts. Try again in %d s.', 'cherrylink'), $wait)
                    : \__('Too many connection attempts. Try again later.', 'cherrylink');
            case 'invalid_domain':
                return \__('This domain cannot be connected (a public domain is required, not an IP address or localhost).', 'cherrylink');
            case 'subscription_not_eligible':
                return \__('Connecting is not available for this domain. Enter a key manually or contact support.', 'cherrylink');
            default:
                return \__('The connection service is temporarily unavailable. Try again later.', 'cherrylink');
        }
    }

    // ── Link health ────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function emptyHealth(): array
    {
        return [
            'checked_at' => null,
            'summary'    => ['orphans' => 0, 'broken' => 0, 'no_outgoing' => 0, 'repeats' => 0, 'self_links' => 0, 'links_total' => null, 'posts_checked' => null],
            'rows'       => [],
        ];
    }

    /** @return array<string,mixed> */
    private function cachedHealth(): array
    {
        $cached = \get_option(self::HEALTH_OPTION, null);

        return \is_array($cached) && isset($cached['summary'], $cached['rows']) ? $cached : $this->emptyHealth();
    }

    /**
     * Newest-`$limit` cutoff of eligible posts by post_modified_gmt (same rule as the server
     * admission), one query. Null = every post counts (no limit, or fewer posts than the limit).
     */
    public static function learnedCutoff(?int $limit): ?string
    {
        if ($limit === null || $limit < 1) {
            return null;
        }
        global $wpdb;
        $allowed = IndexHelpers::get_allowed_types_list();
        $ph      = \implode(',', \array_fill(0, \count($allowed), '%s'));
        $cutoff  = $wpdb->get_var($wpdb->prepare(
            "SELECT post_modified_gmt FROM {$wpdb->posts} WHERE post_type IN ($ph) AND post_status = 'publish'
             ORDER BY post_modified_gmt DESC, ID DESC LIMIT 1 OFFSET %d",
            \array_merge($allowed, [$limit - 1])
        ));

        return $cutoff === null ? null : (string) $cutoff;
    }

    /**
     * Shape the API problems response for the Link health screen.
     *
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    public function buildHealth(array $response): array
    {
        $cutoff  = self::learnedCutoff($this->license->postLimit());
        $allowed = IndexHelpers::get_allowed_types_list();
        $status  = IndexHooks::statusData(false);
        $summary = ['orphans' => 0, 'broken' => 0, 'no_outgoing' => 0, 'repeats' => 0, 'self_links' => 0];
        $rows    = [];

        foreach ((array) ($response['problems'] ?? []) as $p) {
            $url     = (string) ($p['source_url'] ?? '');
            $post_id = (int) ($p['source_id'] ?? 0);
            if (! $post_id) {
                $post_id = (int) \url_to_postid($url);
            }
            $post = $post_id ? \get_post($post_id) : null;
            $obj  = $post ? \get_post_type_object((string) $post->post_type) : null;

            $repeats = [];
            foreach ((array) ($p['repeated_targets'] ?? []) as $rt) {
                $repeats[] = ['target_url' => (string) ($rt['target_url'] ?? ''), 'count' => (int) ($rt['count'] ?? 0)];
            }
            $broken = [];
            foreach ((array) ($p['broken_links'] ?? []) as $bl) {
                $broken[] = ['target_url' => (string) ($bl['target_url'] ?? ''), 'anchor' => (string) ($bl['anchor'] ?? '')];
            }
            $self = [];
            foreach ((array) ($p['recursive_anchors'] ?? []) as $a) {
                $self[] = \is_array($a) ? (string) ($a['anchor'] ?? $a['anchor_text'] ?? '') : (string) $a;
            }
            $orphan      = ! empty($p['no_incoming']);
            $no_outgoing = ! empty($p['no_outgoing']);

            $summary['orphans']     += (int) $orphan;
            $summary['no_outgoing'] += (int) $no_outgoing;
            $summary['repeats']     += (int) ($repeats !== []);
            $summary['broken']      += \count($broken);
            $summary['self_links']  += \count($self);

            $eligible = $post && $post->post_status === 'publish' && \in_array($post->post_type, $allowed, true);
            $rows[] = [
                'post_id'     => $post_id,
                'title'       => $post ? \html_entity_decode((string) \get_the_title($post_id), ENT_QUOTES | ENT_HTML5, 'UTF-8') : $url,
                'type'        => $post ? (string) $post->post_type : '',
                'type_label'  => $obj ? (string) $obj->labels->singular_name : '',
                'url'         => $url,
                'edit_url'    => $post_id ? \admin_url('post.php?action=edit&post=' . $post_id) : '',
                // learned = analyzed for suggestions (counts toward the plan limit); unlimited plans learn everything.
                'learned'     => $eligible && ($cutoff === null || (string) $post->post_modified_gmt >= $cutoff),
                'orphan'      => $orphan,
                'no_outgoing' => $no_outgoing,
                'repeats'     => $repeats,
                'broken'      => $broken,
                'self'        => $self,
            ];
        }
        $summary['links_total']   = isset($response['links_total']) ? (int) $response['links_total'] : (isset($status['scheme_rows']) ? (int) $status['scheme_rows'] : null);
        $summary['posts_checked'] = isset($response['posts_checked']) ? (int) $response['posts_checked'] : null;

        return ['checked_at' => \gmdate('c'), 'summary' => $summary, 'rows' => $rows];
    }
}
