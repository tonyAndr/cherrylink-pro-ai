<?php
/*
 * CherryLink Plugin
 */

// Disable direct access
defined('ABSPATH') || exit;
// Define lib name
define('LP_OT_LIBRARY', true);

// Called by the post plugins to match output tags to the actions that evaluate them
function lp_output_tag_action($tag)
{
    return 'linkate_otf_' . $tag;
}

// To add a new output template tag all you need to do is write a tag function like those below.

// All the tag functions must follow the pattern of 'linkate_otf_title' below. 
//	the name is the tag name prefixed by 'linkate_otf_'
//	the arguments are always $option_key, $result and $ext
//		$option_key	the key to the plugin's options
//		$result		the particular row of the query result
//		$ext			some extra data which a tag may use
//	the return value is the value of the tag as a string  

function linkate_otf_postid($option_key, $result, $ext)
{
    return $result->ID;
}

function linkate_otf_title($option_key, $result, $ext)
{
    if (isset($result->manual_title))
        return $result->manual_title; // return manual title for block links

    $value = htmlspecialchars($result->post_title, ENT_QUOTES, 'UTF-8'); // for json
    $value = apply_filters('the_title', $value, $result->ID);
    if (defined('QTRANSLATE_FILE')) $value = apply_filters('translate_text', $value);
    return $value;
}

function linkate_otf_title_seo($option_key, $result, $ext)
{
    if (isset($result->manual_title))
        return $result->manual_title; // return manual title for block links
    $seotitle = '';
    $options = get_option($option_key);
    $seo_meta_source = $options['seo_meta_source'];

    $seotitle = linkate_get_post_seo_title($result, $seo_meta_source);

    if (empty(trim($seotitle))) {
        $seotitle = $result->post_title;
    }
    $seotitle = htmlspecialchars($seotitle, ENT_QUOTES);
    if (defined('QTRANSLATE_FILE')) {
        $seotitle = apply_filters('translate_text', $seotitle);
    }
    return $seotitle;
}

function linkate_otf_url($option_key, $result, $ext)
{
    $options = get_option('linkate-posts', []);
    $url_option = $options['relative_links'];
    $value = get_permalink($result->ID);
    $value = linkate_unparse_url($value, $url_option);
    return $value;
}

function linkate_otf_author($option_key, $result, $ext)
{
    $type = false;
    if ($ext) {
        $s = explode(':', $ext);
        if (count($s) == 1) {
            $type = $s[0];
        }
    }
    switch ($type) {
        case 'display':
            $author = get_the_author_meta('display_name', $result->post_author);
            break;
        case 'full':
            $auth = get_userdata($result->post_author);
            $author = $auth->first_name . ' ' . $auth->last_name;
            break;
        case 'reverse':
            $auth = get_userdata($result->post_author);
            $author = $auth->last_name . ', ' . $auth->first_name;
            break;
        case 'first':
            $auth = get_userdata($result->post_author);
            $author = $auth->first_name;
            break;
        case 'last':
            $auth = get_userdata($result->post_author);
            $author = $auth->last_name;
            break;
        default:
            $author = get_the_author_meta('display_name', $result->post_author);
    }
    return $author;
}

function linkate_otf_date($option_key, $result, $ext)
{
    if ($ext === 'raw') return $result->post_date;
    else return linkate_oth_format_date($result->post_date, $ext, $result->ID);
}

function linkate_otf_anons($option_key, $result, $ext)
{
    $options = get_option($option_key);

    if ($options['anons_len']) {
        $limit = intval($options['anons_len']);
    } else {
        $limit = 220;
    }
    $meta = get_post_meta($result->ID, 'perelink', true);
    if ($meta) {
        $value = $meta;
    } else {
        $value = trim($result->post_excerpt);
        if ($value == '') $value = $result->post_content;
    }
    if (defined('QTRANSLATE_FILE')) $value = apply_filters('translate_text', $value);
    $excerpt = preg_replace(" (\[.*?\])", '', $value);
    $excerpt = strip_shortcodes($excerpt);
    $excerpt = strip_tags($excerpt);
    $excerpt = mb_substr($excerpt, 0, $limit);
    $next_space_pos = mb_strripos($excerpt, " ");
    if ($next_space_pos)
        $excerpt = mb_substr($excerpt, 0, $next_space_pos);
    $excerpt = trim(preg_replace('/\s+/', ' ', $excerpt));
    $excerpt = htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8'); // for json
    $excerpt = $excerpt . '...';
    return $excerpt;
}

function linkate_otf_suggestions($option_key, $result, $ext) {
    return trim($result->suggestions);
}

function linkate_otf_categorynames($option_key, $result, $ext)
{
    $cats = get_the_category($result->ID);
    $value = ''; //$n = 0;
    foreach ($cats as $k => $cat) {
        $value .= $k === 0 ? $cat->name : ", " . $cat->name;
    }
    return $value;
}

function linkate_otf_imagesrc($option_key, $result, $ext)
{
    $options = get_option($option_key);
    $url_option = $options['relative_links'];
    $template_image_size = isset($options['template_image_size']) ? $options['template_image_size'] : '';
    $crb_placeholder_path = CHERRYLINK_DIR_URL . 'img/imgsrc_placeholder.jpg';

    $size_to_use = $template_image_size;

    // Check Featured Image first
    $imgsrc = get_the_post_thumbnail_url($result->ID, $size_to_use ? $size_to_use : '');

    if ($imgsrc) {
        // $featured_src = linkate_get_featured_src($result->ID);
        // $featured_src = get_site_url() . "/wp-content/uploads/" . $featured_src;
        $imgsrc = linkate_unparse_url($imgsrc, $url_option);
        return $imgsrc;
    }

    // DANGEROUS but possibly can find more images
    $content = $result->post_content;

    // Try to extract img tags from html
    $pattern = '/<img.+?src\s*=\s*[\'|\"](.*?)[\'|\"].+?>/i';
    $found = preg_match_all($pattern, $content, $matches);
    if ($found) {
        // $i = isset($s[0]) ? $s[0] : false;
        // if (!$i) $i = 0;
        // $imgsrc = $matches[1][$i];
        $imgsrc = $matches[1][0];
        $imgsrc = linkate_unparse_url($imgsrc, $url_option);
    }

    // Well, shite, return placeholder
    if (!$imgsrc) { // placeholder
        return $crb_placeholder_path;
    }

    // Now we try to find suitable size
    // first check using vanilla url
    $att_id = attachment_url_to_postid($imgsrc);

    // cut the shit outta here
    if (!$att_id) {
        $imgsrc = preg_replace("~-\d{2,4}x\d{2,4}(?!.*-\d{2,4}x\d{2,4})~", '', $imgsrc);
        $att_id = attachment_url_to_postid($imgsrc);
    }

    // If not found again, return imgsrc from prev step and relax
    if (!$att_id) {
        return $imgsrc;
    }

    // Now lets try to get needed size
    // If size is empty then original will be returned
    $attachement = wp_get_attachment_image_url($att_id, $size_to_use ? $size_to_use : '');
    if ($attachement) {
        $imgsrc = $attachement;
    }

    if (!$imgsrc) // placeholder
        $imgsrc = $crb_placeholder_path;

    return $imgsrc;
}

// ****************************** Helper Functions *********************************************


function linkate_oth_format_date($date, $fmt, $id)
{
    if (!$fmt) $fmt = get_option('date_format');
    $d = mysql2date($fmt, $date);
    $d = apply_filters('get_the_time', $d, $fmt, $id);
    return apply_filters('the_time', $d, $fmt);
}

function linkate_unparse_url($url, $opt)
{
    $url = trim($url);
    $parsed_url = parse_url($url);
    if ($parsed_url === false) {
        return '';
    }
    // $REQUEST_SCHEME = '';
    // $HTTP_HOST = '';
    $REQUEST_SCHEME = !empty($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] . '://' : '';
    $HTTP_HOST = !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';

    if ($parsed_url) {
        $scheme   = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : $REQUEST_SCHEME;
        $host     = isset($parsed_url['host']) ? $parsed_url['host'] : $HTTP_HOST;
        $port     = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
        $user     = isset($parsed_url['user']) ? $parsed_url['user'] : '';
        $pass     = isset($parsed_url['pass']) ? ':' . $parsed_url['pass']  : '';
        $pass     = ($user || $pass) ? "$pass@" : '';
        $path     = isset($parsed_url['path']) ? $parsed_url['path'] : '';
        $query    = isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '';
        $fragment = isset($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] : '';
        if ($opt === "full") {
            return "$scheme$host$port$path";
        }
        if ($opt === "no_proto") {
            return "//$host$port$path";
        }
        if ($opt === "no_domain") {
            return "$path";
        }
    }
    return $url;
}
