<?php

declare(strict_types=1);

namespace CherryLink\Support;

use CherryLink\Contracts\Registerable;

/**
 * License validation + activation lifecycle.
 *
 * Owns the "call home" check against seocherry.ru, the cached validity state in the
 * linkate_posts_meta option, the daily re-check cron, the settings-page "index out of
 * sync while unlicensed" notice, and the administrator capability grant. Ported from
 * the legacy cherrylink_install.php (deleted); the activation/update lifecycle now lives
 * in Support\Lifecycle, which calls install() on activation and after an update.
 *
 * All API indexing (save hook, deletes, scans) is gated on isLicensed(); while
 * unlicensed, post changes are not pushed to the API and the index drifts, which the
 * admin notice surfaces until a scan reconciles it.
 */
final class License implements Registerable
{
    public const VALIDATION_HOOK = 'clpro_license_validation_hook';
    public const DEFAULT_LICENSING_URL = 'https://cherrylink.apov.dev/cherrylink-pro-licensing/';
    private const MAX_ATTEMPTS = 3;

    /** @var Options */
    private $options;

    public function __construct(Options $options)
    {
        $this->options = $options;
    }

    public function register(): void
    {
        \add_action('clpro_license_cron_hook', [$this, 'runDailyCheck']);
        \add_action(self::VALIDATION_HOOK, [$this, 'runScheduledValidation']);
        \add_action('update_option_linkate-posts', [$this, 'onOptionsUpdated'], 10, 2);
        if (! \wp_next_scheduled('clpro_license_cron_hook')) {
            \wp_schedule_event(\time(), 'daily', 'clpro_license_cron_hook');
        }

        \add_action('admin_notices', [$this, 'indexNotice']);

        // Let other plugins grant access to the CherryLink settings page.
        \add_action('plugins_loaded', [$this, 'addCap']);
    }

    // ── activation / install ────────────────────────────────────────────────

    /** Set up default options on install / update / "revert to defaults". */
    public function install(): bool
    {
        Options::fill($this->options->main());

        return true;
    }

    // ── license state ───────────────────────────────────────────────────────

    /**
     * Base URL of the licensing authority (check + connect). A wp-config constant
     * overrides it for local development.
     */
    public static function licensingUrl(): string
    {
        $url = \defined('CHERRYLINK_LICENSING_URL') ? (string) \constant('CHERRYLINK_LICENSING_URL') : '';
        if ($url === '') {
            $url = self::DEFAULT_LICENSING_URL;
        }

        return \rtrim($url, '/') . '/';
    }

    /** A key is stored (valid or not). */
    public function hasKey(): bool
    {
        return \trim((string) $this->options->get('hash_field', '')) !== '';
    }

    /** True when the site is connected: a key is stored and validated (free or paid). */
    public function isConnected(): bool
    {
        return $this->hasKey() && $this->isValid();
    }

    /** Gate for all indexing/panel work: a validated key (free or paid) is on file. */
    public function isLicensed(): bool
    {
        return $this->isValid();
    }

    /**
     * A free plan never expires (also the paid_expired fallback, whose expires_at is
     * in the past); every other plan needs a future expiry.
     *
     * @param array<string,mixed> $meta
     */
    private static function metaActive(array $meta): bool
    {
        if (empty($meta['key_valid'])) {
            return false;
        }
        if (($meta['plan'] ?? '') === 'free') {
            return true;
        }

        return \strtotime((string) ($meta['expires_at'] ?? '')) > \time();
    }

    /** Cached validity: key marked valid AND (free plan OR not past its expiry date). */
    public function isValid(): bool
    {
        $meta = $this->options->meta();
        if (isset($meta['key_valid']) && $meta['key_valid']) {
            $stored_hash = (string) ($meta['validation_key_hash'] ?? '');
            $current_key = (string) $this->options->get('hash_field', '');
            if ($stored_hash !== '' && $stored_hash !== $this->keyFingerprint($current_key)) {
                return false;
            }
            return self::metaActive($meta);
        }

        return false;
    }

    /** Plan post limit for a validated key; null = unlimited (legacy keys and no key). */
    public function postLimit(): ?int
    {
        $meta = $this->options->meta();
        if (! $this->isValid() || ! isset($meta['post_limit']) || ! \is_numeric($meta['post_limit'])) {
            return null;
        }
        $limit = (int) $meta['post_limit'];

        return $limit > 0 ? $limit : null;
    }

    /**
     * Copy a valid/invalid authority decision into the meta array (pure).
     * Plan fields are stored only when the authority sent a plan (legacy keeps none).
     *
     * @param array<string,mixed> $meta
     * @param \stdClass|array<string,mixed> $decision
     * @return array<string,mixed>
     */
    public static function applyDecision(array $meta, $decision): array
    {
        $d = (array) $decision;
        foreach (['plan', 'post_limit', 'features', 'paid_expired'] as $field) {
            unset($meta[$field]);
        }
        if (isset($d['plan']) && \is_string($d['plan']) && $d['plan'] !== '') {
            $meta['plan']         = $d['plan'];
            $meta['post_limit']   = isset($d['post_limit']) && \is_numeric($d['post_limit']) ? (int) $d['post_limit'] : null;
            $meta['features']     = isset($d['features']) ? (array) $d['features'] : [];
            $meta['paid_expired'] = ! empty($d['paid_expired']);
        }

        return $meta;
    }

    /**
     * Store a freshly connected (free) key and mark it validated, without a second
     * round trip. Writes the key first (its update hook resets validation), then the meta.
     *
     * @param array<string,mixed> $decision plan fields from the connect response
     */
    public function storeConnected(string $key, array $decision): void
    {
        $this->options->set('hash_field', $key);
        $meta = self::applyDecision($this->options->meta(), $decision);
        $meta['key_valid']            = true;
        $meta['expires_at']           = '';
        $meta['key_error_reason']     = '';
        $meta['validation_status']    = 'valid';
        $meta['validation_attempt']   = 0;
        $meta['validation_next_gmt']  = '';
        $meta['validation_key_hash']  = $this->keyFingerprint($key);
        $this->options->saveMeta($meta);
        if (\function_exists('wp_clear_scheduled_hook')) {
            // The key-change hook queued a validation we no longer need.
            \wp_clear_scheduled_hook(self::VALIDATION_HOOK);
        }
    }

    /** Forget the key and its validation state (the old "remove licence" action). */
    public function disconnect(): void
    {
        $options = $this->options->main();
        $meta    = $this->options->meta();
        $meta['key_valid']  = false;
        $meta['expires_at'] = '';
        $options['hash_field'] = '';
        unset($options['activations_left']);
        unset(
            $meta['validation_key_hash'],
            $meta['validation_status'],
            $meta['validation_attempt'],
            $meta['validation_next_gmt'],
            $meta['key_error_reason']
        );
        $this->options->saveMain($options);
        $this->options->saveMeta($meta);
        if (\function_exists('wp_clear_scheduled_hook')) {
            \wp_clear_scheduled_hook(self::VALIDATION_HOOK);
        }
    }

    /** Queue a key check without making an external request in the current page render. */
    public function scheduleValidation(bool $resetAttempts = true, bool $keyChanged = false): bool
    {
        $key  = (string) $this->options->get('hash_field', '');
        $hash = $this->keyFingerprint($key);
        $meta = $this->options->meta();
        $changed = isset($meta['validation_key_hash']) && $meta['validation_key_hash'] !== $hash;

        // A newly submitted key must never inherit the previous key's activation.
        if ($changed || $keyChanged) {
            $meta['key_valid'] = false;
            $meta['expires_at'] = '';
            unset($meta['plan'], $meta['post_limit'], $meta['features'], $meta['paid_expired']);
        }

        $meta['validation_key_hash'] = $hash;
        $meta['validation_status'] = 'pending';
        $meta['key_error_reason'] = '';
        $meta['validation_attempt'] = $resetAttempts ? 0 : (int) ($meta['validation_attempt'] ?? 0);
        $meta['validation_next_gmt'] = '';
        if ($key === '') {
            $meta['validation_status'] = 'error';
            $meta['key_error_reason'] = 'missing_key';
            $this->options->saveMeta($meta);
            return false;
        }
        $this->options->saveMeta($meta);
        if (! $this->queueValidation(0)) {
            $meta['validation_status'] = 'error';
            $meta['key_error_reason'] = 'validation_schedule_failed';
            $meta['validation_next_gmt'] = '';
            $this->options->saveMeta($meta);
            return false;
        }
        return true;
    }

    /**
     * Imports and other option writers must not transfer the old key's activation.
     *
     * @param mixed $old
     * @param mixed $new
     */
    public function onOptionsUpdated($old, $new): void
    {
        $old_key = \is_array($old) ? ($old['hash_field'] ?? '') : '';
        $new_key = \is_array($new) ? ($new['hash_field'] ?? '') : '';
        if ($old_key !== $new_key) {
            $this->scheduleValidation(true, true);
        }
    }

    /** Backward-compatible entry point; validation is now always scheduled. */
    public function validate(): bool
    {
        $this->scheduleValidation();
        return $this->isValid();
    }

    /**
     * POST the license key + domain to the licensing endpoint.
     *
     * @param mixed $key
     * @return \stdClass|false|null decoded response, or false on transport/HTTP error
     */
    public function callHome($key, string $domain)
    {
        if (\trim((string) $key) === '') {
            return false; // nothing to check, and no request before the site is connected
        }
        $response = \wp_remote_post(
            self::licensingUrl(),
            [
                'method'      => 'POST',
                'timeout'     => 5,
                'redirection' => 0,
                'httpversion' => '1.0',
                'blocking'    => true,
                'headers'     => [],
                'body'        => ['license_key' => $key, 'action' => 'check', 'domain' => $domain],
                'cookies'     => [],
            ]
        );

        if (\is_wp_error($response)) {
            return false;
        }
        if ((int) \wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }
        $body = \wp_remote_retrieve_body($response);
        $decoded = \json_decode($body);
        if (! \is_object($decoded) || ! isset($decoded->key_valid) || ! \is_scalar($decoded->key_valid)) {
            return false;
        }
        if (! \in_array((string) $decoded->key_valid, ['0', '1'], true)) {
            return false;
        }
        // Free keys never expire: the authority answers plan 'free' with expires_at null.
        $free_no_expiry = (string) $decoded->key_valid === '1'
            && isset($decoded->plan) && $decoded->plan === 'free'
            && (! isset($decoded->expires_at) || $decoded->expires_at === null);
        if (! $free_no_expiry) {
            if (! isset($decoded->expires_at) || ! \is_string($decoded->expires_at)) {
                return false;
            }
            if (
                (string) $decoded->key_valid === '1'
                && \strtotime($decoded->expires_at) === false
            ) {
                return false;
            }
        }
        if (
            (string) $decoded->key_valid === '0'
            && (! isset($decoded->key_error_reason) || ! \is_string($decoded->key_error_reason))
        ) {
            return false;
        }
        return $decoded;
    }

    // ── hooks ────────────────────────────────────────────────────────────────

    /** Daily cron callback: refresh the cached license state. */
    public function runDailyCheck(): void
    {
        $this->performValidation(true);
    }

    /** Single-event validation callback, including bounded retries. */
    public function runScheduledValidation(): void
    {
        $this->performValidation(false);
    }

    private function performValidation(bool $daily): void
    {
        $this->refreshOptionCache(Options::MAIN);
        $key = (string) $this->options->get('hash_field', '');
        $lock_token = $key === '' ? null : RequestLock::acquire('clpro_license_validation_lock', 60);
        if ($lock_token === null) {
            if ($key !== '') {
                if (! $this->queueValidation(30)) {
                    $this->markScheduleFailure($key);
                }
            }
            return;
        }

        try {
            $key_hash = $this->keyFingerprint($key);
            $domain = (string) \wp_parse_url(\home_url(), PHP_URL_HOST);
            $domain = \sanitize_text_field($domain);
            $response = $domain !== '' ? $this->callHome($key, $domain) : false;

            // Never commit a response for a key that was replaced while the request ran.
            $this->refreshOptionCache(Options::MAIN);
            $this->refreshOptionCache(Options::META);
            $current_key = (string) $this->options->get('hash_field', '');
            $meta = $this->options->meta();
            $stored_key_hash = (string) ($meta['validation_key_hash'] ?? '');
            if (
                $current_key !== $key
                || $this->keyFingerprint($current_key) !== $key_hash
                || ($stored_key_hash !== '' && $stored_key_hash !== $key_hash)
            ) {
                if ($current_key !== '') {
                    $this->scheduleValidation(true, true);
                }
                return;
            }

            if ($response !== false) {
                $meta = self::applyDecision($meta, $response);
                $meta['key_valid'] = (int) $response->key_valid === 1;
                $meta['expires_at'] = isset($response->expires_at) ? (string) $response->expires_at : '';
                $meta['key_error_reason'] = $meta['key_valid'] ? '' : (string) $response->key_error_reason;
                $meta['validation_status'] = $meta['key_valid'] ? 'valid' : 'error';
                $meta['validation_attempt'] = 0;
                $meta['validation_next_gmt'] = '';
                $meta['validation_key_hash'] = $key_hash;
                $this->options->saveMeta($meta);
                return;
            }

            // An outage never invalidates an unexpired key already validated for this key.
            // Existing installations predate the fingerprint. Their cached active key
            // is still treated as the current key until an explicit key change records one.
            $same_key = isset($meta['validation_key_hash'])
                ? $meta['validation_key_hash'] === $key_hash
                : ! empty($meta['key_valid']);
            $valid_now = $same_key && self::metaActive($meta);
            if ($valid_now) {
                $meta['validation_status'] = 'valid';
            } else {
                $meta['key_valid'] = false;
                $meta['validation_status'] = 'error';
                $meta['key_error_reason'] = 'no_connection';
            }

            $attempt = ($daily ? 0 : (int) ($meta['validation_attempt'] ?? 0)) + 1;
            $meta['validation_attempt'] = $attempt;
            if ($attempt < self::MAX_ATTEMPTS) {
                $delay = $attempt === 1 ? 30 : 120;
                $next = \time() + $delay;
                $meta['validation_next_gmt'] = \gmdate('Y-m-d H:i:s', $next);
                $meta['validation_status'] = $valid_now ? 'valid' : 'pending';
                if (! $this->queueValidation($delay)) {
                    $meta['validation_status'] = 'error';
                    $meta['key_error_reason'] = 'validation_schedule_failed';
                    $meta['validation_next_gmt'] = '';
                }
            } else {
                $meta['validation_next_gmt'] = '';
            }
            $this->options->saveMeta($meta);
        } finally {
            RequestLock::release('clpro_license_validation_lock', $lock_token);
        }
    }

    private function queueValidation(int $delay): bool
    {
        if (! \wp_next_scheduled(self::VALIDATION_HOOK)) {
            $scheduled = \wp_schedule_single_event(\time() + $delay, self::VALIDATION_HOOK);
            return $scheduled === true;
        }
        return true;
    }

    private function markScheduleFailure(string $key): void
    {
        $this->refreshOptionCache(Options::META);
        $meta = $this->options->meta();
        $stored_key_hash = (string) ($meta['validation_key_hash'] ?? '');
        $same_key = $stored_key_hash !== ''
            ? $stored_key_hash === $this->keyFingerprint($key)
            : ! empty($meta['key_valid']);
        $valid_now = $same_key && self::metaActive($meta);
        if (! $valid_now) {
            $meta['key_valid'] = false;
            $meta['expires_at'] = '';
        }
        $meta['validation_status'] = 'error';
        $meta['key_error_reason'] = 'validation_schedule_failed';
        $meta['validation_next_gmt'] = '';
        $this->options->saveMeta($meta);
    }

    private function keyFingerprint(string $key): string
    {
        return \hash('sha256', $key);
    }

    private function refreshOptionCache(string $name): void
    {
        \wp_cache_delete($name, 'options');
        // WordPress keeps autoloaded options together, so invalidate that bucket too.
        \wp_cache_delete('alloptions', 'options');
    }

    /** Grant the settings-page capability to administrators. */
    public function addCap(): void
    {
        $role = \get_role('administrator');
        if (\is_object($role)) {
            $role->add_cap('cherrylink_settings');
        }
    }

    /** Warn on the settings page when the index has drifted while unlicensed. */
    public function indexNotice(): void
    {
        if (! \current_user_can('edit_posts') || $this->isLicensed()) {
            return;
        }
        // No key at all: the setup wizard / key card explains what to do.
        if (\trim((string) $this->options->get('hash_field', '')) === '') {
            return;
        }
        // Only on the CherryLink settings page — not every admin screen.
        if (! PluginMeta::isSettingsPage()) {
            return;
        }

        $dirty = $this->options->indexIsDirty();
        $html  = (new View())->render('notices/license-inactive', [
            'dirty'        => $dirty,
            'since'        => $dirty ? \get_date_from_gmt($this->options->indexDirtySince()) : '',
            'settings_url' => \admin_url('admin.php?page=cherrylink-settings'),
        ]);
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- View renders pre-escaped template output
        echo $html;
    }
}
