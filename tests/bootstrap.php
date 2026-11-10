<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap. Loads the dev-only tooling autoloader (Brain Monkey, PHPUnit),
 * defines the constants the legacy plugin files expect at include time, and installs
 * inert stubs for WordPress functions that legacy files invoke while being required.
 *
 * Value-returning WP functions (get_option, get_permalink, …) are NOT stubbed here —
 * tests mock those per-case with Brain Monkey so each test controls its own inputs.
 */

$devAutoload = __DIR__ . '/../dev/vendor/autoload.php';
if (!is_file($devAutoload)) {
    fwrite(STDERR, "\nDev dependencies are not installed. Run:\n    dev/qa.sh install\n\n");
    exit(1);
}
require $devAutoload;

if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/');
}
if (!defined('CHERRYLINK_TEXT_DOMAIN')) {
    define('CHERRYLINK_TEXT_DOMAIN', 'cherrylink');
}
// Support\Logger gates on WP_DEBUG; keep it off in tests so error_log stays quiet.
if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', false);
}
if (!defined('CHERRYLINK_DIR_URL')) {
    define('CHERRYLINK_DIR_URL', 'http://example.test/wp-content/plugins/cherrylink-pro-ai/');
}
if (!defined('CHERRYLINK_PLUGIN_DIR')) {
    define('CHERRYLINK_PLUGIN_DIR', dirname(__DIR__));
}
// wpdb result-shape constants referenced by $wpdb->get_row/get_results/get_var.
if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}

// The plugin's own Composer autoloader registers the CherryLink\ namespace (src/),
// so tests can resolve namespaced classes and the legacy class_alias shims.
$pluginAutoload = CHERRYLINK_PLUGIN_DIR . '/vendor/autoload.php';
if (is_file($pluginAutoload)) {
    require $pluginAutoload;
}

require __DIR__ . '/stubs/wp-functions.php';
