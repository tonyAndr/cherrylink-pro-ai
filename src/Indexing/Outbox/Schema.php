<?php

declare(strict_types=1);

namespace CherryLink\Indexing\Outbox;

use CherryLink\Support\Logger;

/**
 * Prefix-aware outbox schema and its lifecycle upgrade strategy.
 *
 * The outbox is a plugin-owned, tenant-scoped table pair created with dbDelta so it
 * follows the site's configured `$wpdb->prefix` (never a hardcoded `wp_`). It stores
 * immutable payload snapshots/tombstones plus delivery state; the queue is never
 * persisted in an autoloaded option.
 *
 * Lifecycle:
 *   - `install()` runs on activation and after a plugin update (version-gated).
 *   - `maybeInstall()` re-runs it lazily when the stored version is stale, which
 *     recovers from an activation/update that crashed before the table existed.
 *   - `drop()` runs on uninstall so outbox rows are removed with the plugin.
 *
 * dbDelta is idempotent and forward-only: columns are added but never dropped, and
 * existing rows survive an upgrade. On failure the version option is NOT advanced,
 * so the next `maybeInstall()` retries instead of silently proceeding on a broken
 * schema.
 */
final class Schema
{
    /** Bump when the outbox DDL changes shape. */
    public const DB_VERSION = 1;

    /** Unprefixed table suffixes; the real names use $wpdb->prefix. */
    public const OUTBOX_TABLE = 'cherrylink_outbox';
    public const STATE_TABLE  = 'cherrylink_outbox_state';

    /** Option holding the last successfully installed schema version (autoload off). */
    public const VERSION_OPTION = 'cherrylink_db_version';

    public static function version(): int
    {
        return self::DB_VERSION;
    }

    /** @return bool True when the current schema version is already installed. */
    public static function isInstalled(): bool
    {
        return (int) \get_option(self::VERSION_OPTION, 0) >= self::DB_VERSION;
    }

    /**
     * Run the install if the stored version is behind. Cheap when already current
     * (one option read), so it is safe to call defensively on `plugins_loaded`.
     */
    public static function maybeInstall(): bool
    {
        if (self::isInstalled()) {
            return true;
        }

        return self::install();
    }

    /**
     * Create/upgrade both tables and record the version. Returns false (without
     * advancing the version) when dbDelta is unavailable or throws, so the failure
     * is retried rather than masked.
     */
    public static function install(): bool
    {
        if (! \function_exists('dbDelta')) {
            $upgrade = ABSPATH . 'wp-admin/includes/upgrade.php';
            if (! \is_file($upgrade)) {
                return false;
            }
            require_once $upgrade;
        }
        if (! \function_exists('dbDelta')) {
            return false;
        }

        global $wpdb;
        /** @var \wpdb $wpdb */
        $charset = $wpdb->get_charset_collate();

        try {
            \dbDelta(self::outboxDdl($wpdb->prefix, $charset));
            \dbDelta(self::stateDdl($wpdb->prefix, $charset));
        } catch (\Throwable $error) {
            (new Logger())->log('CherryLink outbox schema install failed: ' . $error->getMessage());
            return false;
        }

        // dbDelta commonly reports a failed statement without throwing. Verify the
        // tables exist before recording the version, otherwise the defensive
        // installer would never retry and the plugin would run on a missing table.
        if (! self::tablesExist($wpdb)) {
            (new Logger())->log('CherryLink outbox schema install did not create both tables');
            return false;
        }

        \update_option(self::VERSION_OPTION, self::DB_VERSION, false);
        return true;
    }

    /**
     * True when both outbox tables exist under the site's actual prefix.
     *
     * @param \wpdb|null $wpdb Defaults to the global $wpdb.
     */
    public static function tablesExist($wpdb = null): bool
    {
        if (! \is_object($wpdb)) {
            global $wpdb;
        }
        if (! \is_object($wpdb) || ! \is_callable([$wpdb, 'get_var'])) {
            return false;
        }
        /** @var \wpdb $db */
        $db = $wpdb;
        foreach ([self::OUTBOX_TABLE, self::STATE_TABLE] as $suffix) {
            $table  = $db->prefix . $suffix;
            $exists = $db->get_var($db->prepare('SHOW TABLES LIKE %s', $db->esc_like($table)));
            if ($exists !== $table) {
                return false;
            }
        }

        return true;
    }

    /**
     * Remove both tables. Called from uninstall.php only — never as a rollback
     * shortcut while the plugin is active.
     */
    public static function drop(): void
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        // No direct interpolation of the prefix into a user-controlled string; the
        // prefix comes from wpdb itself and the suffixes are our constants.
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}" . self::OUTBOX_TABLE); // phpcs:ignore WordPress.DB
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}" . self::STATE_TABLE); // phpcs:ignore WordPress.DB
        \delete_option(self::VERSION_OPTION);
    }

    /** @return string CREATE TABLE statement for the outbox. */
    public static function outboxDdl(string $prefix, string $charset): string
    {
        $table = $prefix . self::OUTBOX_TABLE;

        return "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL auto_increment,
  event_key varchar(64) NOT NULL,
  source_epoch char(36) NOT NULL,
  source_revision bigint(20) unsigned NOT NULL,
  post_id bigint(20) unsigned NOT NULL default 0,
  kind varchar(20) NOT NULL,
  run_id varchar(64) NULL,
  payload longtext NOT NULL,
  state varchar(20) NOT NULL default 'pending',
  attempts int(10) unsigned NOT NULL default 0,
  next_attempt_gmt datetime NULL,
  lease_owner varchar(64) NULL,
  lease_expiry_gmt datetime NULL,
  remote_item_id varchar(64) NULL,
  last_http_status int(10) unsigned NOT NULL default 0,
  last_retry_after int(10) unsigned NULL,
  last_error text NULL,
  created_gmt datetime NOT NULL,
  updated_gmt datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY event_key (event_key),
  KEY state_due (state, next_attempt_gmt),
  KEY epoch_revision (source_epoch, source_revision),
  KEY post_id (post_id)
) {$charset};";
    }

    /** @return string CREATE TABLE statement for the singleton epoch/sequence row. */
    public static function stateDdl(string $prefix, string $charset): string
    {
        $table = $prefix . self::STATE_TABLE;

        return "CREATE TABLE {$table} (
  id tinyint unsigned NOT NULL,
  source_epoch char(36) NOT NULL,
  last_revision bigint(20) unsigned NOT NULL default 0,
  updated_gmt datetime NOT NULL,
  PRIMARY KEY  (id)
) {$charset};";
    }
}
