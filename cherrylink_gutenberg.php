<?php
/*
 * CherryLink — legacy shim.
 * The Gutenberg sidebar enqueue moved to \CherryLink\Editor\GutenbergAssets
 * (src/Editor/GutenbergAssets.php), registered from the Plugin bootstrap.
 *
 * register_cherrylink_gutenberg_scripts() stays as a thin back-compat shim in case
 * anything external calls it; it is no longer hooked here (the service owns that).
 */

defined('ABSPATH') || exit;

define('LINKATE_GUTENBERG_ASSETS', true);

function register_cherrylink_gutenberg_scripts()
{
    (new \CherryLink\Editor\GutenbergAssets())->enqueue();
}
