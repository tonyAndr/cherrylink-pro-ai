<?php

declare(strict_types=1);

namespace CherryLink\Admin;

use CherryLink\Ajax\Security;
use CherryLink\Contracts\Registerable;
use CherryLink\Plugin;
use CherryLink\Support\Connect;
use CherryLink\Support\PluginMeta;
use CherryLink\Support\Urls;

/**
 * Top-level CherryLink menu and its four pages. Each page prints the React mount point;
 * the bootstrap (`window.cherrylinkAdmin`) and bundle are enqueued only on these screens.
 * Contract: specs/admin-ui.md.
 */
final class AdminMenu implements Registerable
{
    /** page slug => bootstrap `page` value */
    private const PAGES = [
        'cherrylink'          => 'dashboard',
        'cherrylink-links'    => 'links',
        'cherrylink-index'    => 'index',
        'cherrylink-settings' => 'settings',
    ];

    /** @var AdminApi */
    private $api;

    public function __construct(AdminApi $api)
    {
        $this->api = $api;
    }

    public function register(): void
    {
        \add_action('admin_menu', [$this, 'menu'], 1);
        \add_action('admin_init', [$this, 'redirectLegacy']);
        \add_action('admin_enqueue_scripts', [$this, 'enqueue'], 1);
        // Settings export is served through a nonce-checked admin handler, never as a
        // static file inside the plugin directory.
        \add_action('admin_post_cherrylink_export_options', [$this, 'exportOptions']);
        \add_filter(
            'plugin_action_links_' . \plugin_basename(Plugin::instance()->file()),
            [$this, 'actionLinks']
        );
    }

    /**
     * @param array<int,string> $links
     * @return array<int,string>
     */
    public function actionLinks($links): array
    {
        \array_unshift($links, '<a href="' . \esc_url(self::url('cherrylink-settings')) . '">'
            . \esc_html__('Settings', 'cherrylink-ai') . '</a>');

        return $links;
    }

    public static function url(string $slug): string
    {
        return \admin_url('admin.php?page=' . $slug);
    }

    public function menu(): void
    {
        $cap = 'cherrylink_settings';
        // The icon is a data URI so the menu needs no extra request; WordPress recolors it.
        $icon = 'data:image/svg+xml;base64,' . \base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><g fill="#a7aaad"><circle cx="6" cy="14" r="4"/><circle cx="14.500" cy="13" r="3.500"/><path d="M6 10c0-4 2-6 6-8l1 1c-3 1-4 3-4 7zM11 9c1-3 3-5 5-5l.5 1c-2 .5-3 2-3.500 4.500z"/></g></svg>'
        );
        \add_menu_page(\__('CherryLink', 'cherrylink-ai'), \__('CherryLink', 'cherrylink-ai'), $cap, 'cherrylink', [$this, 'render'], $icon, 58);
        \add_submenu_page('cherrylink', \__('Dashboard', 'cherrylink-ai'), \__('Dashboard', 'cherrylink-ai'), $cap, 'cherrylink', [$this, 'render']);
        \add_submenu_page('cherrylink', \__('Link health', 'cherrylink-ai'), \__('Link health', 'cherrylink-ai'), $cap, 'cherrylink-links', [$this, 'render']);
        \add_submenu_page('cherrylink', \__('Index', 'cherrylink-ai'), \__('Index', 'cherrylink-ai'), $cap, 'cherrylink-index', [$this, 'render']);
        \add_submenu_page('cherrylink', \__('Settings', 'cherrylink-ai'), \__('Settings', 'cherrylink-ai'), $cap, 'cherrylink-settings', [$this, 'render']);
    }

    /**
     * Target for the pre-2.x settings URL (options-general.php?page=cherrylink-pro), or null.
     * Connect outcome parameters survive so a wizard return still shows its message.
     *
     * @param array<string,mixed> $get
     */
    public static function legacyTarget(string $pagenow, array $get): ?string
    {
        if ($pagenow !== 'options-general.php' || ($get['page'] ?? '') !== 'cherrylink-pro') {
            return null;
        }
        $args = [];
        foreach (['cl_connect', 'cl_retry'] as $key) {
            if (isset($get[$key]) && \is_scalar($get[$key])) {
                $args[$key] = \sanitize_key((string) $get[$key]);
            }
        }

        return \add_query_arg($args, self::url('cherrylink'));
    }

    public function redirectLegacy(): void
    {
        global $pagenow;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect selector
        $target = self::legacyTarget((string) $pagenow, $_GET);
        if ($target !== null) {
            \wp_safe_redirect($target);
            exit;
        }
    }

    public function render(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen selector
        $slug = isset($_GET['page']) ? \sanitize_key(\wp_unslash($_GET['page'])) : 'cherrylink';
        echo '<div class="wrap"><hr class="wp-header-end"><div id="cherrylink-admin" data-page="' . \esc_attr(self::PAGES[$slug] ?? 'dashboard') . '"></div></div>';
    }

    public function enqueue(): void
    {
        if (! PluginMeta::isSettingsPage()) {
            return;
        }
        $dir = Plugin::instance()->dir();
        $url = Plugin::instance()->url();
        if (! \file_exists($dir . 'js/cherry-app.js')) {
            return; // bundle not built yet
        }
        $asset = \file_exists($dir . 'js/cherry-app.asset.php') ? (array) include $dir . 'js/cherry-app.asset.php' : [];
        $deps  = (array) ($asset['dependencies'] ?? ['wp-element', 'wp-components', 'wp-i18n']);
        $ver   = (string) ($asset['version'] ?? PluginMeta::assetVer('js/cherry-app.js'));

        \wp_register_script('cherrylink-admin', $url . 'js/cherry-app.js', $deps, $ver, true);
        \wp_set_script_translations('cherrylink-admin', 'cherrylink-ai', $dir . 'languages');
        \wp_add_inline_script('cherrylink-admin', 'window.cherrylinkAdmin = ' . \wp_json_encode($this->bootstrap()) . ';', 'before');
        \wp_enqueue_script('cherrylink-admin');
        if (\file_exists($dir . 'js/cherry-app.css')) {
            \wp_enqueue_style('cherrylink-admin', $url . 'js/cherry-app.css', ['wp-components'], $ver);
        }
    }

    /** @return array<string,mixed> */
    public function bootstrap(): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen selector
        $slug = isset($_GET['page']) ? \sanitize_key(\wp_unslash($_GET['page'])) : 'cherrylink';

        return [
            'page'    => self::PAGES[$slug] ?? 'dashboard',
            'ajaxUrl' => \admin_url('admin-ajax.php'),
            'nonce'   => Security::nonce(),
            'connect' => [
                'postUrl' => \admin_url('admin-post.php'),
                'action'  => Connect::ACTION,
                'nonce'   => \wp_create_nonce(Connect::ACTION),
                'error'   => $this->api->connectError(),
            ],
            'urls'    => [
                'dashboard'      => self::url('cherrylink'),
                'links'          => self::url('cherrylink-links'),
                'index'          => self::url('cherrylink-index'),
                'settings'       => self::url('cherrylink-settings'),
                'exportSettings' => \wp_nonce_url(\admin_url('admin-post.php?action=cherrylink_export_options'), 'cherrylink-export-options'),
                'editPost'       => \admin_url('post.php?action=edit&post='),
                'upgrade'        => Urls::UPGRADE,
                'pricing'        => Urls::PRICING,
                'terms'          => Urls::TERMS,
                'privacy'        => Urls::PRIVACY,
                'docs'           => Urls::DOCS,
                'limits'         => Urls::LIMITS,
            ],
            'state'   => $this->api->state(),
        ];
    }

    /**
     * Stream the settings file to the current administrator (never a static file in the
     * plugin directory: the export includes the license key).
     */
    public function exportOptions(): void
    {
        if (! \current_user_can('cherrylink_settings')) {
            \wp_die(\esc_html__('Insufficient permissions.', 'cherrylink-ai'), '', ['response' => 403]);
        }
        \check_admin_referer('cherrylink-export-options');

        $payload = \http_build_query((array) \get_option('linkate-posts', []));

        \nocache_headers();
        \header('Content-Type: text/plain; charset=utf-8');
        \header('Content-Disposition: attachment; filename="cherrylink_options.txt"');
        \header('Content-Length: ' . \strlen($payload));
        echo $payload; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw options download payload
        exit;
    }
}
