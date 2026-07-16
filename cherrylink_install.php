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
function fill_options($options)
{
    if ($options == NULL) {
        // saving license on options reset
        $options = (array) get_option('linkate-posts', []);
        $hash_field = $options['hash_field'];
        $options = array();
        $options['hash_field'] = $hash_field;
    }

    if (!isset($options['append_on'])) $options['append_on'] = 'false';
    if (!isset($options['append_priority'])) $options['append_priority'] = '10';
    if (!isset($options['append_condition'])) $options['append_condition'] = 'is_single()';
    if (!isset($options['limit_ajax'])) $options['limit_ajax'] = 50; // since 1.4.0
    if (!isset($options['divider'])) $options['divider'] = '';
    if (!isset($options['show_private'])) $options['show_private'] = 'false';
    if (!isset($options['show_pages'])) $options['show_pages'] = 'false';
    // show_static is now show_pages
    if (isset($options['show_static'])) {
        $options['show_pages'] = $options['show_static'];
        unset($options['show_static']);
    };
    if (!isset($options['none_text'])) $options['none_text'] = __('Ничего не найдено...', CHERRYLINK_TEXT_DOMAIN);
    if (!isset($options['excluded_cats'])) $options['excluded_cats'] = '';
    if ($options['excluded_cats'] === '9999') $options['excluded_cats'] = '';
    if (!isset($options['excluded_authors'])) $options['excluded_authors'] = '';
    if ($options['excluded_authors'] === '9999') $options['excluded_authors'] = '';
    if (!isset($options['show_customs'])) $options['show_customs'] = ''; // custom post types v1.2.10
    if ($options['show_customs'] === '9999') $options['show_customs'] = '';
    if (!isset($options['match_cat'])) $options['match_cat'] = 'false';
    if (!isset($options['content_filter'])) $options['content_filter'] = 'false';
    if (!isset($options['status'])) {
        $options['status']['publish'] = 'true';
        $options['status']['private'] = 'false';
        $options['status']['draft'] = 'false';
        $options['status']['future'] = 'false';
    }
    if (!isset($options['group_template'])) $options['group_template'] = '';
    if (!isset($options['link_before'])) $options['link_before'] = base64_encode(urlencode('<a href="{url}" title="{title}">'));
    if (!isset($options['link_after'])) $options['link_after'] = base64_encode(urlencode('</a>'));
    if (!isset($options['link_temp_alt'])) $options['link_temp_alt'] = base64_encode(urlencode("<div style=\"padding:10px;margin:10px;border-top:1px solid lightgrey;border-bottom:1px solid lightgrey;\"><span style=\"color:lightgrey;font-size:smaller;\">Читайте также</span><div style=\"position:relative;max-width: 660px;margin: 0 auto;padding: 0 20px 20px 20px;display:flex;flex-wrap: wrap;\"><div style=\"width: 35%; min-width: 180px; height: auto; box-sizing: border-box;padding-right: 5%;\"><img src=\"{imagesrc}\" style=\"width:100%;\"></div><div style=\"width: 60%; min-width: 180px; height: auto; box-sizing: border-box;\"><strong>{title}</strong><br>{anons}</div><a target=\"_blank\" href=\"{url}\"><span style=\"position:absolute;width:100%;height:100%;top:0;left: 0;z-index: 1;\">&nbsp;</span></a></div></div>"));
    if (!isset($options['term_temp_alt'])) $options['term_temp_alt'] = base64_encode(urlencode("<div style=\"padding:10px;margin:10px;border-top:1px solid lightgrey;border-bottom:1px solid lightgrey;\">Больше интересной информации по данной теме вы найдете в разделе нашего сайта \"<a href=\"{url}\"><strong>{title}</strong></a>\".</div>"));
    if (!isset($options['term_before'])) $options['term_before'] = base64_encode(urlencode('<a href="{url}" title="{title}">'));
    if (!isset($options['term_after'])) $options['term_after'] = base64_encode(urlencode('</a>'));
    $options['no_selection_action'] = 'placeholder'; // always use placeholder, not configurable
    if (!isset($options['hash_field'])) $options['hash_field'] = '';
    $options['multilink'] = 'checked'; // always allow multiple links, not configurable
    if (!isset($options['hash_last_check'])) $options['hash_last_check'] = 1523569887;
    if (!isset($options['hash_last_status'])) $options['hash_last_status'] = false;
    if (!isset($options['anons_len'])) $options['anons_len'] = 200;
    if (!isset($options['suggestions_click'])) $options['suggestions_click'] = 'select';
    if (!isset($options['suggestions_join'])) $options['suggestions_join'] = 'all';
    if (!isset($options['linkate_scheme_exists'])) $options['linkate_scheme_exists'] = false; // since 1.4.0
    if (!isset($options['linkate_scheme_time'])) $options['linkate_scheme_time'] = 0; // since 1.4.0
    if (!isset($options['relative_links'])) $options['relative_links'] = "full"; // since 1.4.9
if (!isset($options['debug_enabled'])) $options['debug_enabled'] = "false"; // since 2.0.6
    if (!isset($options['consider_max_incoming_links'])) $options['consider_max_incoming_links'] = 'false';
    if (!isset($options['max_incoming_links'])) $options['max_incoming_links'] = 20;
    if (!isset($options['template_image_size'])) $options['template_image_size'] = '';
    if (!isset($options['show_cat_filter'])) $options['show_cat_filter'] = 'false';
    if (!isset($options['vector_api_url'])) $options['vector_api_url'] = '';
    if (!isset($options['vector_api_key'])) $options['vector_api_key'] = '';
    if (!isset($options['vector_custom_fields'])) $options['vector_custom_fields'] = '';
    update_option('linkate-posts', $options);
    return $options;
}


if (!function_exists('link_cf_plugin_basename')) {
    if (!defined('WP_PLUGIN_DIR')) define('WP_PLUGIN_DIR', ABSPATH . 'wp-content/plugins');
    function link_cf_plugin_basename($file)
    {
        $file = str_replace('\\', '/', $file); // sanitize for Win32 installs
        $file = preg_replace('|/+|', '/', $file); // remove any duplicate slash
        $plugin_dir = str_replace('\\', '/', WP_PLUGIN_DIR); // sanitize for Win32 installs
        $plugin_dir = preg_replace('|/+|', '/', $plugin_dir); // remove any duplicate slash
        $file = preg_replace('|^' . preg_quote($plugin_dir, '|') . '/|', '', $file); // get relative path from plugins dir
        return $file;
    }
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

/** Flag the index as out-of-sync (a change happened while unlicensed). Records when it began. */
function cl_mark_index_dirty(): void
{
    if (!get_option('cherrylink_index_dirty')) {
        update_option('cherrylink_index_dirty', gmdate('Y-m-d H:i:s'), false);
    }
}

function cl_clear_index_dirty(): void
{
    delete_option('cherrylink_index_dirty');
}

function cl_index_is_dirty(): bool
{
    return (bool) get_option('cherrylink_index_dirty');
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
    $settings = admin_url('options-general.php?page=cherrylink-pro&subpage=main');
    echo '<div class="notice notice-warning"><p><strong>CherryLink Pro</strong>: ';
    echo 'лицензия неактивна — индексация и сканирование приостановлены, ссылки не обновляются.';
    if (cl_index_is_dirty()) {
        $since = esc_html(get_date_from_gmt(get_option('cherrylink_index_dirty')));
        echo ' <strong>Индекс перелинковки не синхронизирован</strong> (изменения с ' . $since
            . ' не проиндексированы). Активируйте ключ и запустите сканирование, чтобы восстановить синхронизацию.';
    } else {
        echo ' <a href="' . esc_url($settings) . '">Введите ключ лицензии</a>.';
    }
    echo '</p></div>';
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
