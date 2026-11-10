<?php
/*
 * Uninstall: remove everything the plugin persisted.
 *
 * Kept deliberately explicit (no wildcard LIKE queries) so it only ever touches
 * this plugin's own rows.
 */

if (defined('ABSPATH') && defined('WP_UNINSTALL_PLUGIN')) {
	global $wpdb;

	$options = [
		// Settings + licence state.
		'linkate-posts',
		'linkate_posts',
		'linkate_posts_meta',
		'cherrylink_index_dirty',
		'cherry_debug_info',
		// Synchronous scan state + its worker fence.
		'cherrylink_sync_state',
		'cherrylink_sync_plan',
		'cherrylink_sync_worker_lock',
		// Durable async run state, in-flight batch and worker fence.
		'cherrylink_async_run',
		'cherrylink_async_plan',
		'cherrylink_async_batch',
		'cherrylink_async_cancel',
		'cherrylink_async_worker_lock',
		'cherrylink_async_tenant',
		// Request locks.
		'cl_server_probe_lock',
		'cl_status_refresh_lock',
		// Schema version marker.
		'cherrylink_db_version',
	];
	foreach ($options as $option) {
		delete_option($option);
	}

	$transients = [
		'cherrylink_capabilities',
		'cl_status_cache',
		'cl_server_status_cache',
		'cherry-manual-indexation-needed',
		'cherrylink_updated',
	];
	foreach ($transients as $transient) {
		delete_transient($transient);
	}

	// Remove the durable async-indexing outbox (tables + rows).
	$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}cherrylink_outbox"); // phpcs:ignore WordPress.DB
	$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}cherrylink_outbox_state"); // phpcs:ignore WordPress.DB
}
