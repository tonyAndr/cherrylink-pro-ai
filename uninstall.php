<?php
/*
 * Uninstall: remove everything the plugin persisted.
 *
 * Kept deliberately explicit (no wildcard LIKE queries) so it only ever touches
 * this plugin's own rows.
 */

if (defined('ABSPATH') && defined('WP_UNINSTALL_PLUGIN')) {
	global $wpdb;

	$cherrylink_options = [
		// Settings + connection state.
		'cherrylink_options',
		'cherrylink_state',
		'cherrylink_index_dirty',
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
		'cherrylink_server_probe_lock',
		'cherrylink_status_refresh_lock',
		'cherrylink_key_check_lock',
		// Link health / key pages caches and the user's key-page pins.
		'cherrylink_link_health',
		'cherrylink_setup_done',
		'cherrylink_key_pages',
		'cherrylink_key_pins',
		// Schema version marker.
		'cherrylink_db_version',
	];
	foreach ($cherrylink_options as $cherrylink_option) {
		delete_option($cherrylink_option);
	}

	$cherrylink_transients = [
		'cherrylink_capabilities',
		'cherrylink_status_cache',
		'cherrylink_server_status_cache',
		'cherrylink_updated',
		'cherrylink_connect_nonce',
		'cherrylink_relay_until',
		'cherrylink_eligible_total',
	];
	foreach ($cherrylink_transients as $cherrylink_transient) {
		delete_transient($cherrylink_transient);
	}

	// Link-count post meta, the per-user notice dismissal and the settings capability.
	delete_post_meta_by_key('cherrylink_outgoing');
	delete_post_meta_by_key('cherrylink_incoming');
	delete_metadata('user', 0, 'cherrylink_connect_notice_dismissed', '', true);
	$cherrylink_admin_role = get_role('administrator');
	if ($cherrylink_admin_role) {
		$cherrylink_admin_role->remove_cap('cherrylink_settings');
	}

	// Remove the durable async-indexing outbox (tables + rows).
	$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}cherrylink_outbox"); // phpcs:ignore WordPress.DB
	$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}cherrylink_outbox_state"); // phpcs:ignore WordPress.DB
}
