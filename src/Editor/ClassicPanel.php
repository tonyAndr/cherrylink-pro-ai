<?php

declare(strict_types=1);

namespace CherryLink\Editor;

use CherryLink\Contracts\Registerable;
use CherryLink\Support\Logger;
use CherryLink\Support\Options;
use CherryLink\Support\PluginMeta;

/**
 * The classic (TinyMCE) editor integration: the CherryLink media button (toggle for the
 * React panel) and the enqueue of the `js/cherry-classic.*` bundle on classic post/page
 * and term edit screens. buildSourceHtml() is called by the stats service and the panel API.
 *
 * register() reproduces the old file-scope gate: the panel only wires up in the
 * admin for a licensed site.
 */
final class ClassicPanel implements Registerable
{
    /** @var Options */
    private $options;

    public function __construct(?Options $options = null)
    {
        $this->options = $options ?? new Options();
    }

    public function register(): void
    {
        // Not gated on the key: unconnected sites get the panel with a single Connect button.
        if (! \is_admin()) {
            return;
        }

        \add_action('admin_enqueue_scripts', [$this, 'hookTermEdit'], 10);
    }

    // ── Screen wiring ────────────────────────────────────────────────────────

    public function hookTermEdit(string $hook_suffix): void
    {
        $is_term = 'term.php' === $hook_suffix;
        $is_post = 'post.php' === $hook_suffix || 'post-new.php' === $hook_suffix;
        if (! $is_term && ! ($is_post && ! $this->isGutenbergEnabled())) {
            return;
        }

        if (! self::hasUnifiedBundle()) {
            // Missing bundle (broken install): render nothing, log once per request.
            (new Logger())->log('CherryLink: js/cherry-classic.js is missing; the editor panel is not loaded.');
            return;
        }

        \add_action('media_buttons', [$this, 'mediaButton'], 15);
        $this->enqueueUnifiedPanel($is_term);
    }

    public static function hasUnifiedBundle(): bool
    {
        return \defined('CHERRYLINK_DIR') && \file_exists(CHERRYLINK_DIR . 'js/cherry-classic.js');
    }

    /** Classic/term host of the React panel (js/cherry-classic.*). */
    public function enqueueUnifiedPanel(bool $is_term): void
    {
        $asset_path = CHERRYLINK_DIR . 'js/cherry-classic.asset.php';
        $asset      = \file_exists($asset_path) ? include $asset_path : [];
        $deps       = ! empty($asset['dependencies']) ? $asset['dependencies'] : ['wp-element', 'wp-i18n'];
        $ver        = ! empty($asset['version']) ? $asset['version'] : PluginMeta::assetVer('js/cherry-classic.js');

        if ($is_term) {
            // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only screen identification
            $id       = isset($_GET['tag_ID']) ? (int) $_GET['tag_ID'] : 0;
            $taxonomy = isset($_GET['taxonomy']) ? \sanitize_key(\wp_unslash($_GET['taxonomy'])) : '';
            // phpcs:enable WordPress.Security.NonceVerification.Recommended
            $config = PanelConfig::build($this->options, 'classic', $id, true, $taxonomy);
        } else {
            $post   = \get_post();
            $config = PanelConfig::build($this->options, 'classic', $post ? (int) $post->ID : 0);
        }

        \wp_register_script('cherrylink-classic', CHERRYLINK_DIR_URL . 'js/cherry-classic.js', $deps, $ver, true);
        \wp_add_inline_script('cherrylink-classic', PanelConfig::inlineScript($config), 'before');
        \wp_set_script_translations('cherrylink-classic', 'cherrylink', CHERRYLINK_DIR . 'languages');
        \wp_enqueue_script('cherrylink-classic');
        \wp_enqueue_style('cherrylink-css-main', CHERRYLINK_DIR_URL . 'css/cherry-main.css', [], PluginMeta::assetVer('css/cherry-main.css'));
        if (\file_exists(CHERRYLINK_DIR . 'js/cherry-classic.css')) {
            \wp_enqueue_style('cherrylink-classic', CHERRYLINK_DIR_URL . 'js/cherry-classic.css', [], $ver);
        }
    }

    public function mediaButton(): void
    {
        // Stable toggle hook for the React host; the id is printed once even when
        // several editors (ACF etc.) fire media_buttons on one screen.
        static $printed = false;
        $id      = $printed ? '' : ' id="cherrylink-panel-toggle"';
        $printed = true;
        echo '<a' . $id . ' class="linkate-button cherrylink-panel-toggle button"><span class="wp-media-buttons-icon linkate-media-btn"></span>CherryLink Pro</a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup
    }

    public function isGutenbergEnabled(): bool
    {
        if (\function_exists('is_gutenberg_page') && \is_gutenberg_page()) {
            return true; // The Gutenberg plugin is on.
        }
        $current_screen = \get_current_screen();
        if ($current_screen && \method_exists($current_screen, 'is_block_editor') && $current_screen->is_block_editor()) {
            return true; // Gutenberg page on 5+.
        }
        return false;
    }

    // ── Helpers (buildSourceHtml: stats service + panel API) ─────────────────

    /**
     * Wrap plain-text content in <p> so the API's block-based NP extractor can find
     * blocks. Mirrors the prep the suggestions pipeline does before search.
     */
    public static function buildSourceHtml(string $content): string
    {
        if ($content === '') {
            return '';
        }
        if (\strip_tags($content) === $content) {
            $paras   = \array_filter(\array_map('trim', \preg_split('/\n{2,}/', $content)));
            $content = '<p>' . \implode('</p><p>', $paras) . '</p>';
        }
        return $content;
    }
}
