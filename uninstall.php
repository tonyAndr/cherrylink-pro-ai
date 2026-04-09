<?php
/*
 * Linkate Posts
 */
 
if (defined('ABSPATH') && defined('WP_UNINSTALL_PLUGIN')) {
	global $wpdb, $table_prefix;

	delete_option('linkate-posts');
	delete_option('linkate_posts');
	delete_option('linkate_posts_meta');

}
