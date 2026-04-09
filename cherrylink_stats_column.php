<?php
/*
 * CherryLink Plugin
 */

// Disable direct access
defined( 'ABSPATH' ) || exit;
// Define lib name
define('LINKATE_STATS_COLUMN_LIBRARY', true);


global $pagenow;

if ( is_admin() && 'edit.php' == $pagenow ) {

    // manage colunms
    add_filter('manage_posts_columns', 'linkate_post_stats_column');
    add_filter('manage_pages_columns', 'linkate_post_stats_column');

    // make columns sortable
    add_filter( 'manage_edit-page_sortable_columns', 'linkate_add_custom_column_make_sortable' );
    add_filter( 'manage_edit-post_sortable_columns', 'linkate_add_custom_column_make_sortable' );

    // populate column cells
    add_action('manage_posts_custom_column', 'linkate_post_stats_column_values', 10, 2);
    add_action('manage_pages_custom_column', 'linkate_post_stats_column_values', 10, 2);

    // set query to sort
    add_filter( 'pre_get_posts', 'linkate_add_custom_column_do_sortable' );
}

// unique column 
function linkate_post_stats_column($cols) {
    $cols['cherry_outgoing'] = __('Исх', CHERRYLINK_TEXT_DOMAIN);
    $cols['cherry_income'] = __('Вх', CHERRYLINK_TEXT_DOMAIN);
    return $cols;
}


function linkate_post_stats_column_values($column_name, $post_id) {
    if ('cherry_income' == $column_name) {
        $permalink = get_permalink($post_id);
        $api       = new CherryVectorAPI();
        $response  = $permalink ? $api->get_incoming_links($permalink) : null;
        $incoming  = (int)($response['count'] ?? 0);
        update_post_meta($post_id, 'cherry_income', $incoming);
        echo $incoming;
    }

    if ('cherry_outgoing' == $column_name) {
        $out_cnt = get_post_meta($post_id, 'cherry_outgoing', true);
        echo (int)$out_cnt;
    }
}



// Make the custom column sortable

function linkate_add_custom_column_make_sortable( $columns ) {
	$columns['cherry_outgoing'] = '_cherry_outgoing';
	$columns['cherry_income'] = '_cherry_income';

	return $columns;
}

// Handle the custom column sorting
function linkate_add_custom_column_do_sortable( $query ) {


    $orderby = $query->get( 'orderby' );

    if ( '_cherry_outgoing' == $orderby ) {

        $meta_query = array(
            'relation' => 'OR',
            array(
                'key' => 'cherry_outgoing',
                'compare' => 'NOT EXISTS', // see note above
            ),
            array(
                'key' => 'cherry_outgoing',
            ),
        );

        $query->set( 'meta_query', $meta_query );
        $query->set( 'orderby', 'meta_value_num' );
    }

    if ( '_cherry_income' == $orderby ) {

        $meta_query = array(
            'relation' => 'OR',
            array(
                'key' => 'cherry_income',
                'compare' => 'NOT EXISTS', // see note above
            ),
            array(
                'key' => 'cherry_income',
            ),
        );

        $query->set( 'meta_query', $meta_query );
        $query->set( 'orderby', 'meta_value_num' );
    }

}