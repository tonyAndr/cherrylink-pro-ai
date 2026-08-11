<?php
/*
 * CherryLink — legacy shim.
 * The install/defaults lifecycle, license validation, the daily re-check cron, the
 * "index out of sync" notice and the admin capability grant now live in
 * \CherryLink\Support\License and \CherryLink\Support\Lifecycle; defaults live in
 * \CherryLink\Support\Options.
 *
 * fill_options() stays only as a back-compat delegator for the option-defaults
 * characterization test (the production code calls Options::fill() directly). This
 * file is loaded by that test, not by the plugin bootstrap.
 */

defined('ABSPATH') || exit;
define('LINKATE_INSTALL_LIBRARY', true);

use CherryLink\Support\Options;

// used on install, import settings, revert to defaults
function fill_options($options)
{
    return Options::fill($options);
}
