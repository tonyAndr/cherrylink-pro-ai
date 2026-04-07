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
    global $wpdb, $table_prefix;
    $table_name = $table_prefix . 'linkate_posts';

    // Create index on install if possible
    $create_index = false;

    // Check if DB tables were created
    $created_posts = false;
    $created_scheme = false;
    $created_stop = false;

    $default_collation = "utf8mb4_unicode_520_ci";
    $default_charset = "utf8mb4";
    if (!$wpdb->has_cap('utf8mb4_520')) {
        $default_collation = "utf8mb4_unicode_ci";
        if (!$wpdb->has_cap('utf8mb4')) {
            $default_collation = "utf8_general_ci";
            $default_charset = "utf8";
        }
    }

    $errorlevel = error_reporting(0);
    $suppress = $wpdb->hide_errors();

    // main table, index
    $result = $wpdb->query("SHOW TABLES LIKE '" . $table_name . "'");
    if ($result) {
        $create_index = cherrylink_update_table_structure();
    } else {
        $create_index = true;
        $sql = "CREATE TABLE IF NOT EXISTS `$table_name` (

				`id` bigint( 20 ) unsigned NOT NULL AUTO_INCREMENT,
				`pID` bigint( 20 ) unsigned NOT NULL ,
				`content` longtext NOT NULL ,
				`title` text NOT NULL ,
				`custom_fields` text NOT NULL ,
				`is_term` tinyint DEFAULT false,
				`suggestions` text NOT NULL ,
                PRIMARY KEY `id` (`id`),
                INDEX `pID` (`pID`),
				FULLTEXT KEY `title` ( `title` ) ,
				FULLTEXT KEY `content` ( `content` ) ,
				FULLTEXT KEY `suggestions` ( `suggestions` ) ,
				FULLTEXT KEY `custom_fields` ( `custom_fields` ) 
				) ENGINE = InnoDB CHARSET=$default_charset COLLATE $default_collation;";
        $created_posts = $wpdb->query($sql);
        $wpdb->show_errors($suppress);
    }

    // scheme table, export, statistics
    $table_name = $table_prefix . 'linkate_scheme';
    $result = $wpdb->query("SHOW TABLES LIKE '" . $table_name . "'");
    if ($result) {
        $create_index = cherrylink_update_table_structure();
    } else {
        $create_index = true;
        $sql = "CREATE TABLE IF NOT EXISTS `$table_name` (
				`ID` bigint( 20 ) unsigned NOT NULL primary key AUTO_INCREMENT,
				`source_id` int unsigned NOT NULL ,
				`source_type` tinyint unsigned NOT NULL ,
				`target_id` int unsigned NOT NULL ,
				`target_type` tinyint unsigned NOT NULL ,
				`ankor_text` varchar(1000) NOT NULL ,
				`external_url` varchar(1000) NOT NULL 
				) ENGINE = InnoDB CHARSET=$default_charset COLLATE $default_collation;";
        $created_scheme = $wpdb->query($sql);
        $wpdb->show_errors($suppress);
    }

    // stopwords table
    $table_name = $table_prefix . 'linkate_stopwords';
    $result = $wpdb->query("SHOW TABLES LIKE '" . $table_name . "'");
    if ($result) {
        $create_index = cherrylink_update_table_structure();
    } else {
        $create_index = true;
        $sql = "CREATE TABLE IF NOT EXISTS `$table_name` (
				`ID` bigint( 20 ) unsigned NOT NULL primary key AUTO_INCREMENT,
				`stemm` varchar(15) NOT NULL ,
				`word` varchar(20) NOT NULL UNIQUE ,
				`is_white` tinyint unsigned NOT NULL default 0,
				`is_custom` tinyint unsigned NOT NULL default 0 
				) ENGINE = InnoDB CHARSET=$default_charset COLLATE $default_collation;";
        $created_stop = $wpdb->query($sql);
        $wpdb->show_errors($suppress);
    }

    error_reporting($errorlevel);

    // (Re)fill options if empty
    $options = (array) get_option('linkate-posts', []);
    fill_options($options);

    fill_stopwords();

    // Vector API indexing is triggered via the admin panel; no local index needed on activation.

    // tables creation result
    return $created_posts && $created_scheme && $created_stop;
}

// Adding new column 'is_tag' in plugin ver. >= 1.2.0 
function cherrylink_update_table_structure()
{
    global $wpdb, $table_prefix;
    $update_index = false;
    $table_name = $table_prefix . 'linkate_posts';

    if (!linkate_table_column_exists($table_name, 'is_term')) {
        $sql = "ALTER TABLE `$table_name` 
			ADD COLUMN `is_term` tinyint DEFAULT false
			AFTER `tags`;";
        $wpdb->query($sql);
        $update_index = true;
    }

    if (!linkate_table_column_exists($table_name, 'suggestions')) {
        $sql = "ALTER TABLE `$table_name` 
			ADD COLUMN `suggestions` text NOT NULL
			AFTER `is_term`;";
        $wpdb->query($sql);
        $update_index = true;
    }

    return $update_index;
}

add_action("wp_ajax_check_collation", "linkate_table_is_collation_utf8mb4");
function linkate_table_is_collation_utf8mb4()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'linkate_posts';
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT TABLE_SCHEMA , TABLE_NAME , COLUMN_NAME , COLLATION_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'content'",
        DB_NAME,
        $table_name
    ));
    if (!empty($results) && strpos($results[0]->COLLATION_NAME, 'utf8mb4') !== false) {
        wp_send_json(true);
    }
    wp_send_json(false);
}

add_action("wp_ajax_update_collation", "linkate_table_update_collation_utf8mb4");
function linkate_table_update_collation_utf8mb4()
{
    global $wpdb;
    $default_collation = "utf8mb4_unicode_520_ci";
    $default_charset = "utf8mb4";
    if (!$wpdb->has_cap('utf8mb4_520')) {
        $default_collation = "utf8mb4_unicode_ci";
        if (!$wpdb->has_cap('utf8mb4')) {
            $default_collation = "utf8_unicode_ci";
            $default_charset = "utf8";
        }
    }
    $table_posts = $wpdb->prefix . 'linkate_posts';
    $table_scheme = $wpdb->prefix . 'linkate_scheme';
    $table_stop = $wpdb->prefix . 'linkate_stopwords';
    $results = [];
    foreach ([$table_posts, $table_scheme, $table_stop] as $table) {
        $results[] = $wpdb->query(
            "ALTER TABLE `$table` ENGINE = INNODB DEFAULT CHARSET=$default_charset COLLATE $default_collation;"
        );
        if ($table === $table_posts) {
            $results[] = $wpdb->query(
                "ALTER TABLE `$table` CHANGE `content` `content` LONGTEXT CHARACTER SET $default_charset COLLATE $default_collation NOT NULL;"
            );
            $results[] = $wpdb->query(
                "ALTER TABLE `$table` CHANGE `title` `title` TEXT CHARACTER SET $default_charset COLLATE $default_collation NOT NULL;"
            );
            $results[] = $wpdb->query(
                "ALTER TABLE `$table` CHANGE `tags` `tags` TEXT CHARACTER SET $default_charset COLLATE $default_collation NOT NULL;"
            );
            $results[] = $wpdb->query(
                "ALTER TABLE `$table` CHANGE `suggestions` `suggestions` TEXT CHARACTER SET $default_charset COLLATE $default_collation NOT NULL;"
            );
        }
        if ($table === $table_scheme) {
            $results[] = $wpdb->query(
                "ALTER TABLE `$table` CHANGE `ankor_text` `ankor_text` VARCHAR(1000) CHARACTER SET $default_charset COLLATE $default_collation NOT NULL;"
            );
        }
        if ($table === $table_stop) {
            $results[] = $wpdb->query(
                "ALTER TABLE `$table` CHANGE `stemm` `stemm` VARCHAR(15) CHARACTER SET $default_charset COLLATE $default_collation NOT NULL;"
            );
            $results[] = $wpdb->query(
                "ALTER TABLE `$table` CHANGE `word` `word` VARCHAR(20) CHARACTER SET $default_charset COLLATE $default_collation NOT NULL UNIQUE;"
            );
        }
    }

    if ($results[0] && $results[1] && $results[2])
        wp_send_json(["result" => true, "error" => $wpdb->last_error]);
    else {
        wp_send_json(["result" => false, "error" => $wpdb->last_error]);
    }
}

function linkate_table_column_exists($table_name, $column_name)
{
    global $wpdb;
    $column = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s ",
        DB_NAME,
        $table_name,
        $column_name
    ));
    if (!empty($column)) {
        return true;
    }
    return false;
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

    // Remove stopwords from options, read from files directly - v 1.4.9
    $options['base_stopwords'] = "";
    $options['base_tinywords'] = "";

    if (!isset($options['append_on'])) $options['append_on'] = 'false';
    if (!isset($options['append_priority'])) $options['append_priority'] = '10';
    if (!isset($options['append_condition'])) $options['append_condition'] = 'is_single()';
    if (!isset($options['limit'])) $options['limit'] = 1000;
    if (!isset($options['limit_ajax'])) $options['limit_ajax'] = 50; // since 1.4.0
    if (!isset($options['skip'])) $options['skip'] = 0;
    if (!isset($options['age'])) {
        $options['age']['direction'] = 'none';
        $options['age']['length'] = '0';
        $options['age']['duration'] = 'month';
    }
    if (!isset($options['divider'])) $options['divider'] = '';
    $options['omit_current_post'] = 'true';
    if (!isset($options['show_private'])) $options['show_private'] = 'false';
    if (!isset($options['show_pages'])) $options['show_pages'] = 'false';
    // show_static is now show_pages
    if (isset($options['show_static'])) {
        $options['show_pages'] = $options['show_static'];
        unset($options['show_static']);
    };
    if (!isset($options['none_text'])) $options['none_text'] = __('Ничего не найдено...', CHERRYLINK_TEXT_DOMAIN);
    if (!isset($options['no_text'])) $options['no_text'] = 'false';
    if (!isset($options['tag_str'])) $options['tag_str'] = '';
    if (!isset($options['excluded_cats'])) $options['excluded_cats'] = '';
    if ($options['excluded_cats'] === '9999') $options['excluded_cats'] = '';
    if (!isset($options['included_cats'])) $options['included_cats'] = '';
    if ($options['included_cats'] === '9999') $options['included_cats'] = '';
    if (!isset($options['excluded_authors'])) $options['excluded_authors'] = '';
    if ($options['excluded_authors'] === '9999') $options['excluded_authors'] = '';
    if (!isset($options['included_authors'])) $options['included_authors'] = '';
    if ($options['included_authors'] === '9999') $options['included_authors'] = '';
    if (!isset($options['included_posts'])) $options['included_posts'] = '';
    if (!isset($options['excluded_posts'])) $options['excluded_posts'] = '';
    if ($options['excluded_posts'] === '9999') $options['excluded_posts'] = '';
    if (!isset($options['show_customs'])) $options['show_customs'] = ''; // custom post types v1.2.10
    if ($options['show_customs'] === '9999') $options['show_customs'] = '';
    if (!isset($options['stripcodes'])) $options['stripcodes'] = array(array());
    $options['prefix'] = '<div class="linkate-box-container"><ol id="linkate-links-list">';
    $options['suffix'] = '</ol></div>';
    if (!isset($options['output_template'])) $options['output_template'] = 'h1';
    if (!isset($options['match_cat'])) $options['match_cat'] = 'false';
    if (!isset($options['match_tags'])) $options['match_tags'] = 'false';
    if (!isset($options['match_author'])) $options['match_author'] = 'false';
    if (!isset($options['content_filter'])) $options['content_filter'] = 'false';
    if (!isset($options['custom'])) {
        $options['custom']['key'] = '';
        $options['custom']['op'] = '=';
        $options['custom']['value'] = '';
    }
    if (!isset($options['sort'])) {
        $options['sort']['by1'] = '';
        $options['sort']['order1'] = SORT_ASC;
        $options['sort']['case1'] = 'false';
        $options['sort']['by2'] = '';
        $options['sort']['order2'] = SORT_ASC;
        $options['sort']['case2'] = 'false';
    }
    if (!isset($options['status'])) {
        $options['status']['publish'] = 'true';
        $options['status']['private'] = 'false';
        $options['status']['draft'] = 'false';
        $options['status']['future'] = 'false';
    }
    if (!isset($options['group_template'])) $options['group_template'] = '';
    if (!isset($options['weight_content'])) $options['weight_content'] = 0.33;
    if (!isset($options['weight_title'])) $options['weight_title'] = 0.33;
    if (!isset($options['weight_custom'])) $options['weight_custom'] = 0.33;
    if (!isset($options['num_terms'])) $options['num_terms'] = 150;
    if (!isset($options['clean_suggestions_stoplist'])) $options['clean_suggestions_stoplist'] = 'true';
    $options['term_extraction'] = 'frequency'; // since 1.4 we hide TextRank option 
    if (!isset($options['utf8'])) $options['utf8'] = 'true';
    if (!function_exists('mb_internal_encoding')) $options['utf8'] = 'false';
    if (!isset($options['use_stemming'])) $options['use_stemming'] = 'false';
    if (!isset($options['batch'])) $options['batch'] = '100';
    if (!isset($options['match_all_against_title'])) $options['match_all_against_title'] = 'false';
    if (!isset($options['link_before'])) $options['link_before'] = base64_encode(urlencode('<a href="{url}" title="{title}">'));
    if (!isset($options['link_after'])) $options['link_after'] = base64_encode(urlencode('</a>'));
    if (!isset($options['link_temp_alt'])) $options['link_temp_alt'] = base64_encode(urlencode("<div style=\"padding:10px;margin:10px;border-top:1px solid lightgrey;border-bottom:1px solid lightgrey;\"><span style=\"color:lightgrey;font-size:smaller;\">Читайте также</span><div style=\"position:relative;max-width: 660px;margin: 0 auto;padding: 0 20px 20px 20px;display:flex;flex-wrap: wrap;\"><div style=\"width: 35%; min-width: 180px; height: auto; box-sizing: border-box;padding-right: 5%;\"><img src=\"{imagesrc}\" style=\"width:100%;\"></div><div style=\"width: 60%; min-width: 180px; height: auto; box-sizing: border-box;\"><strong>{title}</strong><br>{anons}</div><a target=\"_blank\" href=\"{url}\"><span style=\"position:absolute;width:100%;height:100%;top:0;left: 0;z-index: 1;\">&nbsp;</span></a></div></div>"));
    if (!isset($options['term_temp_alt'])) $options['term_temp_alt'] = base64_encode(urlencode("<div style=\"padding:10px;margin:10px;border-top:1px solid lightgrey;border-bottom:1px solid lightgrey;\">Больше интересной информации по данной теме вы найдете в разделе нашего сайта \"<a href=\"{url}\"><strong>{title}</strong></a>\".</div>"));
    if (!isset($options['term_before'])) $options['term_before'] = base64_encode(urlencode('<a href="{url}" title="{title}">'));
    if (!isset($options['term_after'])) $options['term_after'] = base64_encode(urlencode('</a>'));
    if (!isset($options['no_selection_action'])) $options['no_selection_action'] = 'placeholder';
    if (!isset($options['hash_field'])) $options['hash_field'] = '';
    if (!isset($options['custom_stopwords'])) $options['custom_stopwords'] = '';
    if (!isset($options['term_length_limit'])) $options['term_length_limit'] = 3;
    if (!isset($options['multilink'])) $options['multilink'] = 'checked';
    if (!isset($options['compare_seotitle'])) $options['compare_seotitle'] = '';
    if (!isset($options['hash_last_check'])) $options['hash_last_check'] = 1523569887;
    if (!isset($options['hash_last_status'])) $options['hash_last_status'] = false;
    if (!isset($options['anons_len'])) $options['anons_len'] = 200;
    if (!isset($options['suggestions_click'])) $options['suggestions_click'] = 'select';
    if (!isset($options['suggestions_join'])) $options['suggestions_join'] = 'all';
    if (!isset($options['suggestions_donors_src'])) $options['suggestions_donors_src'] = 'title,content,custom_fields';
    if (!isset($options['suggestions_donors_join'])) $options['suggestions_donors_join'] = 'join';
    if (!isset($options['suggestions_switch_action'])) $options['suggestions_switch_action'] = 'false';
    if (!isset($options['ignore_relevance'])) $options['ignore_relevance'] = 'false'; // since 1.4.0
    if (!isset($options['linkate_scheme_exists'])) $options['linkate_scheme_exists'] = false; // since 1.4.0
    if (!isset($options['linkate_scheme_time'])) $options['linkate_scheme_time'] = 0; // since 1.4.0
    if (!isset($options['relative_links'])) $options['relative_links'] = "full"; // since 1.4.9
    if (!isset($options['quickfilter_dblclick'])) $options['quickfilter_dblclick'] = "false"; // since 1.5.0
    if (!isset($options['singleword_suggestions'])) $options['singleword_suggestions'] = "false"; // since 1.6.0
    if (!isset($options['debug_enabled'])) $options['debug_enabled'] = "false"; // since 2.0.6
    if (!isset($options['use_stemming'])) $options['use_stemming'] = "false"; // since 2.1.11
    if (!isset($options['seo_meta_source'])) $options['seo_meta_source'] = "none"; // since 2.3.0
    if (!isset($options['consider_max_incoming_links'])) $options['consider_max_incoming_links'] = 'false';
    if (!isset($options['max_incoming_links'])) $options['max_incoming_links'] = 20;
    if (!isset($options['template_image_size'])) $options['template_image_size'] = '';
    if (!isset($options['show_cat_filter'])) $options['show_cat_filter'] = 'false';
    if (!isset($options['index_custom_fields'])) $options['index_custom_fields'] = '';
    update_option('linkate-posts', $options);
    return $options;
}

add_action("wp_ajax_fill_stopwords", "fill_stopwords");
function fill_stopwords()
{
    global $wpdb, $table_prefix;
    $table_name = $table_prefix . "linkate_stopwords";

    $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    if ($count == 0 || isset($_POST["restore_ajax"])) {

        $stemmer = new Stem\LinguaStemRu();

        // it's empty, fill the table
        $linkate_overusedwords = file(CHERRYLINK_DIR_URL . '/stopwords.txt', FILE_IGNORE_NEW_LINES);
        if (is_array($linkate_overusedwords)) {
            $query = "INSERT INTO $table_name (stemm, word, is_white, is_custom) VALUES ";
            foreach ($linkate_overusedwords as $word) {
                $values = $wpdb->prepare("(%s,%s,0,0)", $stemmer->stem_word($word), mb_strtolower(trim($word)));
                if ($values) {
                    $wpdb->query($query . $values);
                }
            }
        }

        // Add custom stopwords from old versions to the db
        $options = get_option('linkate-posts', []);
        $custom_stopwords = isset($options["custom_stopwords"]) ? explode("\n", str_replace("\r", "", $options['custom_stopwords'])) : array();
        if (is_array($custom_stopwords) && !empty($custom_stopwords)) {
            $query = "INSERT INTO $table_name (stemm, word, is_white, is_custom) VALUES ";
            foreach ($custom_stopwords as $word) {
                $values = $wpdb->prepare("(%s,%s,0,1)", $stemmer->stem_word($word), mb_strtolower(trim($word)));
                if ($values) {
                    $wpdb->query($query . $values);
                }
            }
        }
        $options["custom_stopwords"] = "";
        update_option("linkate-posts", $options);
    }
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

// function getNeededOption()
// {
//     $options = get_option('linkate-posts', []);
//     $s = isset($options['hash_field']) ? $options['hash_field'] : '';
//     if (empty($s)) {
//         return NULL;
//     } else {
//         return explode(",", base64_decode($s));
//     }
// }

// function linkate_callDelay()
// {
//     $options = get_option('linkate-posts', []);
//     if (!isset($options['hash_last_check']) || time() > $options['hash_last_check']) {
//         return false;
//     }
//     return true;
// }
// function linkate_lastStatus()
// {
//     $options = get_option('linkate-posts', []);
//     return isset($options['hash_last_status']) ? $options['hash_last_status'] : false;
// }

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
