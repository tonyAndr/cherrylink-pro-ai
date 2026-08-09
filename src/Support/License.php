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
 * the legacy cherrylink_install.php; that file now keeps only thin global shims for the
 * handful of callers (settings page, activation/update hooks, indexing gate).
 *
 * All API indexing (save hook, deletes, scans) is gated on isLicensed(); while
 * unlicensed, post changes are not pushed to the API and the index drifts, which the
 * admin notice surfaces until a scan reconciles it.
 */
final class License implements Registerable
{
    /** @var Options */
    private $options;

    public function __construct(Options $options)
    {
        $this->options = $options;
    }

    public function register(): void
    {
        \add_action('clpro_license_cron_hook', [$this, 'runDailyCheck']);
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

    /** True when a valid, non-expired license is on file. */
    public function isLicensed(): bool
    {
        return $this->isValid();
    }

    /** Cached validity: key marked valid AND not past its expiry date. */
    public function isValid(): bool
    {
        $meta = $this->options->meta();
        if (isset($meta['key_valid']) && $meta['key_valid']) {
            return \strtotime((string) $meta['expires_at']) > \time();
        }

        return false;
    }

    /**
     * Re-validate against the licensing server and cache the result in meta.
     * Returns the resulting key_valid flag. On a connection failure an already-valid
     * license is left untouched (returns true); a never-validated one stays invalid.
     */
    public function validate(): bool
    {
        $meta   = $this->options->meta();
        $domain = isset($_SERVER['HTTP_HOST'])
            ? \sanitize_text_field(\wp_unslash($_SERVER['HTTP_HOST']))
            : (isset($_SERVER['SERVER_NAME']) ? \sanitize_text_field(\wp_unslash($_SERVER['SERVER_NAME'])) : '');

        $response = $this->callHome($this->options->get('hash_field', ''), $domain);

        if ($response !== false) {
            if (\intval($response->key_valid) === 1) {
                $meta['key_valid']        = true;
                $meta['expires_at']       = $response->expires_at;
                $meta['key_error_reason'] = '';
            } else {
                $meta['key_valid']        = false;
                $meta['expires_at']       = $response->expires_at;
                $meta['key_error_reason'] = $response->key_error_reason;
            }
        } else {
            // probably server/connection error — don't deactivate an active key
            if (! isset($meta['key_valid']) || ! $meta['key_valid']) {
                $meta['key_valid']        = false;
                $meta['key_error_reason'] = 'no_connection';
            } else {
                return true;
            }
        }

        $this->options->saveMeta($meta);

        (new Logger())->log('key validation');
        (new Logger())->log($meta['key_valid']);

        return (bool) $meta['key_valid'];
    }

    /**
     * POST the license key + domain to the licensing endpoint.
     *
     * @param mixed $key
     * @return \stdClass|false|null decoded response, or false on transport/HTTP error
     */
    public function callHome($key, string $domain)
    {
        $response = \wp_remote_post(
            'https://seocherry.ru/cherrylink-pro-licensing/',
            [
                'method'      => 'POST',
                'timeout'     => 30,
                'redirection' => 5,
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
        if ($response['response']['code'] != 200) {
            return false;
        }

        return \json_decode($response['body']);
    }

    // ── hooks ────────────────────────────────────────────────────────────────

    /** Daily cron callback: refresh the cached license state. */
    public function runDailyCheck(): void
    {
        $this->validate();
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
        // Only on the CherryLink settings page — not every admin screen.
        if (! \class_exists('LinkatePosts') || ! \LinkatePosts::linkate_is_plugin_admin_page('settings')) {
            return;
        }

        $dirty = $this->options->indexIsDirty();
        $html  = (new View())->render('notices/license-inactive', [
            'dirty'        => $dirty,
            'since'        => $dirty ? \get_date_from_gmt($this->options->indexDirtySince()) : '',
            'settings_url' => \admin_url('options-general.php?page=cherrylink-pro&subpage=main'),
        ]);
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- View renders pre-escaped template output
        echo $html;
    }
}
