<?php

declare(strict_types=1);

namespace CherryLink\Support;

use CherryLink\Plugin;

/**
 * Plugin metadata helpers: the header version, cache-busting asset versions, and the
 * "are we on the CherryLink settings screen" check. Ported from the static bits of the
 * legacy LinkatePosts god-class (get_linkate_version / asset_ver /
 * linkate_is_plugin_admin_page).
 */
final class PluginMeta
{
    /** @var string|null */
    private static $version = null;

    /** Plugin header Version (read once per request). */
    public static function version(): string
    {
        if (self::$version === null) {
            $data          = \get_file_data(Plugin::instance()->file(), ['version' => 'Version'], 'plugin');
            self::$version = (string) ($data['version'] ?? '');
        }

        return self::$version;
    }

    /**
     * Cache-busting version for an enqueued asset, keyed on its file mtime so every edit
     * invalidates the browser cache. Falls back to the plugin version if the file can't
     * be stat'd.
     *
     * @param string $rel path relative to the plugin dir, e.g. 'js/cherry-front.js'
     */
    public static function assetVer(string $rel): string
    {
        $mtime = @\filemtime(Plugin::instance()->dir() . $rel);

        return $mtime ? (string) $mtime : self::version();
    }

    /** True on the CherryLink settings admin screen. */
    public static function isSettingsPage(): bool
    {
        $screen = \get_current_screen();

        return $screen !== null && $screen->id === 'settings_page_cherrylink-pro';
    }
}
