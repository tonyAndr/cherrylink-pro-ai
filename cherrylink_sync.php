<?php
/*
 * CherryLink — legacy shim.
 * The background incremental sync (Action Scheduler worker + state helpers + AJAX)
 * moved to \CherryLink\Indexing\SyncService (src/Indexing/SyncService.php),
 * registered from the Plugin bootstrap.
 *
 * The CL_SYNC_* constant names are kept as back-compat aliases; the option keys the
 * state/plan persist under are unchanged, so an in-flight scan survives the refactor.
 */

defined('ABSPATH') || exit;
define('LINKATE_SYNC_LIBRARY', true);

use CherryLink\Indexing\SyncService;

const CL_SYNC_HOOK         = SyncService::HOOK;
const CL_SYNC_GROUP        = SyncService::GROUP;
const CL_SYNC_STATE        = SyncService::STATE;
const CL_SYNC_PLAN         = SyncService::PLAN;
const CL_SYNC_UPSERT_BATCH = SyncService::UPSERT_BATCH;
const CL_SYNC_LINK_BATCH   = SyncService::LINK_BATCH;
