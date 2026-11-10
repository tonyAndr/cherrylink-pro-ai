<?php
/*
 * Linkate Posts
 */
 
if (defined('ABSPATH') && defined('WP_UNINSTALL_PLUGIN')) {
	global $wpdb;

	delete_option('linkate-posts');
	delete_option('linkate_posts');
	delete_option('linkate_posts_meta');

	// Remove durable async-run state + capability routing markers.
	delete_option('cherrylink_async_run');
	delete_option('cherrylink_async_plan');
	delete_option('cherrylink_async_tenant');
	// Capabilities are cached in a transient, not an option.
	delete_option('_transient_cherrylink_capabilities');
	delete_option('_transient_timeout_cherrylink_capabilities');
	delete_option('cherrylink_index_dirty');

	// Remove the durable async-indexing outbox (schema + rows + version marker).
	$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}cherrylink_outbox"); // phpcs:ignore WordPress.DB
	$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}cherrylink_outbox_state"); // phpcs:ignore WordPress.DB
	delete_option('cherrylink_db_version');

}
