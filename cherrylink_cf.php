<?php
/*
 * CherryLink Plugin
 */

// Disable direct access
defined('ABSPATH') || exit;
// Define lib name
define('LINKATE_CF_LIBRARY', true);

function link_cf_parse_args($args)
{
    // 	$args is of the form 'key1=val1&key2=val2'
    //	The code copes with null values, e.g., 'key1=&key2=val2' 
    //	and arguments with embedded '=', e.g. 'output_template=<li class="stuff">{...}</li>'.
    $result = array();
    if ($args) {
        // the default separator is '&' but you may wish to include the character in a title, say,
        // so you can specify an alternative separator by making the first character of $args
        // '&' and the second character your new separator...
        if (substr($args, 0, 1) === '&') {
            $s = substr($args, 1, 1);
            $args = substr($args, 2);
        } else {
            $s = '&';
        }
        // separate the arguments into key=value pairs
        $arguments = explode($s, $args);
        foreach ($arguments as $arg) {
            if ($arg) {
                // find the position of the first '='
                $i = strpos($arg, '=');
                // if not a valid format ('key=value) we ignore it
                if ($i) {
                    $key = substr($arg, 0, $i);
                    $val = substr($arg, $i + 1);
                    $result[$key] = $val;
                }
            }
        }
    }
    return $result;
}

function link_cf_set_options($option_key, $arg)
{
    $options = get_option($option_key);
    // Fill in from stored options anything not passed as a request argument.
    // (execute() is the only caller; it passes only manual_ID/is_term/offset/mode/custom_text,
    //  so in practice every value below comes from the stored option.)
    if (!isset($arg['limit_ajax'])) $arg['limit_ajax'] = stripslashes(@$options['limit_ajax']);
    if (!isset($arg['divider'])) $arg['divider'] = stripslashes(@$options['divider']);
    if (!isset($arg['show_private'])) $arg['show_private'] = @$options['show_private'];
    if (!isset($arg['show_pages'])) $arg['show_pages'] = @$options['show_pages'];
    if (!isset($arg['none_text'])) $arg['none_text'] = stripslashes(@$options['none_text']);
    if (!isset($arg['excluded_cats'])) $arg['excluded_cats'] = stripslashes(@$options['excluded_cats']);
    if (!isset($arg['excluded_authors'])) $arg['excluded_authors'] = stripslashes(@$options['excluded_authors']);
    if (!isset($arg['show_customs'])) $arg['show_customs'] = stripslashes(@$options['show_customs']);
    if (!isset($arg['output_template'])) $arg['output_template'] = stripslashes(@$options['output_template']);
    // an empty output_template makes no sense so we fall back to the default
    if ($arg['output_template'] == '') $arg['output_template'] = 'h1';
    if (!isset($arg['match_cat'])) $arg['match_cat'] = @$options['match_cat'];
    if (!isset($arg['status'])) $arg['status'] = @$options['status'];
    if (!isset($arg['consider_max_incoming_links'])) $arg['consider_max_incoming_links'] = @$options['consider_max_incoming_links'];
    if (!isset($arg['max_incoming_links'])) $arg['max_incoming_links'] = @$options['max_incoming_links'];
    return $arg;
}

// the $post global can be overwritten by the use of $wp_query so we go back to the source
// note the addition of a 'manual overide' allowing the current posts to me marked by linkate_posts_mark_current for example
function link_cf_current_post_id($manual_current_ID = -1)
{
    $the_ID = -1;

    // -666 comes from admin page as preview w/o id
    if ($manual_current_ID === -666) {
        return false;
    }

    if ($manual_current_ID > 0) {
        $the_ID = $manual_current_ID;
    } else if (isset($GLOBALS['wp_the_query'])) {
        $the_ID = $GLOBALS['wp_the_query']->post->ID;
        if (!$the_ID) $the_ID = $GLOBALS['wp_the_query']->posts[0]->ID;
    } else {
        $the_ID = $GLOBALS['post']->ID;
    }
    return $the_ID;
}



/*

	Functions to fill in the WHERE part of the workhorse SQL
	
*/



function link_cf_where_show_pages($show_pages, $show_customs)
{
    if (function_exists('get_post_type')) {
        $typelist = array();
        if ($show_pages === 'true') {
            $typelist[] = "'page'";
            $typelist[] = "'post'";
        } else if ($show_pages === 'false') {
            $typelist[] = "'post'";
        } else if ($show_pages === 'but') {
            $typelist[] = "'page'";
        };
        if (!empty($show_customs)) {
            $customs = explode(',', $show_customs);
            foreach ($customs as $value) {
                $typelist[] = "'" . $value . "'";
            }
        }
        if (count($typelist) === 1) {
            $sql = "post_type=$typelist[0]";
        } else {
            $sql = "post_type IN (" . implode(',', $typelist) . ")";
        }
    } else {
        if ($show_pages === 'true') $sql = "post_status IN ('publish', 'static')";
        else if ($show_pages === 'false') $sql = "post_status = 'publish'";
        else if ($show_pages === 'but') $sql = "post_status = 'static'";
    }
    return $sql;
}

function link_cf_where_show_status($status)
{
    $set = array();
    $status = (array) $status;
    // a quick way of allowing for attachments having status=inherit
    foreach ($status as $name => $state) {
        if ($state === 'true') $set[] = "'$name'";
    }
    if ($set) {
        $result = implode(',', $set);
        return "post_status IN ($result)";
    } else {
        return "1 = 2";
    }
}

function link_cf_where_match_category($post_id)
{
    global $wpdb;
    $cat_ids = '';
    foreach (get_the_category($post_id) as $cat) {
        if ($cat->cat_ID) $cat_ids .= $cat->cat_ID . ',';
    }
    if (!isset($cat_ids)) {
        $cat_ids = '';
    }
    $cat_ids = rtrim($cat_ids, ',');
    $catarray = explode(',', $cat_ids);
    foreach ($catarray as $cat) {
        $catarray = array_merge($catarray, get_term_children($cat, 'category'));
    }
    $catarray = array_unique($catarray);
    $ids = get_objects_in_term($catarray, 'category');
    $ids = array_unique($ids);
    if (is_array($ids) && count($ids) > 0) {
        $out_posts = "'" . implode("', '", $ids) . "'";
        $sql = "$wpdb->posts.ID IN ($out_posts)";
    } else {
        $sql = "1 = 2";
    }
    return $sql;
}

function link_cf_where_excluded_cats($excluded_cats)
{
    global $wpdb;
    $catarray = explode(',', $excluded_cats);
    foreach ($catarray as $cat) {
        $catarray = array_merge($catarray, get_term_children($cat, 'category'));
    }
    $catarray = array_unique($catarray);
    $ids = get_objects_in_term($catarray, 'category');
    if (is_array($ids) && count($ids) > 0) {
        $out_posts = "'" . implode("', '", $ids) . "'";
        $sql = "$wpdb->posts.ID NOT IN ($out_posts)";
    } else {
        $sql = "1 = 1";
    }
    return $sql;
}

function link_cf_where_excluded_authors($excluded_authors)
{
    return "post_author NOT IN ( $excluded_authors )";
}

function link_cf_where_hide_pass()
{
    return "post_password =''";
}


function link_cf_get_suggestions_for_ids($results)
{
    global $wpdb;
    $table_name = $wpdb->prefix . "linkate_posts";
    $ids = array();
    foreach ($results as $k => $res) {
        # code...
        $ids[] = $res->ID;
    }
    $query = "SELECT pID, suggestions FROM $table_name WHERE pID IN (" . implode(',', $ids) . ") AND is_term = 0";
    $suggestions = $wpdb->get_results($query);
    foreach ($results as &$o1) {
        foreach ($suggestions as $o2) {
            if ($o1->ID === $o2->pID) {
                $o1 = (object) array_merge((array) $o1, (array) $o2);
            }
        }
    }
    return $results;
}

function linkate_decode_yoast_variables($post_id, $is_term = false)
{
    $string =  WPSEO_Meta::get_value('title', $post_id);
    if ($string !== '') {
        $replacer = new WPSEO_Replace_Vars();

        return $replacer->replace($string, get_post($post_id));
    } else {
        return '';
    }
}

function linkate_get_post_seo_title($post, $seo_meta_source = 'none')
{
    if (!$seo_meta_source || $seo_meta_source === 'none') {
        return '';
    }
    $seotitle = '';
    switch ($seo_meta_source) {
        case 'yoast':
            if (function_exists('YoastSEO')) {
                $seotitle = linkate_decode_yoast_variables($post->ID); // weird but works
                // YoastSEO()->meta->for_current_page()->title; https://developer.yoast.com/customization/apis/surfaces-api/
                // !! this returns old data $seotitle = YoastSEO()->meta->for_post( $post->ID )->title;
            }
            break;
        case 'aioseo':
            //All in One SEO Pack 4.0 Before
            if (!empty(get_post_meta($post->ID, '_aioseop_title', true))) {
                $seotitle = get_post_meta($post->ID, '_aioseop_title', true);
                break;
            }
            //All in One SEO 4.0 After
            if (function_exists('aioseo')) {
                // $seotitle = aioseo()->meta->metaData->getMetaData($post)->title;
                // if (!empty($seotitle)) {
                    $seotitle = aioseo()->meta->title->getPostTitle($post->ID);
                // }
            }
            break;
        case 'rankmath':
            if (class_exists('RankMath')) {
                $seotitle = RankMath\Post::get_meta('title', $post->ID, RankMath\Paper\Paper::get_from_options("pt_{$post->post_type}_title", $post, '%title% %sep% %sitename%'));
            }
            break;
        default:
            $seotitle = '';
            break;
    }
    return is_string($seotitle) ? $seotitle : '';
}



function linkate_get_image_sizes()
{
    global $_wp_additional_image_sizes;

    $sizes = array();

    foreach (get_intermediate_image_sizes() as $_size) {
        if (in_array($_size, array('thumbnail', 'medium', 'medium_large', 'large'))) {
            $sizes[$_size]['width']  = get_option("{$_size}_size_w");
            $sizes[$_size]['height'] = get_option("{$_size}_size_h");
            $sizes[$_size]['crop']   = (bool) get_option("{$_size}_crop");
        } elseif (isset($_wp_additional_image_sizes[$_size])) {
            $sizes[$_size] = array(
                'width'  => $_wp_additional_image_sizes[$_size]['width'],
                'height' => $_wp_additional_image_sizes[$_size]['height'],
                'crop'   => $_wp_additional_image_sizes[$_size]['crop'],
            );
        }
    }

    return $sizes;
}

/*

	End of SQL functions

*/