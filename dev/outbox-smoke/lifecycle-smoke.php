<?php
/**
 * Schema lifecycle assertions: activation created the tables, reinstall is
 * idempotent and preserves rows, and drop removes tables + version option.
 */

use CherryLink\Indexing\Outbox\Schema;

if (! Schema::isInstalled()) {
    WP_CLI::error('schema was not installed on activation');
}
if (! Schema::maybeInstall()) {
    WP_CLI::error('maybeInstall failed while the schema was current');
}

global $wpdb;
$before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}" . Schema::OUTBOX_TABLE);
WP_CLI::log('outbox rows before reinstall=' . $before);
if ($before === 0) {
    WP_CLI::error('expected rows from the repository smoke');
}

Schema::install();
$after = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}" . Schema::OUTBOX_TABLE);
if ($after !== $before) {
    WP_CLI::error('reinstall was not idempotent: rows changed');
}

Schema::drop();
$gone = $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}" . Schema::OUTBOX_TABLE . "'");
if ($gone !== null) {
    WP_CLI::error('drop left the outbox table behind');
}
if (get_option(Schema::VERSION_OPTION) !== false) {
    WP_CLI::error('drop left the schema version option behind');
}

WP_CLI::success('outbox lifecycle smoke passed');
