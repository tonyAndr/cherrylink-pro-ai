<?php
/*
Plugin Name: CherryLink Pro AI
Plugin URI: http://seocherry.ru/
Description: Плагин для автоматической внутренней перелинковки на основе семантического поиска (AI). Находит релевантные статьи и предлагает анкорные фразы с помощью векторных эмбеддингов.
Version: 1.1.1
Author: Anton SeoCherry.ru
Author URI: http://seocherry.ru/
Requires at least: 5.6
Requires PHP: 7.4
Text Domain: cherrylink-td
*/

function linkate_posts($args = '')
{
    return LinkatePosts::execute($args);
}

function linkate_posts_mark_current()
{
    global $post, $linkate_posts_current_ID;
    $linkate_posts_current_ID = $post->ID;
}


// ========================================================================================= //
// ============================== Defines and Imports ============================== //
// ========================================================================================= //

define('CHERRYLINK_INITIAL_LIMIT', 100);
define('CHERRYLINK_TEXT_DOMAIN', 'cherrylink-td');
define('CHERRYLINK_DIR', plugin_dir_path(__FILE__));
define('CHERRYLINK_DIR_URL', plugin_dir_url(__FILE__));

// Composer PSR-4 autoloader for the CherryLink\ namespace (src/) + bundled deps.
// autoload_files.php is empty, so this only registers the classloader (no side
// effects); Action Scheduler is still loaded explicitly below with its own
// double-load guards.
require_once(CHERRYLINK_DIR . 'vendor/autoload.php');

// Bootstrap the plugin container. Legacy procedural modules still register their
// own hooks for now; services migrate into the container across the refactor stages.
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

if (!defined('LINKATE_DEBUG')) require(CHERRYLINK_DIR . '/cherrylink_debug.php');

if (!defined('LINKATE_INSTALL_LIBRARY')) require(CHERRYLINK_DIR . '/cherrylink_install.php');

if (!defined('LINKATE_CF_LIBRARY')) require(CHERRYLINK_DIR . '/cherrylink_cf.php');
if (!defined('LINKATE_ACF_LIBRARY')) require(CHERRYLINK_DIR . '/cherrylink_acf.php');
if (!defined('LP_OT_LIBRARY')) require(CHERRYLINK_DIR . '/cherrylink_output_tags.php');
if (!defined('LP_ADMIN_SUBPAGES_LIBRARY')) require(CHERRYLINK_DIR . '/cherrylink_admin_subpages.php');
if (!defined('LINKATE_TERMS_LIBRARY')) require(CHERRYLINK_DIR . '/cherrylink_terms.php');
if (!defined('CHERRY_VECTOR_API_CLIENT')) { define('CHERRY_VECTOR_API_CLIENT', true); require(CHERRYLINK_DIR . '/cherrylink_vector_api.php'); }
if (!defined('LINKATE_INDEX_LIBRARY')) require(CHERRYLINK_DIR . '/cherrylink_index.php');
if (!defined('LINKATE_SYNC_LIBRARY')) require(CHERRYLINK_DIR . '/cherrylink_sync.php');
if (!defined('LINKATE_STATISTICS_LIBRARY')) require(CHERRYLINK_DIR . '/cherrylink_statistics.php');

if (!defined('LINKATE_STATS_COLUMN_LIBRARY')) require(CHERRYLINK_DIR . '/cherrylink_stats_column.php');

if (!defined('LINKATE_GUTENBERG_ASSETS')) require(CHERRYLINK_DIR . '/cherrylink_gutenberg.php');
if (!defined('LINKATE_TAILWIND_CONSTANTS')) require(CHERRYLINK_DIR . '/cherrylink_tailwind_constants.php');
if (!defined('LINKATE_INDEX_HELPERS')) require(CHERRYLINK_DIR . "/cherrylink_index_helpers.php");

if (is_admin()) {
    require(CHERRYLINK_DIR . '/cherrylink_admin_options.php');
    require(CHERRYLINK_DIR . '/cherrylink_editor_ui.php');
}


// ========================================================================================= //
// ============================== Main Class ============================== //
// ========================================================================================= //

class LinkatePosts
{
    static $version = 0;

    static function get_linkate_version()
    {
        $plugin_data = get_file_data(__FILE__, array('version' => 'Version'), 'plugin');
        LinkatePosts::$version = $plugin_data['version'];

        return $plugin_data['version'];
    } // get_linkate_version

    /**
     * Cache-busting version for an enqueued asset, keyed on its file mtime so every edit
     * invalidates the browser cache. The plugin header Version is static (0.1.0), which meant
     * hand-written JS/CSS (e.g. cherry-front.js) stayed cached across updates. Falls back to
     * the plugin version if the file can't be stat'd.
     *
     * @param string $rel path relative to the plugin dir, e.g. 'js/cherry-front.js'
     */
    static function asset_ver($rel)
    {
        $mtime = @filemtime(CHERRYLINK_DIR . $rel);
        return $mtime ? (string) $mtime : self::get_linkate_version();
    } // asset_ver

    // check if plugin's admin page is shown
    static function linkate_is_plugin_admin_page($page = 'settings')
    {
        $current_screen = get_current_screen();

        if ($page == 'settings' && $current_screen->id == 'settings_page_cherrylink-pro') {
            return true;
        }

        return false;
    } // linkate_is_plugin_admin_page

    // add settings link to plugins page
    static function linkate_plugin_action_links($links)
    {
        $settings_link = '<a href="' . admin_url('options-general.php?page=cherrylink-pro') . '" title="Настройки CherryLink">Настройки</a>';

        array_unshift($links, $settings_link);

        return $links;
    } // linkate_plugin_action_links

    // ========================================================================================= //
    // ============================== Main function [Get Posts] ============================== //
    // ========================================================================================= //

    // The suggestions pipeline (query → filter → render) now lives in
    // \CherryLink\Editor\SuggestionsResults; this stays as a thin back-compat entry point.
    static function execute($args = '', $option_key = 'linkate-posts')
    {
        return \CherryLink\Editor\SuggestionsResults::execute((string) $args, $option_key);
    }

    // save some info
    static function lp_activate()
    {
        global $wpdb;
        $options = get_option('linkate_posts_meta', array());

        if (empty($options['first_version'])) {
            $options['first_version'] = LinkatePosts::get_linkate_version();
            $options['first_install'] = current_time('timestamp');
            update_option('linkate_posts_meta', $options);

            $index_helper = new CL_Index_Helpers();
            $amount_of_db_rows = $index_helper->get_indexable_posts_count();
            if ($amount_of_db_rows > CHERRYLINK_INITIAL_LIMIT)
                set_transient('cherry-manual-indexation-needed', true, 20);
        }
    } // lp_activate

} // linkateposts class


// call install func on activation
add_action('activate_' . str_replace('-admin', '', plugin_basename(__FILE__)), 'linkate_posts_install');
// call on update
add_action('upgrader_process_complete', 'linkate_on_update', 10, 2);
// call after update
add_action('plugins_loaded', 'linkate_redirectToUpdatePlugin');

// call this when plugin updates
function linkate_on_update($upgrader_object, $options)
{
    $current_plugin_path_name = str_replace('-admin', '', plugin_basename(__FILE__));

    if ($options['action'] == 'update' && $options['type'] == 'plugin') {
        foreach ($options['plugins'] as $each_plugin) {
            if ($each_plugin == $current_plugin_path_name) {
                // set to 1 - we need it to run update script after plugin was updated by WP
                set_transient('cherrylink_updated', 1);
                break;
            }
        }
    }
}
// call this after plugin updates
function linkate_redirectToUpdatePlugin()
{
    if (get_transient('cherrylink_updated') && current_user_can('update_plugins')) {
        linkate_posts_install();
        set_transient('cherrylink_updated', 0);
    } // endif;
} // redirectToUpdatePlugin


function cherrylink_activation_notice()
{
    $options_meta = get_option('linkate_posts_meta', []);
    $index_process_status = (isset($options_meta['indexing_process']) && !empty($options_meta['indexing_process'])) ? $options_meta['indexing_process'] : 'VALUE_NOT_EXIST';


    $scan_url = site_url() . '/wp-admin/options-general.php?page=cherrylink-pro&subpage=scan';
    $view     = new \CherryLink\Support\View();

    /* Check transient, if available display notice */
    if (get_transient('cherry-manual-indexation-needed')) {
        echo $view->render('notices/scan-prompt', [
            'line1'    => '<strong>CherryLink Pro</strong> установлен!',
            'line2'    => 'Для начала работы с ним <strong>необходимо просканировать ваш сайт</strong>.',
            'scan_url' => $scan_url,
        ]);
        /* Delete transient, only display this notice once. */
        delete_transient('cherry-manual-indexation-needed');
    } else if ($index_process_status === 'VALUE_NOT_EXIST' || $index_process_status === 'IN_PROGRESS') {
        echo $view->render('notices/scan-prompt', [
            'line1'    => '<strong>CherryLink Pro</strong>: сканирование не завершено!',
            'line2'    => 'Плагин может работать некорректно или не видеть все записи, <strong>необходимо завершить сканирование</strong>.',
            'scan_url' => $scan_url,
        ]);
    }
}

function linkate_posts_wp_admin_style()
{
    if (LinkatePosts::linkate_is_plugin_admin_page('settings')) {
        wp_register_style('cherrylink-css-admin', plugins_url('', __FILE__) . '/css/cherry-admin.css', false, LinkatePosts::asset_ver('css/cherry-admin.css'));
        wp_enqueue_style('cherrylink-css-admin');

        wp_register_script('cherrylink-js-tailwind', plugins_url('/js/tailwind.js', __FILE__), array(), LinkatePosts::asset_ver('js/tailwind.js'));
        wp_register_script('cherrylink-js-admin', plugins_url('/js/cherry-admin.js', __FILE__), array('jquery'), LinkatePosts::asset_ver('js/cherry-admin.js'));
        wp_register_script('cherrylink-js-admin-csv', plugins_url('/js/cherry-admin-csv.js', __FILE__), array('jquery'), LinkatePosts::asset_ver('js/cherry-admin-csv.js'));

        wp_register_script('cherrylink-js-admin-index', plugins_url('/js/cherry-admin-index.js', __FILE__), array('jquery'), LinkatePosts::asset_ver('js/cherry-admin-index.js'));

        $options = (array) get_option('linkate-posts', []);
        $scheme_exists = array("state" => isset($options['linkate_scheme_exists']) ? true : false);
        wp_localize_script('cherrylink-js-admin', 'scheme', $scheme_exists);

        // Shared AJAX nonce for the admin scripts (admin / index / csv). Localized to the
        // first-enqueued admin handle so the cherry_ajax global is defined before the others run.
        wp_localize_script('cherrylink-js-admin', 'cherry_ajax', array(
            'nonce' => \CherryLink\Ajax\Security::nonce(),
        ));

        wp_enqueue_script('cherrylink-js-tailwind');
        wp_enqueue_script('cherrylink-js-admin');
        wp_enqueue_script('cherrylink-js-admin-index');
        wp_enqueue_script('cherrylink-js-admin-csv');
    }
}

function linkate_posts_init()
{
    global $wpdb;
    load_plugin_textdomain(CHERRYLINK_TEXT_DOMAIN);

    LinkatePosts::get_linkate_version();

    add_action('admin_notices', 'cherrylink_activation_notice');

    // Hooks for vector API indexing are registered in cherrylink_index.php

    add_action('admin_enqueue_scripts', 'linkate_posts_wp_admin_style', 1);

    // additional links in plugin description
    add_filter(
        'plugin_action_links_' . basename(dirname(__FILE__)) . '/' . basename(__FILE__),
        array('LinkatePosts', 'linkate_plugin_action_links')
    );
} // init

add_action('init', 'linkate_posts_init', 1);
register_activation_hook(__FILE__, array('LinkatePosts', 'lp_activate'));


// Debug logging moved to \CherryLink\Support\Logger; this stays as a thin
// back-compat shim for the legacy callers.
function cherry_write_log($data)
{
    (new \CherryLink\Support\Logger())->log($data);
}
