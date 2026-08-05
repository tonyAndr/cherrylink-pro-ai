<?php
/*
 * CherryLink Plugin
 */

// Disable direct access
defined( 'ABSPATH' ) || exit;
// Define lib name
define('LINKATE_TERMS_LIBRARY', true);

/* ====== CLASSIC ====== */

// Get terms, including tags and custom
// Creates hierarchical list for CherryLink panel
function hierarchical_term_tree($category = 0, $taxonomy = array()) {
    $output_template_item_prefix = '<li><span class="link-counter"  title="Найдено в тексте / переход к ссылке">[ 0 ]</span><div  title="Нажмите для вставки в текст" class="linkate-link link-term" data-url="{url}" data-title="{title}" data-taxonomy="{taxonomy}"><span class="link-title">';
    $output_template_item_suffix = '</span></div></li>';
    $list_prefix = '<ul class="linkate-terms-list">';
    $list_suffix = '</ul>';
    $output_tepmlate_devider = '<li class="linkate-terms-devider">{taxonomy}</li>';
    $r = ''; // tax item

    // get all terms from DB
    $args = array( 
        'parent' => $category,
        'taxonomy' => $taxonomy, // if not empty - looking for children
        'hide_empty'    => false,
        'orderby' => 'taxonomy',
        'order' => 'ASC',
    );

    $next = get_terms($args);

    if ($next && !($next instanceof WP_Error)) {
        $r .= $list_prefix;

        foreach ($next as $cat) {
            if (!$cat instanceof WP_Term || $cat->taxonomy == 'nav_menu')
                continue;

            $cat_tax = get_taxonomy($cat->taxonomy);

            // Don't show terms w/o taxonomies or if taxonomy isn't public
            if ($cat_tax === false || (is_object($cat_tax) && !$cat_tax->public))
                continue;
            
            if ($taxonomy != $cat->taxonomy) { // if next type of taxonomy - add header/divider
                $taxonomy = $cat->taxonomy;
                $label = is_object($cat_tax) ? $cat_tax->label : $cat->taxonomy;
                $r .= str_replace('{taxonomy}', $label, $output_tepmlate_devider);
            }
            $link = get_term_link($cat);
            $r .= str_replace(  // item template with values
                    array('{url}','{title}','{taxonomy}'),
                    array($link,$cat->name,$cat->taxonomy),
                    $output_template_item_prefix) .  $cat->name . ' ('.$cat->count.')' . $output_template_item_suffix;
            $r .= $cat->term_id !== 0 ? hierarchical_term_tree($cat->term_id, $taxonomy) : null; // check children

        }

        $r .= $list_suffix;
    }

    return $r;
}

// For quick filtering Classic
function linkate_get_all_categories($category = 0, $level = 0) {
	// get all terms from DB
	$args = array(
		'parent' => $category,
		'taxonomy' => 'category', // if not empty - looking for children
		'hide_empty'    => '0',
	);

	$r = array();

	$next = get_terms($args);

	if ($next) {
		foreach ($next as $cat) {
			if (!$cat instanceof WP_Term)
				continue;

			$r[] = array ($level => $cat->name);

			if ($cat->term_id !== 0) {
				$r = array_merge($r, linkate_get_all_categories($cat->term_id, $level+1));
			}

		}
	}

	return $r;
}

/* ====== GUTENBERG ====== */

// Cat links list, including custom and tags
function linkate_gutenberg_hierarchical_terms($category = 0, $taxonomy = array()) {
    $r = array(); // tax item

    $args = array( 
        'parent' => $category,
        'taxonomy' => $taxonomy, // if not empty - looking for children
        'hide_empty'    => '0',
        'orderby' => 'taxonomy',
        'order' => 'ASC',
    );

    $next = get_terms($args);

    if ($next) {
        foreach ($next as $cat) {
            if (!$cat instanceof WP_Term || $cat->taxonomy == 'nav_menu')
                continue;

            $cat_tax = get_taxonomy($cat->taxonomy);

            // Don't show terms w/o taxonomies or if taxonomy isn't public
            if ($cat_tax === false || (is_object($cat_tax) && !$cat_tax->public))
                continue;

            if ($taxonomy != $cat->taxonomy) { // if next type of taxonomy - add header/divider
                $taxonomy = $cat->taxonomy;
                $label = is_object($cat_tax) ? $cat_tax->label : $cat->taxonomy;
                $r[] = array(
                    "name" => $label,
                    "is_divider" => "yes"
                );
            }

            $r[] = array(
                "url" => get_term_link($cat),
                "name" => $cat->name,
                "taxonomy" => $cat->taxonomy,
                "post_count" => $cat->count,
                "is_divider" => "no",
                "children" => []
            ); 


			if ($cat->term_id !== 0) {
				$r[sizeof($r)-1]['children'] = linkate_gutenberg_hierarchical_terms($cat->term_id, $taxonomy);
			}


        }
    }

    return $r;
}

//, returns json
function linkate_gutenberg_hierarchical_terms_json() {
    \CherryLink\Ajax\Security::verify('edit_posts');
    $cats = linkate_gutenberg_hierarchical_terms();
    echo json_encode($cats);
    wp_die();
}
add_action('wp_ajax_linkate_gutenberg_hierarchical_terms_json', 'linkate_gutenberg_hierarchical_terms_json');

// For quick filtering Gutenberg
function linkate_get_all_categories_gutenberg($category = 0) {
	// get all terms from DB
	$args = array(
		'parent' => $category,
		'taxonomy' => 'category', // if not empty - looking for children
		'hide_empty'    => '0',
	);

	$r = array();

	$next = get_terms($args);

	if ($next) {
		foreach ($next as $cat) {
			if (!$cat instanceof WP_Term)
				continue;

			$r[] = array ('name' => $cat->name, 'children' => []);

			if ($cat->term_id !== 0) {
				$r[sizeof($r)-1]['children'] = linkate_get_all_categories_gutenberg($cat->term_id);
			}

		}
	}

	return $r;
}
//, returns json
function linkate_get_all_categories_json() {
    \CherryLink\Ajax\Security::verify('edit_posts');
    $cats = linkate_get_all_categories_gutenberg();
    echo json_encode($cats);
    wp_die();
}
add_action('wp_ajax_linkate_get_all_categories_json', 'linkate_get_all_categories_json');
