<?php
/*
 * CherryLink — legacy shim.
 * The editor link-stats + admin link-scheme reports moved to
 * \CherryLink\Statistics\StatisticsService, and the CSV writing to
 * \CherryLink\Csv\CsvService (src/Statistics/, src/Csv/), both registered from
 * the Plugin bootstrap. No external callers referenced the old global functions.
 */

defined('ABSPATH') || exit;
define('LINKATE_STATISTICS_LIBRARY', true);
