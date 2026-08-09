<?php
/*
 * CherryLink — legacy shim.
 * The index save/delete hooks, readiness-status payload, and the status/diagnostics
 * AJAX moved to \CherryLink\Indexing\IndexHooks (src/Indexing/IndexHooks.php),
 * registered from the Plugin bootstrap.
 *
 * cl_index_status_data() stays as a thin delegator because the readiness card and
 * the statistics subpage still call it directly during page render.
 */

defined('ABSPATH') || exit;
define('LINKATE_INDEX_LIBRARY', true);

use CherryLink\Indexing\IndexHooks;

/**
 * @return array<string,mixed>
 */
function cl_index_status_data(bool $refresh = false): array
{
    return IndexHooks::statusData($refresh);
}
