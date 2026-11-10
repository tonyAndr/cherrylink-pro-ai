<?php
/*
 * Linkate Posts
 */
 
if (defined('ABSPATH') && defined('WP_UNINSTALL_PLUGIN')) {
	global $wpdb;

	delete_option('linkate-posts');
	delete_option('linkate_posts');
	delete_option('linkate_posts_meta');

	// Remove the durable async-indexing outbox (schema + rows + version marker).
	$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}cherrylink_outbox"); // phpcs:ignore WordPress.DB
	$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}cherrylink_outbox_state"); // phpcs:ignore WordPress.DB
	delete_option('cherrylink_db_version');

}
