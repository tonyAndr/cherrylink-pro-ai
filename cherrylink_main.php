<?php

/*
Plugin Name: CherryLink – AI Internal Linking
Plugin URI: https://cherrylink.app/
Description: AI internal linking for WordPress: find related posts, get anchor phrase suggestions in the editor, and audit your link structure.
Version: 2.0.0
Author: CherryLink
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires at least: 6.9
Requires PHP: 7.4
Text Domain: cherrylink-ai
Domain Path: /languages
*/

defined('ABSPATH') || exit;

// Two plugin lines share the CherryLink\ namespace and options: never load both.
// The legacy "CherryLink Pro AI" loads first (alphabetical) and defines CHERRYLINK_DIR.
if (defined('CHERRYLINK_DIR')) {
    add_action('admin_notices', static function () {
        echo '<div class="notice notice-error"><p><strong>CherryLink</strong>: '
            . esc_html__('Deactivate CherryLink Pro AI first. Two CherryLink plugins cannot run together.', 'cherrylink-ai')
            . '</p></div>';
    });
    return;
}

// ========================================================================================= //
// ============================== Defines and Bootstrap ============================== //
// ========================================================================================= //

define('CHERRYLINK_DIR', plugin_dir_path(__FILE__));
define('CHERRYLINK_DIR_URL', plugin_dir_url(__FILE__));

// Composer PSR-4 autoloader for the CherryLink\ namespace (src/) + bundled deps.
// autoload_files.php is empty, so this only registers the classloader (no side
// effects); Action Scheduler is still loaded explicitly below with its own
// double-load guards.
require_once(CHERRYLINK_DIR . 'vendor/autoload.php');

// Bootstrap the plugin container: it wires and registers every namespaced service
// (admin, editor, indexing, API, lifecycle, ...). The remaining root files below only
// provide template-helper globals, class aliases, and the API runtime constants/hook.
\CherryLink\Plugin::boot(__FILE__);

// Action Scheduler — background job queue for the incremental sync (must load early).
if (file_exists(CHERRYLINK_DIR . '/vendor/woocommerce/action-scheduler/action-scheduler.php')) {
    require_once(CHERRYLINK_DIR . '/vendor/woocommerce/action-scheduler/action-scheduler.php');
}
