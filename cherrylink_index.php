<?php
/*
 * CherryLink — legacy shim.
 * The index save/delete hooks, readiness-status payload, and the status/diagnostics
 * AJAX moved to \CherryLink\Indexing\IndexHooks (src/Indexing/IndexHooks.php),
 * registered from the Plugin bootstrap.
 *
 * The status-payload functions stay as thin delegators because the readiness card
 * and the statistics subpage still call them directly during page render.
 */

defined('ABSPATH') || exit;
define('LINKATE_INDEX_LIBRARY', true);

use CherryLink\Indexing\IndexHooks;

// Back-compat aliases for the old global constant names.
const CL_STATUS_CACHE_KEY = IndexHooks::STATUS_CACHE_KEY;
const CL_STATUS_STALE_S   = IndexHooks::STATUS_STALE_S;

/**
 * @param array<string,mixed> $meta
 * @return array{last_scan_text:string,badge_html:string}
 */
function cl_index_status_local(array $meta): array
{
    return IndexHooks::statusLocal($meta);
}

/**
 * @return array<string,mixed>
 */
function cl_index_status_data(bool $refresh = false): array
{
    return IndexHooks::statusData($refresh);
}
