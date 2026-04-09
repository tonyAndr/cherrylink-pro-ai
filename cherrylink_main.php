<?php
/*
Plugin Name: CherryLink Pro AI
Plugin URI: http://seocherry.ru/
Description: Плагин для автоматической внутренней перелинковки на основе семантического поиска (AI). Находит релевантные статьи и предлагает анкорные фразы с помощью векторных эмбеддингов.
Version: 0.1.0
Author: Anton SeoCherry.ru
Author URI: http://seocherry.ru/
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

require(CHERRYLINK_DIR . '/env-info/W18T.class.php');

if (!defined('LINKATE_DEBUG')) require(CHERRYLINK_DIR . '/cherrylink_debug.php');

if (!defined('LINKATE_INSTALL_LIBRARY')) require(CHERRYLINK_DIR . '/cherrylink_install.php');
if (!defined('LINKATE_EF_LIBRARY')) require(CHERRYLINK_DIR . '/cherrylink_editor_functions.php');

if (!defined('LINKATE_CF_LIBRARY')) require(CHERRYLINK_DIR . '/cherrylink_cf.php');
if (!defined('LINKATE_ACF_LIBRARY')) require(CHERRYLINK_DIR . '/cherrylink_acf.php');
if (!defined('LP_OT_LIBRARY')) require(CHERRYLINK_DIR . '/cherrylink_output_tags.php');
if (!defined('LP_ADMIN_SUBPAGES_LIBRARY')) require(CHERRYLINK_DIR . '/cherrylink_admin_subpages.php');
if (!defined('LINKATE_TERMS_LIBRARY')) require(CHERRYLINK_DIR . '/cherrylink_terms.php');
if (!defined('CHERRY_VECTOR_API_CLIENT')) { define('CHERRY_VECTOR_API_CLIENT', true); require(CHERRYLINK_DIR . '/cherrylink_vector_api.php'); }
if (!defined('LINKATE_INDEX_LIBRARY')) require(CHERRYLINK_DIR . '/cherrylink_index.php');
if (!defined('LINKATE_STATISTICS_LIBRARY')) require(CHERRYLINK_DIR . '/cherrylink_statistics.php');

if (!defined('LINKATE_STATS_COLUMN_LIBRARY')) require(CHERRYLINK_DIR . '/cherrylink_stats_column.php');
// if (!defined('LINKATE_CRB_LIBRARY')) require(CHERRYLINK_DIR . '/crb_main.php');

if (!defined('LINKATE_GUTENBERG_ASSETS')) require(CHERRYLINK_DIR . '/cherrylink_gutenberg.php');
if (!defined('LINKATE_TAILWIND_CONSTANTS')) require(CHERRYLINK_DIR . '/cherrylink_tailwind_constants.php');
if (!defined('LINKATE_INDEX_HELPERS')) require(CHERRYLINK_DIR . "/cherrylink_index_helpers.php");
if (!defined('LINKATE_STATS_SCHEME')) require(CHERRYLINK_DIR . "/cherrylink_stats_scheme.php");

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

    static function execute($args = '', $option_key = 'linkate-posts')
    {
        global $wpdb;

        $arg_options       = link_cf_parse_args($args);
        $is_term           = isset($arg_options['is_term'])    ? (int)$arg_options['is_term']    : 0;
        $offset            = isset($arg_options['offset'])     ? (int)$arg_options['offset']     : 0;
        $post_id           = isset($arg_options['manual_ID'])  ? (int)$arg_options['manual_ID']  : -1;
        $mode              = !empty($arg_options['mode'])      ? $arg_options['mode']             : 'related_block';
        $custom_text       = isset($arg_options['custom_text']) ? trim($arg_options['custom_text']) : '';

        $options = link_cf_set_options($option_key, $arg_options);
        $post_id = link_cf_current_post_id($post_id);
        $limit   = (int)$options['limit_ajax'];

        if (!$post_id || $limit < 1) {
            return LinkatePosts::_empty_result($mode, $options, $option_key);
        }

        // ── Build query text for the vector API ───────────────────────────────
        if (!empty($custom_text)) {
            $query_text  = $custom_text;
            $source_html = '';
        } else {
            $current_post = get_post($post_id);
            if (!$current_post) {
                return LinkatePosts::_empty_result($mode, $options, $option_key);
            }
            $query_text  = $current_post->post_title . "\n" . wp_strip_all_tags($current_post->post_content);
            $source_html = $current_post->post_content;

            // If the content has no HTML tags (e.g. WP-CLI-seeded posts, classic editor
            // plain text), wrap paragraphs in <p> so the API anchor extractor can find blocks.
            if (!empty($source_html) && strip_tags($source_html) === $source_html) {
                $paras = array_filter(array_map('trim', preg_split('/\n{2,}/', $source_html)));
                $source_html = '<p>' . implode('</p><p>', $paras) . '</p>';
            }
        }

        // ── Vector API search ─────────────────────────────────────────────────
        // Request extra results to have headroom after WP-level filtering.
        $api         = new CherryVectorAPI();
        $api_results = $api->search($query_text, $source_html, $limit * 3);

        if (empty($api_results)) {
            return LinkatePosts::_empty_result($mode, $options, $option_key);
        }

        // Build a map: post_id → API row (for suggestions & score), preserving score order
        $api_map     = [];
        $ordered_ids = [];
        foreach ($api_results as $r) {
            $pid = (int)$r['post_id'];
            if ($pid === $post_id) continue;
            $api_map[$pid]  = $r;
            $ordered_ids[]  = $pid;
        }

        if (empty($ordered_ids)) {
            return LinkatePosts::_empty_result($mode, $options, $option_key);
        }

        // ── Filter through WordPress (status, type, cats, age …) ─────────────
        $ids_in          = implode(',', array_map('intval', $ordered_ids));
        $check_custom    = (trim($options['custom']['key']) !== '');
        $match_category  = ($options['match_cat'] === 'true');
        $match_tags      = ($options['match_tags'] !== 'false');
        $match_author    = ($options['match_author'] === 'true');
        $exclude_cats    = ($options['excluded_cats'] !== '');
        $include_cats    = ($options['included_cats'] !== '');
        $exclude_authors = ($options['excluded_authors'] !== '');
        $include_authors = ($options['included_authors'] !== '');
        $exclude_posts   = (trim($options['excluded_posts']) !== '');
        $include_posts   = (trim($options['included_posts']) !== '');
        $use_tag_str     = ('' != trim($options['tag_str']));
        $hide_pass       = ($options['show_private'] === 'false');
        $check_age       = ('none' !== $options['age']['direction']);
        $consider_max    = (isset($options['consider_max_incoming_links']) && $options['consider_max_incoming_links'] !== 'false');

        $sql = "SELECT ID, post_content, post_title, post_author, post_excerpt, post_date, post_type FROM {$wpdb->posts} ";
        if ($check_custom) $sql .= "LEFT JOIN {$wpdb->postmeta} wpp ON post_id = ID ";

        $where   = ["ID IN ($ids_in)"];
        $where[] = link_cf_where_show_status($options['status']);
        if ($is_term == 0) {
            if ($match_category) $where[] = link_cf_where_match_category($post_id);
            if ($match_tags)     $where[] = link_cf_where_match_tags($options['match_tags']);
            if ($match_author)   $where[] = link_cf_where_match_author();
            if ($check_custom)   $where[] = link_cf_where_check_custom($options['custom']['key'], $options['custom']['op'], $options['custom']['value']);
        }
        $where[] = link_cf_where_show_pages($options['show_pages'], $options['show_customs']);
        if ($include_cats)    $where[] = link_cf_where_included_cats($options['included_cats']);
        if ($exclude_cats)    $where[] = link_cf_where_excluded_cats($options['excluded_cats']);
        if ($exclude_authors) $where[] = link_cf_where_excluded_authors($options['excluded_authors']);
        if ($include_authors) $where[] = link_cf_where_included_authors($options['included_authors']);
        if ($exclude_posts)   $where[] = link_cf_where_excluded_posts(trim($options['excluded_posts']));
        if ($include_posts)   $where[] = link_cf_where_included_posts(trim($options['included_posts']));
        if ($use_tag_str)     $where[] = link_cf_where_tag_str($options['tag_str']);
        if ($hide_pass)       $where[] = link_cf_where_hide_pass();
        if ($check_age)       $where[] = link_cf_where_check_age($options['age']['direction'], $options['age']['length'], $options['age']['duration']);

        $sql .= "WHERE " . implode(' AND ', $where);
        if ($check_custom) $sql .= " GROUP BY {$wpdb->posts}.ID";
        // Preserve vector similarity ranking
        $sql .= " ORDER BY FIELD(ID, $ids_in)";
        $sql .= " LIMIT $offset, $limit";

        $results = $wpdb->get_results($sql);

        // Filter by max incoming links
        if ($consider_max && $mode !== 'related_block' && $results) {
            $max = (int)$options['max_incoming_links'];
            $results = array_values(array_filter($results, function ($row) use ($max) {
                return (int)get_post_meta($row->ID, 'cherry_income', true) < $max;
            }));
        }

        // ── Attach API suggestions + score to each result ─────────────────────
        if ($results) {
            foreach ($results as $result) {
                $api_row = $api_map[(int)$result->ID] ?? [];
                // Store raw JSON; each renderer applies its own escaping:
                // Gutenberg needs \" (JSON-inside-JSON), Classic needs &quot; (HTML attr)
                $result->suggestions = !empty($api_row['suggestions'])
                    ? wp_json_encode($api_row['suggestions'], JSON_UNESCAPED_UNICODE)
                    : '';
                $result->score = isset($api_row['score'])
                    ? (int)round($api_row['score'] * 100)
                    : 0;
            }
        }

        switch ($mode) {
            case 'gutenberg':
                return LinkatePosts::prepare_for_cherry_gutenberg($results, $option_key, $options);
            case 'classic':
            default:
                return LinkatePosts::prepare_for_cherrylink_panel($results, $option_key, $options);
        }
    }

    private static function _empty_result(string $mode, array $options, string $option_key): array
    {
        if ($mode === 'gutenberg') {
            return ['links' => [], 'count' => -1];
        }
        $translations = link_cf_prepare_template($options['none_text']);
        $output = '<p>' . link_cf_expand_template([], $options['none_text'], $translations, $option_key) . '</p>';
        return ['links' => trim($output), 'count' => 0];
    }

    static function prepare_for_cherry_gutenberg($results, $option_key, $options)
    {
        $output_template = '"data-url":"{url}","data-titleseo":"{title_seo}","data-title":"{title}","data-category":"{categorynames}","data-date":"{date}","data-author":"{author}","data-postid":"{postid}","data-imagesrc":"{imagesrc}","data-anons":"{anons}","data-suggestions":"{suggestions}"';

        $results_count = 0;
        if ($results) {
            $out_final    = $output_template;
            $translations = link_cf_prepare_template($out_final);

            foreach ($results as $result) {
                // JSON-inside-JSON: escape quotes so the value is valid inside "data-suggestions":"{suggestions}"
                $result->suggestions = str_replace('"', '\\"', $result->suggestions);
                $items[] = "{" . link_cf_expand_template($result, $out_final, $translations, $option_key) . "}";
            }

            if ($options['sort']['by1'] !== '') $items = link_cf_sort_items($options['sort'], $results, $option_key, $options['group_template'], $items);
            $output = "[" . implode(",", str_replace("\n", "", $items)) . "]";

            $results_count = count($results);
            $send_data['links'] = $output;
            $send_data['count'] = $results_count;
        } else {
            $send_data['links'] = [];
            $send_data['count'] = -1;
        }
        return $send_data;
    }

    static function prepare_for_cherrylink_panel($results, $option_key, $options)
    {
        $output_template_item_prefix = '
		<div class="linkate-item-container">
			<div class="linkate-controls">
				<div class="link-counter" title="Найдено в тексте / переход к ссылке">0</div>
				<div class="link-preview" title="Редактировать статью в новой вкладке"></div>
				
			</div>
			<div class="linkate-link" title="Нажмите для вставки в текст" data-url="{url}" data-titleseo="{title_seo}" data-title="{title}" data-category="{categorynames}" data-date="{date}" data-author="{author}" data-postid="{postid}" data-imagesrc="{imagesrc}" data-anons="{anons}" data-suggestions="{suggestions}"><span class="link-title" >';
        // <div class="link-add-to-block" title="Добавить в блок релевантных ссылок"></div><div class="link-del-from-block btn-hidden" title="Убрать из блока ссылок"></div>

        $output_template_item_suffix = '</span></div>
			<div class="link-right-controls"><div class="link-individual-stats-income" title="Сколько раз сослались на эту статью">?</div><div class="link-individual-stats-out" title="Сколько исходящих ссылок содержит статья">?</div><div class="link-suggestions" title="Подсказка"></div></div></div>';

        $results_count = 0;
        if ($results) {
            $output_template = (!isset($options['output_template']) || $options['output_template'] === 'h1' || $options['output_template'] === '{title}') ? '{title}' : '{title_seo}';
            $out_final = $output_template_item_prefix . $output_template . $output_template_item_suffix;
            $translations = link_cf_prepare_template($out_final);
            foreach ($results as $result) {
                // HTML attribute context: encode quotes so they don't break data-suggestions="..."
                $result->suggestions = htmlspecialchars($result->suggestions, ENT_COMPAT, 'UTF-8');
                $items[] = link_cf_expand_template($result, $out_final, $translations, $option_key);
            }
            if ($options['sort']['by1'] !== '') $items = link_cf_sort_items($options['sort'], $results, $option_key, $options['group_template'], $items);
            $output = implode(($options['divider']) ? $options['divider'] : "\n", $items);

            $results_count = sizeof($results);
        } else {
            // we display the blank message, with tags expanded if necessary
            $translations = link_cf_prepare_template($options['none_text']);
            $output = "<p>" . link_cf_expand_template(array(), $options['none_text'], $translations, $option_key) . "</p>";
        }

        $send_data['links'] = trim($output);
        $send_data['count'] = $results_count;
        return $send_data;
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
add_action('activate_' . str_replace('-admin', '', link_cf_plugin_basename(__FILE__)), 'linkate_posts_install');
// call on update
add_action('upgrader_process_complete', 'linkate_on_update', 10, 2);
// call after update
add_action('plugins_loaded', 'linkate_redirectToUpdatePlugin');

// call this when plugin updates
function linkate_on_update($upgrader_object, $options)
{
    $current_plugin_path_name = str_replace('-admin', '', link_cf_plugin_basename(__FILE__));

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


    /* Check transient, if available display notice */
    if (get_transient('cherry-manual-indexation-needed')) {
?>
        <div class="notice notice-warning is-dismissable">
            <p><strong>CherryLink Pro</strong> установлен!</p>
            <p>Для начала работы с ним <strong>необходимо просканировать ваш сайт</strong>.</p>
            <p>Перейдите в настройки плагина на вкладку <a href="<?php echo site_url() . '/wp-admin/options-general.php?page=cherrylink-pro&subpage=scan'; ?>">Сканирование</a>, и нажмите на кнопку "<strong>Начать сканирование</strong>".</p>
        </div>
    <?php
        /* Delete transient, only display this notice once. */
        delete_transient('cherry-manual-indexation-needed');
    } else if ($index_process_status === 'VALUE_NOT_EXIST' || $index_process_status === 'IN_PROGRESS') {
    ?>
        <div class="notice notice-warning is-dismissable">
            <p><strong>CherryLink Pro</strong>: сканирование не завершено!</p>
            <p>Плагин может работать некорректно или не видеть все записи, <strong>необходимо завершить сканирование</strong>.</p>
            <p>Перейдите в настройки плагина на вкладку <a href="<?php echo site_url() . '/wp-admin/options-general.php?page=cherrylink-pro&subpage=scan'; ?>">Сканирование</a>, и нажмите на кнопку "<strong>Начать сканирование</strong>".</p>
        </div>
<?php
    }
}

function linkate_posts_wp_admin_style()
{
    if (LinkatePosts::linkate_is_plugin_admin_page('settings')) {
        wp_register_style('cherrylink-css-admin', plugins_url('', __FILE__) . '/css/cherry-admin.css', false, LinkatePosts::$version);
        wp_register_style('cherrylink-css-admin-table', plugins_url('', __FILE__) . '/css/tabulator.css', false, LinkatePosts::$version);
        wp_enqueue_style('cherrylink-css-admin');
        wp_enqueue_style('cherrylink-css-admin-table');

        wp_register_script('cherrylink-js-tailwind', plugins_url('/js/tailwind.js', __FILE__), array(), LinkatePosts::get_linkate_version());
        wp_register_script('cherrylink-js-admin', plugins_url('/js/cherry-admin.js', __FILE__), array('jquery'), LinkatePosts::get_linkate_version());
        wp_register_script('cherrylink-js-admin-csv', plugins_url('/js/cherry-admin-csv.js', __FILE__), array('jquery'), LinkatePosts::get_linkate_version());

        wp_register_script('cherrylink-js-admin-table', plugins_url('/js/tabulator.min.js', __FILE__), array('jquery'), LinkatePosts::get_linkate_version());
        // wp_register_script( 'cherrylink-js-admin-table-wrapper', plugins_url( '/js/jquery_wrapper.js', __FILE__ ), array( 'jquery', 'cherrylink-js-admin-table' ), LinkatePosts::get_linkate_version() );

        wp_register_script('cherrylink-js-admin-index', plugins_url('/js/cherry-admin-index.js', __FILE__), array('jquery'), LinkatePosts::get_linkate_version());

        $options = (array) get_option('linkate-posts', []);
        $scheme_exists = array("state" => isset($options['linkate_scheme_exists']) ? true : false);
        wp_localize_script('cherrylink-js-admin', 'scheme', $scheme_exists);

        wp_enqueue_script('cherrylink-js-tailwind');
        wp_enqueue_script('cherrylink-js-admin');
        wp_enqueue_script('cherrylink-js-admin-table');
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


function cherry_write_log($data)
{
    if (true === WP_DEBUG) {
        if (is_array($data) || is_object($data)) {
            error_log(print_r($data, true));
        } else {
            error_log($data);
        }
    }
}
