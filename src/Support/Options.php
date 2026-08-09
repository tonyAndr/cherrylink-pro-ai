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
 *   - self::MAIN  ('linkate-posts')      the user-facing settings array
 *   - self::META  ('linkate_posts_meta') license-validation state
 *
 * plus two standalone flags:
 *
 *   - self::INDEX_DIRTY ('cherrylink_index_dirty') "changed while unlicensed" marker
 *   - self::DEBUG_INFO  ('cherry_debug_info')      last wpdb query/error snapshot
 *
 * The class is stateless (every method reads/writes WP options live), so it is safe
 * to construct on demand or share a single instance. Legacy global functions now
 * delegate here; the scattered per-file get_option()/update_option() reads migrate
 * into their owning services in later stages.
 */
final class Options
{
    /** User-facing settings array. */
    public const MAIN = 'linkate-posts';

    /** License-validation state (key_valid / expires_at / key_error_reason). */
    public const META = 'linkate_posts_meta';

    /** Timestamp marker: a post changed while the license was inactive. */
    public const INDEX_DIRTY = 'cherrylink_index_dirty';

    /** Last wpdb query/error snapshot, for the debug panel. */
    public const DEBUG_INFO = 'cherry_debug_info';

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
     * null/[] "reset to defaults" branch, the show_static→show_pages migration,
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
            $options['show_pages'] = 'false';
        }
        // show_static is now show_pages
        if (isset($options['show_static'])) {
            $options['show_pages'] = $options['show_static'];
            unset($options['show_static']);
        }
        if (! isset($options['none_text'])) {
            $options['none_text'] = \__('Ничего не найдено...', 'cherrylink');
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
        if (! isset($options['link_before'])) {
            $options['link_before'] = \base64_encode(\urlencode('<a href="{url}" title="{title}">'));
        }
        if (! isset($options['link_after'])) {
            $options['link_after'] = \base64_encode(\urlencode('</a>'));
        }
        if (! isset($options['link_temp_alt'])) {
            $options['link_temp_alt'] = \base64_encode(\urlencode("<div style=\"padding:10px;margin:10px;border-top:1px solid lightgrey;border-bottom:1px solid lightgrey;\"><span style=\"color:lightgrey;font-size:smaller;\">Читайте также</span><div style=\"position:relative;max-width: 660px;margin: 0 auto;padding: 0 20px 20px 20px;display:flex;flex-wrap: wrap;\"><div style=\"width: 35%; min-width: 180px; height: auto; box-sizing: border-box;padding-right: 5%;\"><img src=\"{imagesrc}\" style=\"width:100%;\"></div><div style=\"width: 60%; min-width: 180px; height: auto; box-sizing: border-box;\"><strong>{title}</strong><br>{anons}</div><a target=\"_blank\" href=\"{url}\"><span style=\"position:absolute;width:100%;height:100%;top:0;left: 0;z-index: 1;\">&nbsp;</span></a></div></div>")); // phpcs:ignore Generic.Files.LineLength
        }
        if (! isset($options['term_temp_alt'])) {
            $options['term_temp_alt'] = \base64_encode(\urlencode("<div style=\"padding:10px;margin:10px;border-top:1px solid lightgrey;border-bottom:1px solid lightgrey;\">Больше интересной информации по данной теме вы найдете в разделе нашего сайта \"<a href=\"{url}\"><strong>{title}</strong></a>\".</div>")); // phpcs:ignore Generic.Files.LineLength
        }
        if (! isset($options['term_before'])) {
            $options['term_before'] = \base64_encode(\urlencode('<a href="{url}" title="{title}">'));
        }
        if (! isset($options['term_after'])) {
            $options['term_after'] = \base64_encode(\urlencode('</a>'));
        }
        $options['no_selection_action'] = 'placeholder'; // always use placeholder, not configurable
        if (! isset($options['hash_field'])) {
            $options['hash_field'] = '';
        }
        $options['multilink'] = 'checked'; // always allow multiple links, not configurable
        if (! isset($options['anons_len'])) {
            $options['anons_len'] = 200;
        }
        if (! isset($options['linkate_scheme_exists'])) {
            $options['linkate_scheme_exists'] = false; // since 1.4.0
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
        if (! isset($options['use_alt_server'])) {
            $options['use_alt_server'] = 'false'; // alternative connection route
        }

        \update_option(self::MAIN, $options);

        return $options;
    }
}
