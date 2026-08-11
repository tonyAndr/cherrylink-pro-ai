<?php

declare(strict_types=1);

namespace CherryLink\Support;

use CherryLink\Contracts\Registerable;
use CherryLink\Indexing\IndexHelpers;
use CherryLink\Plugin;

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
    /** Above this indexable-post count, a fresh install prompts a manual scan. */
    private const INITIAL_LIMIT = 100;

    /** @var License */
    private $license;

    public function __construct(License $license)
    {
        $this->license = $license;
    }

    public function register(): void
    {
        \add_action('init', [$this, 'loadTextdomain'], 1);
        \register_activation_hook(Plugin::instance()->file(), [$this, 'activate']);
        \add_action('upgrader_process_complete', [$this, 'onUpdate'], 10, 2);
        \add_action('plugins_loaded', [$this, 'maybeFinishUpdate']);
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

        $meta = \get_option('linkate_posts_meta', []);
        if (empty($meta['first_version'])) {
            $meta['first_version'] = PluginMeta::version();
            $meta['first_install'] = \current_time('timestamp');
            \update_option('linkate_posts_meta', $meta);

            if ((new IndexHelpers())->get_indexable_posts_count() > self::INITIAL_LIMIT) {
                \set_transient('cherry-manual-indexation-needed', true, 20);
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
            \set_transient('cherrylink_updated', 0);
        }
    }
}
