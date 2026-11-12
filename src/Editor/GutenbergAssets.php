<?php

declare(strict_types=1);

namespace CherryLink\Editor;

use CherryLink\Ajax\Security;
use CherryLink\Contracts\Registerable;
use CherryLink\Support\Options;

/**
 * Enqueues the Gutenberg sidebar bundle (built from gutenberg-src/) on the block
 * editor. Ported from the legacy cherrylink_gutenberg.php +
 * register_cherrylink_gutenberg_scripts() (kept as a shim).
 *
 * Only wires the enqueue hook in the admin for a licensed site — mirroring the old
 * behavior where the hook was added from cherrylink_editor_ui.php behind its
 * admin + valid-license gate.
 */
final class GutenbergAssets implements Registerable
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

        \add_action('enqueue_block_editor_assets', [$this, 'enqueue']);
    }

    public function enqueue(): void
    {
        $asset_file = include CHERRYLINK_DIR . 'js/cherry-gutenberg.asset.php';

        \wp_register_script(
            'cherrylink-gutenberg',
            CHERRYLINK_DIR_URL . 'js/cherry-gutenberg.js',
            // The bundle reads wp.richText / wp.editor / wp.editPost as globals; the build's
            // dependency extraction can't see them, so declare the handles explicitly.
            \array_values(\array_unique(\array_merge($asset_file['dependencies'], ['wp-rich-text', 'wp-editor', 'wp-edit-post', 'wp-block-editor']))),
            $asset_file['version']
        );
        $post = \get_post();
        \wp_add_inline_script(
            'cherrylink-gutenberg',
            PanelConfig::inlineScript(PanelConfig::build($this->options, 'gutenberg', $post ? (int) $post->ID : 0)),
            'before'
        );
        \wp_localize_script('cherrylink-gutenberg', 'ajax_var', [
            'url'   => 'admin-ajax.php',
            'nonce' => Security::nonce(),
        ]);
        \wp_set_script_translations('cherrylink-gutenberg', 'cherrylink', CHERRYLINK_DIR . 'languages');
        \wp_enqueue_script('cherrylink-gutenberg');

        \wp_enqueue_style(
            'cherrylink-gutenberg',
            CHERRYLINK_DIR_URL . 'js/cherry-gutenberg.css',
            ['wp-components'],
            $asset_file['version']
        );
    }
}
