<?php

declare(strict_types=1);

namespace CherryLink\Support;

use CherryLink\Contracts\Registerable;
use CherryLink\Editor\LinkMarkup;
use CherryLink\Indexing\AsyncBulkScan;
use CherryLink\Indexing\IndexHooks;
use CherryLink\Indexing\Outbox\Delivery;
use CherryLink\Indexing\Outbox\Schema;
use CherryLink\Indexing\SyncService;
use CherryLink\Plugin;
use CherryLink\Support\Capabilities;

/**
 * Plugin lifecycle wiring: load the text domain, run the install/defaults routine on
 * activation (with first-install bookkeeping + a manual-scan prompt on large sites),
 * and re-run it after WordPress finishes updating the plugin.
 *
 * Ported from the procedural bootstrap in the legacy cherrylink_main.php
 * (linkate_posts_init text-domain load, lp_activate, linkate_on_update,
 * linkate_redirectToUpdatePlugin).
 */
final class Lifecycle implements Registerable
{
    /** @var License */
    private $license;

    /** @var Capabilities|null Warms the capability cache from cron after activation. */
    private $capabilities;

    /** @var PluginReports */
    private $reports;

    public function __construct(
        License $license,
        ?Capabilities $capabilities = null,
        ?PluginReports $reports = null
    ) {
        $this->license      = $license;
        $this->capabilities = $capabilities;
        $this->reports      = $reports ?? new PluginReports($license);
    }

    public function register(): void
    {
        \add_action('init', [$this, 'loadTextdomain'], 1);
        \register_activation_hook(Plugin::instance()->file(), [$this, 'activate']);
        \register_deactivation_hook(Plugin::instance()->file(), [$this, 'deactivate']);
        \add_action('upgrader_process_complete', [$this, 'onUpdate'], 10, 2);
        \add_action('plugins_loaded', [$this, 'maybeFinishUpdate']);
        \add_action('plugins_loaded', [$this, 'maybeInstallOutbox'], 20);
        \add_action('plugins_loaded', [$this, 'maybeMigrateOptions'], 20);
    }

    public function loadTextdomain(): void
    {
        \load_plugin_textdomain(
            'cherrylink',
            false,
            \dirname(\plugin_basename(Plugin::instance()->file())) . '/languages'
        );
    }

    /** Activation: install defaults, record first-install, prompt a scan on large sites. */
    public function activate(): void
    {
        $this->license->install();
        Schema::install();
        IndexHooks::clearStatusCaches();
        if ($this->capabilities !== null) {
            $this->capabilities->forget();
        }

        $meta = \get_option('linkate_posts_meta', []);
        if (empty($meta['first_version'])) {
            $meta['first_version'] = PluginMeta::version();
            $meta['first_install'] = \current_time('timestamp');
            \update_option('linkate_posts_meta', $meta);
        }

        // Reporting is queue-only: no HTTP during activation. A failure here must
        // never block activation, so keep it best-effort.
        try {
            $this->reports->recordActivation();
        } catch (\Throwable $error) {
            \error_log('CherryLink: could not queue plugin report: ' . $error->getMessage());
        }
    }

    /**
     * Deactivation: unschedule our cron events and cancel our queued background work.
     *
     * WordPress keeps scheduled events in the options table across deactivation, so
     * without this the capability refresh, the two recovery sweeps and the licence
     * check keep firing with no callbacks registered.
     */
    public function deactivate(): void
    {
        $hooks = [
            Capabilities::REFRESH_HOOK,
            Delivery::SWEEP_HOOK,
            AsyncBulkScan::SWEEP_HOOK,
            License::VALIDATION_HOOK,
            'clpro_license_cron_hook',
        ];
        foreach ($hooks as $hook) {
            if (\function_exists('wp_clear_scheduled_hook')) {
                \wp_clear_scheduled_hook($hook);
            }
        }

        // Drop ours from the Action Scheduler queue; other plugins keep theirs.
        if (! \class_exists('ActionScheduler_Store') || ! \function_exists('as_get_scheduled_actions')) {
            return;
        }
        foreach ([SyncService::GROUP, Delivery::GROUP, AsyncBulkScan::GROUP] as $group) {
            $ids = \as_get_scheduled_actions([
                'group'    => $group,
                'status'   => \ActionScheduler_Store::STATUS_PENDING,
                'per_page' => -1,
            ], 'ids');
            foreach ((array) $ids as $id) {
                try {
                    \ActionScheduler::store()->cancel_action($id);
                } catch (\Throwable $error) {
                    // best effort
                }
            }
        }
    }

    /**
     * Flag that this plugin was just updated so maybeFinishUpdate() can re-run install.
     *
     * @param mixed               $upgrader
     * @param array<string,mixed> $options
     */
    public function onUpdate($upgrader, $options): void
    {
        $self = \plugin_basename(Plugin::instance()->file());
        if (($options['action'] ?? '') === 'update' && ($options['type'] ?? '') === 'plugin') {
            foreach ((array) ($options['plugins'] ?? []) as $each) {
                if ($each === $self) {
                    \set_transient('cherrylink_updated', 1);
                    break;
                }
            }
        }
    }

    /** After WordPress updates the plugin, re-run install (option defaults/migrations). */
    public function maybeFinishUpdate(): void
    {
        if (\get_transient('cherrylink_updated') && \current_user_can('update_plugins')) {
            $this->license->install();
            Schema::install();
            IndexHooks::clearStatusCaches();
            if ($this->capabilities !== null) {
                $this->capabilities->forget();
            }
            \set_transient('cherrylink_updated', 0);
        }
    }

    /**
     * Defensive schema upgrade: run when the stored outbox version is stale (covers
     * an activation/update that crashed before dbDelta completed). One option read
     * per request; dbDelta only runs when the version is actually behind.
     */
    public function maybeInstallOutbox(): void
    {
        Schema::maybeInstall();
    }

    /**
     * 2.x upgrade: replace the link templates with the three markup settings and drop the
     * removed options (alternative-server toggle, opt-in reports flag). Runs once; the
     * stored array is only touched while a legacy key is still present.
     */
    public function maybeMigrateOptions(): void
    {
        $options = \get_option(Options::MAIN, []);
        if (! \is_array($options)) {
            return;
        }
        $legacy = ['link_before', 'link_after', 'term_before', 'term_after', 'link_temp_alt', 'term_temp_alt', 'use_alt_server', 'lifecycle_reports'];
        if (! \array_intersect($legacy, \array_keys($options))) {
            return;
        }
        $options = LinkMarkup::migrate($options);
        unset($options['use_alt_server'], $options['lifecycle_reports']);
        \update_option(Options::MAIN, $options);
        \delete_transient('cherrylink_relay_until');
    }
}
