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
    // deal with compound options
    if (isset($arg['custom-key'])) {
        $arg['custom']['key'] = $arg['custom-key'];
        unset($arg['custom-key']);
    }
    if (isset($arg['custom-op'])) {
        $arg['custom']['op'] = $arg['custom-op'];
        unset($arg['custom-op']);
    }
    if (isset($arg['custom-value'])) {
        $arg['custom']['value'] = $arg['custom-value'];
        unset($arg['custom-value']);
    }
    if (isset($arg['age-direction'])) {
        $arg['age']['direction'] = $arg['age-direction'];
        unset($arg['age-direction']);
    }
    if (isset($arg['age-length'])) {
        $arg['age']['length'] = $arg['age-length'];
        unset($arg['age-length']);
    }
    if (isset($arg['age-duration'])) {
        $arg['age']['duration'] = $arg['age-duration'];
        unset($arg['age-duration']);
    }
    if (isset($arg['sort-by1'])) {
        $arg['sort']['by1'] = $arg['sort-by1'];
        unset($arg['sort-by1']);
    }
    if (isset($arg['sort-order1'])) {
        $arg['sort']['order1'] = $arg['sort-order1'];
        unset($arg['sort-order1']);
    }
    if (isset($arg['sort-case1'])) {
        $arg['sort']['case1'] = $arg['sort-case1'];
        unset($arg['sort-case1']);
    }
    if (isset($arg['sort-by2'])) {
        $arg['sort']['by2'] = $arg['sort-by2'];
        unset($arg['sort-by2']);
    }
    if (isset($arg['sort-order2'])) {
        $arg['sort']['order2'] = $arg['sort-order2'];
        unset($arg['sort-order2']);
    }
    if (isset($arg['sort-case2'])) {
        $arg['sort']['case2'] = $arg['sort-case2'];
        unset($arg['sort-case2']);
    }
    if (isset($arg['status-publish'])) {
        $arg['status']['publish'] = $arg['status-publish'];
        unset($arg['status-publish']);
    }
    if (isset($arg['status-private'])) {
        $arg['status']['private'] = $arg['status-private'];
        unset($arg['status-private']);
    }
    if (isset($arg['status-draft'])) {
        $arg['status']['draft'] = $arg['status-draft'];
        unset($arg['status-draft']);
    }
    if (isset($arg['status-future'])) {
        $arg['status']['future'] = $arg['status-future'];
        unset($arg['status-future']);
    }
    // then fill in the defaults
    if (!isset($arg['limit'])) $arg['limit'] = stripslashes(@$options['limit']);
    if (!isset($arg['limit_ajax'])) $arg['limit_ajax'] = stripslashes(@$options['limit_ajax']);
    if (!isset($arg['skip'])) $arg['skip'] = stripslashes(@$options['skip']);
    if (!isset($arg['divider'])) $arg['divider'] = stripslashes(@$options['divider']);
    if (!isset($arg['match_all_against_title'])) $arg['match_all_against_title'] = @$options['match_all_against_title'];
    if (!isset($arg['omit_current_post'])) $arg['omit_current_post'] = @$options['omit_current_post'];
    if (!isset($arg['show_private'])) $arg['show_private'] = @$options['show_private'];
    if (!isset($arg['show_pages'])) $arg['show_pages'] = @$options['show_pages'];
    if (!isset($arg['none_text'])) $arg['none_text'] = stripslashes(@$options['none_text']);
    if (!isset($arg['no_text'])) $arg['no_text'] = @$options['no_text'];
    if (!isset($arg['tag_str'])) $arg['tag_str'] = stripslashes(@$options['tag_str']);
    if (!isset($arg['excluded_cats'])) $arg['excluded_cats'] = stripslashes(@$options['excluded_cats']);
    if (!isset($arg['included_cats'])) $arg['included_cats'] = stripslashes(@$options['included_cats']);
    if (!isset($arg['excluded_authors'])) $arg['excluded_authors'] = stripslashes(@$options['excluded_authors']);
    if (!isset($arg['included_authors'])) $arg['included_authors'] = stripslashes(@$options['included_authors']);
    // БЫЛО if (!isset($arg['excluded_posts'])) $arg['excluded_posts'] = stripslashes(@$options['excluded_posts']);

    // get from options + add from CRB args
    if (!isset($arg['excluded_posts']) || empty($arg['excluded_posts'])) {
        $arg['excluded_posts'] = stripslashes(@$options['excluded_posts']);
    } else {
        $excl_from_options = trim(stripslashes(@$options['excluded_posts']));
        if (isset($excl_from_options) && !empty($excl_from_options)) {
            $arg['excluded_posts'] .= "," . $excl_from_options;
        }
    }

    if (!isset($arg['included_posts'])) $arg['included_posts'] = stripslashes(@$options['included_posts']);
    if (!isset($arg['show_customs'])) $arg['show_customs'] = stripslashes(@$options['show_customs']);
    if (!isset($arg['stripcodes'])) $arg['stripcodes'] = @$options['stripcodes'];
    if (!isset($arg['prefix'])) $arg['prefix'] = stripslashes(@$options['prefix']);
    if (!isset($arg['suffix'])) $arg['suffix'] = stripslashes(@$options['suffix']);
    if (!isset($arg['output_template'])) $arg['output_template'] = stripslashes(@$options['output_template']);
    // an empty output_template makes no sense so we fall back to the default
    if ($arg['output_template'] == '') $arg['output_template'] = 'h1';
    if (!isset($arg['match_cat'])) $arg['match_cat'] = @$options['match_cat'];
    if (!isset($arg['match_tags'])) $arg['match_tags'] = @$options['match_tags'];
    if (!isset($arg['match_author'])) $arg['match_author'] = @$options['match_author'];
    if (!isset($arg['age'])) $arg['age'] = @$options['age'];
    if (!isset($arg['custom'])) $arg['custom'] = @$options['custom'];
    if (!isset($arg['sort'])) $arg['sort'] = @$options['sort'];
    if (!isset($arg['status'])) $arg['status'] = @$options['status'];
    if (!isset($arg['clean_suggestions_stoplist'])) $arg['clean_suggestions_stoplist'] = @$options['clean_suggestions_stoplist'];
    if (!isset($arg['ignore_relevance'])) $arg['ignore_relevance'] = @$options['ignore_relevance'];
    if (!isset($arg['weight_content'])) $arg['weight_content'] = @$options['weight_content'];
    if (!isset($arg['weight_title'])) $arg['weight_title'] = @$options['weight_title'];
    if (!isset($arg['weight_custom'])) $arg['weight_custom'] = @$options['weight_custom'];
    if (!isset($arg['num_terms'])) $arg['num_terms'] = stripslashes(@$options['num_terms']);
    if (!isset($arg['term_extraction'])) $arg['term_extraction'] = @$options['term_extraction'];

    if (!isset($arg['consider_max_incoming_links'])) $arg['consider_max_incoming_links'] = @$options['consider_max_incoming_links'];
    if (!isset($arg['max_incoming_links'])) $arg['max_incoming_links'] = @$options['max_incoming_links'];

    // the last options cannot be set via arguments
    $arg['stripcodes'] = @$options['stripcodes'];
    $arg['utf8'] = @$options['utf8'];
    $arg['use_stemming'] = @$options['use_stemming'];
    $arg['batch'] = @$options['batch'];

    $arg['ignore_sorting'] = isset($arg['ignore_sorting']) && $arg['ignore_sorting'] !== 'false';

    // other
    $arg['show_cat_filter'] = @$options['show_cat_filter'];
    return $arg;
}

function link_cf_prepare_template($template)
{
    // Now we process the output_template to find the embedded tags which are to be replaced
    // with values taken from the database.
    // A tag is of the form, {tag:ext}, where the tag part will be evaluated and replaced 
    // and the optional ext part provides extra data pertinent to that tag


    preg_match_all('/{((?:[^{}]|{[^{}]*})*)}/', $template, $matches);
    $translations = array();
    if (is_array($matches)) {

        foreach ($matches[1] as $match) {
            if (strpos($match, ':') !== false) {
                list($tag, $ext) = explode(':', $match, 2);
            } else {
                $tag = $match;
                $ext = false;
            }
            $action = lp_output_tag_action($tag);
            if (function_exists($action)) {
                // store the action that instantiates the tag
                $translations['acts'][] = $action;
                // add the tag in a form ready to use in translation later
                $translations['fulltags'][] = '{' . $match . '}';
                // the extra data if any
                $translations['exts'][] = $ext;
            }
        }
    }

    return $translations;
}

function link_cf_expand_template($result, $template, $translations, $option_key)
{
    global $wpdb;
    $replacements = array();

    if (array_key_exists('fulltags', $translations)) {
        $numtags = count($translations['fulltags']);
        for ($i = 0; $i < $numtags; $i++) {
            $fulltag = $translations['fulltags'][$i];
            $act = $translations['acts'][$i];
            $ext = $translations['exts'][$i];
            $replacements[$fulltag] = $act($option_key, $result, $ext);
        }
    }
    // Replace every valid tag with its value
    $tmp = strtr($template, $replacements) . "\n";
    return $tmp;
}


function link_cf_sort_items($sort, $results, $option_key, $group_template, $items)
{
    $translations1 = link_cf_prepare_template($sort['by1']);
    foreach ($results as $result) {
        $key1 = link_cf_expand_template($result, $sort['by1'], $translations1, $option_key);
        if ($sort['case1'] !== 'false') $key1 = strtolower($key1);
        $keys1[] = $key1;
    }
    if ($sort['by2'] !== '') {
        $translations2 = link_cf_prepare_template($sort['by2']);
        foreach ($results as $result) {
            $key2 = link_cf_expand_template($result, $sort['by2'], $translations2, $option_key);
            if ($sort['case2'] !== 'false') $key2 = strtolower($key2);
            $keys2[] = $key2;
        }
    }
    if (!empty($keys2)) {
        array_multisort($keys1, intval($sort['order1']), $keys2, intval($sort['order2']), $results, $items);
    } else {
        array_multisort($keys1, intval($sort['order1']), $results, $items);
    }
    // merge the group titles into the items
    if ($group_template) {
        $group_translations = link_cf_prepare_template($group_template);
        $prev_key = '';
        $insertions = 0;
        foreach ($keys1 as $n => $key) {
            if ($prev_key !== $key) {
                array_splice($items, $n + $insertions, 0, link_cf_expand_template($results[$n], $group_template, $group_translations, $option_key));
                $insertions++;
            }
            $prev_key = $key;
        }
    }
    return $items;
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

function link_cf_where_match_author()
{
    $current_author = $GLOBALS['wp_the_query']->post->post_author;
    return "post_author = $current_author";
}

function link_cf_where_match_tags($match_tags)
{
    global $wpdb;
    $args = array('fields' => 'ids');
    $tag_ids = wp_get_object_terms(link_cf_current_post_id(), 'post_tag', $args);
    if (is_array($tag_ids) && count($tag_ids) > 0) {
        if ($match_tags === 'any') {
            $ids = get_objects_in_term($tag_ids, 'post_tag');
        } else {
            $ids = array();
            foreach ($tag_ids as $tag_id) {
                if (count($ids) > 0) {
                    $ids = array_intersect($ids, get_objects_in_term($tag_id, 'post_tag'));
                } else {
                    $ids = get_objects_in_term($tag_id, 'post_tag');
                }
            }
        }
        if (is_array($ids) && count($ids) > 0) {
            $ids = array_unique($ids);
            $out_posts = "'" . implode("', '", $ids) . "'";
            $sql = "$wpdb->posts.ID IN ($out_posts)";
        } else {
            $sql = "1 = 2";
        }
    } else {
        $sql = "1 = 2";
    }
    return $sql;
}

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

function link_cf_where_included_cats($included_cats)
{
    global $wpdb;
    $catarray = explode(',', $included_cats);
    foreach ($catarray as $cat) {
        $catarray = array_merge($catarray, get_term_children($cat, 'category'));
    }
    $catarray = array_unique($catarray);
    $ids = get_objects_in_term($catarray, 'category');
    if (is_array($ids) && count($ids) > 0) {
        $ids = array_unique($ids);
        $in_posts = "'" . implode("', '", $ids) . "'";
        $sql = "ID IN ($in_posts)";
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

function link_cf_where_included_authors($included_authors)
{
    return "post_author IN ( $included_authors )";
}

function link_cf_where_excluded_posts($excluded_posts)
{
    return "ID NOT IN ( $excluded_posts )";
}

function link_cf_where_included_posts($included_posts)
{
    return "ID IN ( $included_posts )";
}

function link_cf_where_tag_str($tag_str)
{
    global $wpdb;
    if (strpos($tag_str, ',') !== false) {
        $intags = explode(',', $tag_str);
        foreach ((array) $intags as $tag) {
            $tags[] = sanitize_term_field('name', $tag, 0, 'post_tag', 'db');
        }
        $tag_type = 'any';
    } else if (strpos($tag_str, '+') !== false) {
        $intags = explode('+', $tag_str);
        foreach ((array) $intags as $tag) {
            $tags[] = sanitize_term_field('name', $tag, 0, 'post_tag', 'db');
        }
        $tag_type = 'all';
    } else {
        $tags[] = sanitize_term_field('name', $tag_str, 0, 'post_tag', 'db');
        $tag_type = 'any';
    }
    $ids = array();
    if ($tag_type == 'any') {
        foreach ($tags as $tag) {
            if (term_exists($tag, 'post_tag')) {
                $t = get_term_by('name', $tag, 'post_tag');
                $ids = array_merge($ids, get_objects_in_term($t->term_id, 'post_tag'));
            }
        }
    } else {
        foreach ($tags as $tag) {
            if (term_exists($tag, 'post_tag')) {
                $t = get_term_by('name', $tag, 'post_tag');
                if (count($ids) > 0) {
                    $ids = array_intersect($ids, get_objects_in_term($t->term_id, 'post_tag'));
                } else {
                    $ids = get_objects_in_term($t->term_id, 'post_tag');
                }
            }
        }
    }
    if (is_array($ids) && count($ids) > 0) {
        $ids = array_unique($ids);
        $out_posts = "'" . implode("', '", $ids) . "'";
        $sql = "$wpdb->posts.ID IN ($out_posts)";
    } else $sql = "1 = 2";
    return $sql;
}

// note the addition of a 'manual overide' allowing the current posts to me marked by linkate_posts_mark_current for example
function link_cf_where_omit_post($manual_current_ID = -1)
{
    $postid = link_cf_current_post_id($manual_current_ID);
    if ($postid <= 1) $postid = -1;
    return "ID != $postid";
}

function link_cf_where_hide_pass()
{
    return "post_password =''";
}

function link_cf_where_check_age($direction, $length, $duration)
{
    if ('none' === $direction) return '';
    $age = "DATE_SUB(CURDATE(), INTERVAL $length $duration)";
    // we only filter out posts based on age, not pages
    if ('before' === $direction) {
        if (function_exists('get_post_type')) {
            return "(post_date <= $age OR post_type='page')";
        } else {
            return "(post_date <= $age OR post_status='static')";
        }
    } else {
        if (function_exists('get_post_type')) {
            return "(post_date >= $age OR post_type='page')";
        } else {
            return "(post_date >= $age OR post_status='static')";
        }
    }
}

function link_cf_where_check_custom($key, $op, $value)
{
    if ($op === 'EXISTS') {
        return "meta_key = '$key'";
    } else {
        return "(meta_key = '$key' && meta_value $op '$value')";
    }
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