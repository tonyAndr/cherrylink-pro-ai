<?php

declare(strict_types=1);

namespace CherryLink\Editor;

use CherryLink\Contracts\Registerable;
use CherryLink\Support\Options;

/**
 * Enqueues the Gutenberg sidebar bundle (built from gutenberg-src/) on the block
 * editor.
 *
 * Wires the enqueue hook in the admin whether or not the site is connected: the local
 * panel features (categories, link insertion) never depend on the service.
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
            $asset_file['version'],
            false
        );
        $post = \get_post();
        \wp_add_inline_script(
            'cherrylink-gutenberg',
            PanelConfig::inlineScript(PanelConfig::build($this->options, 'gutenberg', $post ? (int) $post->ID : 0)),
            'before'
        );
        \wp_set_script_translations('cherrylink-gutenberg', 'cherrylink-ai', CHERRYLINK_DIR . 'languages');
        \wp_enqueue_script('cherrylink-gutenberg');

        \wp_enqueue_style(
            'cherrylink-gutenberg',
            CHERRYLINK_DIR_URL . 'js/cherry-gutenberg.css',
            ['wp-components'],
            $asset_file['version']
        );
    }
}
