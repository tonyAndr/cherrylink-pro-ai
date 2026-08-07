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
        if (! \is_admin() || empty($this->options->meta()['key_valid'])) {
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
            $asset_file['dependencies'],
            $asset_file['version']
        );
        \wp_localize_script('cherrylink-gutenberg', 'ajax_var', [
            'url'   => 'admin-ajax.php',
            'nonce' => Security::nonce(),
        ]);
        \wp_enqueue_script('cherrylink-gutenberg');

        \wp_enqueue_style(
            'cherrylink-gutenberg',
            CHERRYLINK_DIR_URL . 'js/cherry-gutenberg.css',
            ['wp-components'],
            $asset_file['version']
        );
    }
}
