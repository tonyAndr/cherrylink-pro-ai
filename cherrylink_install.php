<?php
/*
 * CherryLink Plugin
 */

// Disable direct access
defined('ABSPATH') || exit;
// Define lib name
define('LINKATE_INSTALL_LIBRARY', true);

// ========================================================================================= //
// ============================== CherryLink Install Settings ============================== //
// ========================================================================================= //

// this function gets called when the plugin is installed to set up the index and default options
function linkate_posts_install()
{
    $options = (array) get_option('linkate-posts', []);
    fill_options($options);
    return true;
}


// used on install, import settings, revert to defaults
// Defaults now live in \CherryLink\Support\Options::fill(); this stays as a thin
// back-compat shim for the legacy callers (install + "revert to defaults").
function fill_options($options)
{
    return \CherryLink\Support\Options::fill($options);
}


function linkate_handle_license_response()
{
    $options = get_option('linkate-posts', []);
    $options_meta = get_option('linkate_posts_meta', []);
    $domain = isset($_SERVER['HTTP_HOST']) ?  $_SERVER['HTTP_HOST'] : $_SERVER['SERVER_NAME'];

    $response = linkate_call_home_nocurl($options['hash_field'], $domain);
    if ($response !== false) {
        // check response
        if (intval($response->key_valid) === 1) {
            $options_meta['key_valid'] = true;
            $options_meta['expires_at'] = $response->expires_at;
            $options_meta['key_error_reason'] = '';
        } else {
            $options_meta['key_valid'] = false;
            $options_meta['expires_at'] = $response->expires_at;
            $options_meta['key_error_reason'] = $response->key_error_reason;
        }
        
        
    } else {
        // probably server/connection error
        // don't activate, if first time
        if (!isset($options_meta['key_valid']) || !$options_meta['key_valid']) {
            $options_meta['key_valid'] = false;
            $options_meta['key_error_reason'] = 'no_connection';
        } else {
            return true;
        }
    }
    update_option('linkate_posts_meta', $options_meta);

    cherry_write_log('key validation');
    cherry_write_log($options_meta['key_valid']);
    return $options_meta['key_valid'];
}

function linkate_checkNeededOption()
{
    $options = get_option('linkate_posts_meta', []);
    if (isset($options['key_valid']) && $options['key_valid']) {
        $now = time();
        $date_valid = strtotime($options['expires_at']) > $now;
        return $date_valid;
    } 
    return false;
}

// ── License gate + index-freshness tracking ──────────────────────────────────
// All API indexing (save hook, deletes, scans) is gated on a valid, non-expired
// license. While unlicensed, post changes are NOT pushed to the API, so the index
// drifts out of sync — we flag that and warn until a scan reconciles it.

function cl_is_licensed(): bool
{
    return (bool) linkate_checkNeededOption();
}

// The index-dirty flag semantics now live on \CherryLink\Support\Options; these
// stay as thin back-compat shims for the legacy callers (save/delete hooks, sync).

/** Flag the index as out-of-sync (a change happened while unlicensed). Records when it began. */
function cl_mark_index_dirty(): void
{
    (new \CherryLink\Support\Options())->markIndexDirty();
}

function cl_clear_index_dirty(): void
{
    (new \CherryLink\Support\Options())->clearIndexDirty();
}

function cl_index_is_dirty(): bool
{
    return (new \CherryLink\Support\Options())->indexIsDirty();
}

add_action('admin_notices', 'cl_license_index_notice');
function cl_license_index_notice(): void
{
    if (!current_user_can('edit_posts') || cl_is_licensed()) {
        return;
    }
    // Only on the CherryLink settings page — not every admin screen.
    if (!class_exists('LinkatePosts') || !LinkatePosts::linkate_is_plugin_admin_page('settings')) {
        return;
    }
    $dirty = cl_index_is_dirty();
    echo (new \CherryLink\Support\View())->render('notices/license-inactive', [
        'dirty'        => $dirty,
        'since'        => $dirty ? get_date_from_gmt((new \CherryLink\Support\Options())->indexDirtySince()) : '',
        'settings_url' => admin_url('options-general.php?page=cherrylink-pro&subpage=main'),
    ]);
}

function linkate_call_home_nocurl($val, $d)
{
    $data = array('license_key' => $val, 'action' => 'check', 'domain' => $d);
    $url = 'https://seocherry.ru/cherrylink-pro-licensing/';
    $response = wp_remote_post(
        $url,
        array(
            'method' => 'POST',
            'timeout' => 30,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => true,
            'headers' => array(),
            'body' => $data,
            'cookies' => array()
        )
    );

    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        return false;
    } elseif ($response['response']['code'] != 200) {
        // server down?
        return false;
    } else {
        return json_decode($response['body']);
    }
}

add_action( 'clpro_license_cron_hook', 'clpro_license_exec' );

if ( ! wp_next_scheduled( 'clpro_license_cron_hook' ) ) {
    wp_schedule_event( time(), 'daily', 'clpro_license_cron_hook' );
}

function clpro_license_exec () {
    
    linkate_handle_license_response();
}



// For some plugins to add access to cherrylink settings page
function cherrylink_add_cap()
{
    $role = get_role('administrator');
    if (is_object($role)) {
        $role->add_cap('cherrylink_settings');
    }
}

add_action('plugins_loaded', 'cherrylink_add_cap');
