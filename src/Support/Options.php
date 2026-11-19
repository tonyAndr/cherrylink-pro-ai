<?php

declare(strict_types=1);

namespace CherryLink\Support;

/**
 * Single source of truth for the plugin's persisted settings.
 *
 * Owns the option-key constants, the default/fill logic (ported verbatim from the
 * legacy fill_options()), and a small typed read/write API over the two option
 * arrays the plugin persists:
 *
 *   - self::MAIN  ('cherrylink_options')      the user-facing settings array
 *   - self::META  ('cherrylink_state') license-validation state
 *
 * plus two standalone flags:
 *
 *   - self::INDEX_DIRTY ('cherrylink_index_dirty') "changed while unlicensed" marker
 *
 * The class is stateless (every method reads/writes WP options live), so it is safe
 * to construct on demand or share a single instance. Legacy global functions now
 * delegate here; the scattered per-file get_option()/update_option() reads migrate
 * into their owning services in later stages.
 */
final class Options
{
    /** User-facing settings array. */
    public const MAIN = 'cherrylink_options';

    /** License-validation state (key_valid / expires_at / key_error_reason). */
    public const META = 'cherrylink_state';

    /** Timestamp marker: a post changed while the license was inactive. */
    public const INDEX_DIRTY = 'cherrylink_index_dirty';

    // ── main settings array ────────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function main(): array
    {
        return (array) \get_option(self::MAIN, []);
    }

    /** @param array<string,mixed> $options */
    public function saveMain(array $options): bool
    {
        return (bool) \update_option(self::MAIN, $options);
    }

    /**
     * Read a single main setting with a fallback.
     *
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $options = $this->main();

        return \array_key_exists($key, $options) ? $options[$key] : $default;
    }

    /** @param mixed $value */
    public function set(string $key, $value): bool
    {
        $options       = $this->main();
        $options[$key] = $value;

        return $this->saveMain($options);
    }

    // ── license / meta state ───────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function meta(): array
    {
        return (array) \get_option(self::META, []);
    }

    /** @param array<string,mixed> $meta */
    public function saveMeta(array $meta): bool
    {
        return (bool) \update_option(self::META, $meta);
    }

    // ── index-dirty flag ───────────────────────────────────────────────────

    /**
     * Flag the index as out-of-sync (a change happened while unlicensed), recording
     * when it began. First write wins — the timestamp is not overwritten on repeats.
     */
    public function markIndexDirty(): void
    {
        if (! \get_option(self::INDEX_DIRTY)) {
            \update_option(self::INDEX_DIRTY, \gmdate('Y-m-d H:i:s'), false);
        }
    }

    public function clearIndexDirty(): void
    {
        \delete_option(self::INDEX_DIRTY);
    }

    public function indexIsDirty(): bool
    {
        return (bool) \get_option(self::INDEX_DIRTY);
    }

    /** Raw GMT timestamp the index first went dirty (empty if clean). */
    public function indexDirtySince(): string
    {
        return (string) \get_option(self::INDEX_DIRTY);
    }

    // ── defaults ───────────────────────────────────────────────────────────

    /**
     * Fill in defaults for any missing setting and persist the result. Ported
     * verbatim from the legacy fill_options() — behavior (including the loose
     * null/[] "reset to defaults" branch
     * and the always-forced no_selection_action / multilink values) is preserved
     * exactly and pinned by OptionDefaultsTest.
     *
     * @param array<string,mixed>|null $options
     * @return array<string,mixed>
     */
    public static function fill($options): array
    {
        if ($options == null) { // phpcs:ignore -- [] == null is true; preserves legacy reset branch
            // saving license on options reset
            $options               = (array) \get_option(self::MAIN, []);
            $hash_field            = $options['hash_field'] ?? ''; // fresh install has no saved license yet
            $options               = [];
            $options['hash_field'] = $hash_field;
        }

        $options = \CherryLink\Editor\LinkMarkup::migrate($options); // old link templates -> link_title_attr/new_tab/class

        if (! isset($options['limit_ajax'])) {
            $options['limit_ajax'] = 50; // since 1.4.0
        }
        if (! isset($options['divider'])) {
            $options['divider'] = '';
        }
        if (! isset($options['show_private'])) {
            $options['show_private'] = 'false';
        }
        if (! isset($options['show_pages'])) {
            $options['show_pages'] = 'true'; // pages are analyzed by default (2.x)
        }
        if (! isset($options['none_text'])) {
            $options['none_text'] = \__('Nothing found...', 'cherrylink-ai');
        }
        if (! isset($options['excluded_cats'])) {
            $options['excluded_cats'] = '';
        }
        if ($options['excluded_cats'] === '9999') {
            $options['excluded_cats'] = '';
        }
        if (! isset($options['excluded_authors'])) {
            $options['excluded_authors'] = '';
        }
        if ($options['excluded_authors'] === '9999') {
            $options['excluded_authors'] = '';
        }
        if (! isset($options['show_customs'])) {
            $options['show_customs'] = ''; // custom post types v1.2.10
        }
        if ($options['show_customs'] === '9999') {
            $options['show_customs'] = '';
        }
        if (! isset($options['match_cat'])) {
            $options['match_cat'] = 'false';
        }
        if (! isset($options['status'])) {
            $options['status']['publish'] = 'true';
            $options['status']['private'] = 'false';
            $options['status']['draft']   = 'false';
            $options['status']['future']  = 'false';
        }
        // Link markup (insert-time only): title attribute on, same tab, no class.
        if (! isset($options['link_title_attr'])) {
            $options['link_title_attr'] = 'true';
        }
        if (! isset($options['link_new_tab'])) {
            $options['link_new_tab'] = 'false';
        }
        if (! isset($options['link_class'])) {
            $options['link_class'] = '';
        }
        $options['no_selection_action'] = 'placeholder'; // always use placeholder, not configurable
        if (! isset($options['hash_field'])) {
            $options['hash_field'] = '';
        }
        $options['multilink'] = 'checked'; // always allow multiple links, not configurable
        if (! isset($options['anons_len'])) {
            $options['anons_len'] = 200;
        }
        if (! isset($options['relative_links'])) {
            $options['relative_links'] = 'full'; // since 1.4.9
        }
        if (! isset($options['debug_enabled'])) {
            $options['debug_enabled'] = 'false'; // since 2.0.6
        }
        if (! isset($options['consider_max_incoming_links'])) {
            $options['consider_max_incoming_links'] = 'false';
        }
        if (! isset($options['max_incoming_links'])) {
            $options['max_incoming_links'] = 20;
        }
        if (! isset($options['template_image_size'])) {
            $options['template_image_size'] = '';
        }
        if (! isset($options['show_cat_filter'])) {
            $options['show_cat_filter'] = 'false';
        }
        if (! isset($options['vector_api_url'])) {
            $options['vector_api_url'] = '';
        }
        if (! isset($options['vector_api_key'])) {
            $options['vector_api_key'] = '';
        }
        if (! isset($options['vector_custom_fields'])) {
            $options['vector_custom_fields'] = '';
        }

        \update_option(self::MAIN, $options);

        return $options;
    }
}
