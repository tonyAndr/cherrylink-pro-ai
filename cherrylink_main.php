<?php
/*
Plugin Name: CherryLink Pro AI
Plugin URI: http://seocherry.ru/
Description: Плагин для автоматической внутренней перелинковки на основе семантического поиска (AI). Находит релевантные статьи и предлагает анкорные фразы с помощью векторных эмбеддингов.
Version: 1.2.0
Author: Anton SeoCherry.ru
Author URI: http://seocherry.ru/
Requires at least: 5.6
Requires PHP: 7.4
Text Domain: cherrylink
Domain Path: /languages
*/

defined('ABSPATH') || exit;

/**
 * Legacy public template tag: return the suggestions payload for the current context.
 * Kept for back-compat with any theme that calls linkate_posts() directly; the pipeline
 * lives in \CherryLink\Editor\SuggestionsResults.
 *
 * @param string $args query-string style arguments (see SuggestionsResults::execute)
 * @return array<string,mixed>
 */
function linkate_posts($args = '')
{
    return \CherryLink\Editor\SuggestionsResults::execute((string) $args);
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

require(CHERRYLINK_DIR . '/env-info/W18T.class.php');

// Action Scheduler — background job queue for the incremental sync (must load early).
if (file_exists(CHERRYLINK_DIR . '/vendor/woocommerce/action-scheduler/action-scheduler.php')) {
    require_once(CHERRYLINK_DIR . '/vendor/woocommerce/action-scheduler/action-scheduler.php');
}

// Automatic updates from the public GitHub repo (Plugin Update Checker v4.9).
// Ships new versions via GitHub Releases — publish a release whose tag matches the
// "Version:" header above (e.g. v1.0.3) and attach the packaged plugin zip as a
// release asset. Clients then see the update in wp-admin like any WordPress.org plugin.
if (file_exists(CHERRYLINK_DIR . '/updater/plugin-update-checker.php')) {
    require_once(CHERRYLINK_DIR . '/updater/plugin-update-checker.php');
    $cl_update_checker = Puc_v4_Factory::buildUpdateChecker(
        'https://github.com/tonyAndr/cherrylink-pro-ai/',
        __FILE__,
        'cherrylink-pro-ai'
    );
    // Prefer the attached release-asset zip over GitHub's auto-generated source archive
    // (keeps dev files like gutenberg-src/ and node_modules out of client installs).
    $cl_update_checker->getVcsApi()->enableReleaseAssets();
}
