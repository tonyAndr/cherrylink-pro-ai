<?php
/*
 * CherryLink Plugin
 */

// Disable direct access 
defined('ABSPATH') || exit;
// Define lib name
define('LINKATE_EF_LIBRARY', true);

// Post indexing is handled by cherrylink_index.php (vector API)

// Update term index
// !!! DISABLING TERM INDEXING COMPLETELY
// function linkate_sp_save_index_entry_term($term_id, $tt_id, $taxonomy) {
// 	global $wpdb, $table_prefix;
// 	$table_name = $table_prefix . 'linkate_posts';
// 	$options = get_option('linkate-posts', []);
    
//     $use_stemming = $options['use_stemming'] === "true";
//     $stemmer = new Stem\LinguaStemRu();
//     $stemmer->enable_stemmer($use_stemming);

//     $index_helpers = new CL_Index_Helpers($stemmer, $options, $wpdb);

// 	$term = $wpdb->get_row("SELECT `term_id`, `name` FROM $wpdb->terms WHERE term_id = $term_id", ARRAY_A);


// 	$suggestions_donors_src = $options['suggestions_donors_src'];
//     $suggestions_donors_join = $options['suggestions_donors_join'];
// 	$clean_suggestions_stoplist = $options['clean_suggestions_stoplist'];
// 	$min_len = $options['term_length_limit'];

//     $linkate_overusedwords = $index_helpers->prepare_stopwords();

// 	$descr = '';
// 	$descr .= term_description($term_id); // standart 
// 	// custom plugins sp-category && f-cattxt
// 	$opt = get_option('category_'.$term_id);
// 	if ($opt && (function_exists('contents_sp_category') || function_exists('show_descr_top'))) {
// 		$descr .= $opt['descrtop'] ? ' '.$opt['descrtop'] : '';  
// 		$descr .= $opt['descrbottom'] ? ' '.$opt['descrbottom'] : '';  
// 		$aio_title = $opt['title'];
// 	}

// 	// wp_linkate_scheme, create new scheme for this term
// 	if ($options['linkate_scheme_exists']) {
// 		linkate_scheme_delete_record($term_id, 1);
// 		linkate_scheme_add_row($descr, $term_id, 1); 
// 		$options['linkate_scheme_time'] = time();
// 		update_option('linkate-posts', $options);
// 	}
//     $descr_words_list = mb_split("\W+", $index_helpers->linkate_sp_mb_clean_words($descr));
//     list($content, $content_sugg) = $index_helpers->linkate_sp_get_post_terms($descr_words_list, $min_len, $linkate_overusedwords, $clean_suggestions_stoplist);
// 	//Seo title is more relevant, usually
// 	//Extracting terms from the custom titles, if present
// 	$seotitle = '';

//     $yoast_opt = get_option('wpseo_taxonomy_meta');
//     if ($yoast_opt && $yoast_opt['category'] && function_exists('wpseo_init')) {
//         $seotitle = $yoast_opt['category'][$term_id]['wpseo_title'];
//     }
//     if (!$seotitle && $aio_title && function_exists('show_descr_top'))
//         $seotitle = $aio_title;

//     if (!empty($seotitle) && $seotitle !== $term['name']) {
//         $title = $term['name'] . " " . $seotitle;
//     } else {
//         $title = $term['name'];
//     }

//     list($title, $title_sugg) = $index_helpers->linkate_sp_get_title_terms( $title, $min_len, $linkate_overusedwords, $clean_suggestions_stoplist );

// 	// Extract ancor terms
// 	$suggestions = $index_helpers->linkate_sp_prepare_suggestions($title_sugg, $content_sugg, $suggestions_donors_src, $suggestions_donors_join);
// 	$tags = "";
// 	//check to see if the field is set
// 	$pid = $wpdb->get_var("SELECT pID FROM $table_name WHERE pID=$term_id AND is_term=1 limit 1");
// 	//then insert if empty
// 	if (is_null($pid)) {
// 		$wpdb->query("INSERT INTO $table_name (pID, content, title, tags, is_term, suggestions) VALUES ($term_id, \"$content\", \"$title\", \"$tags\", 1, \"$suggestions\")");
// 	} else {
// 		$wpdb->query("UPDATE $table_name SET content=\"$content\", title=\"$title\", tags=\"$tags\", suggestions=\"$suggestions\" WHERE pID=$term_id AND is_term=1" );
// 	}
// 	//return $postID;
// }

// function linkate_sp_delete_index_entry_term($term_id, $term_taxonomy_ID, $taxonomy_slug, $already_deleted_term) {
// 	global $wpdb, $table_prefix;
// 	$table_name = $table_prefix . 'linkate_posts';
// 	$wpdb->query("DELETE FROM $table_name WHERE pID = $term_id AND is_term = 1");
// 	//return $term_id;
// }