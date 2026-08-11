<?php
/*
 * CherryLink — legacy shim.
 * License validation, the activation/defaults lifecycle, the daily re-check cron,
 * the "index out of sync" notice and the admin capability grant moved to
 * \CherryLink\Support\License (registered by the Plugin container). Defaults live
 * in \CherryLink\Support\Options. These global functions stay as thin delegators
 * for the remaining legacy callers (settings page, activation/update hooks in
 * cherrylink_main.php, the indexing license gate in src/Indexing/*).
 */

defined('ABSPATH') || exit;
define('LINKATE_INSTALL_LIBRARY', true);

use CherryLink\Support\License;
use CherryLink\Support\Options;

function linkate_posts_install()
{
    return (new License(new Options()))->install();
}

// used on install, import settings, revert to defaults
function fill_options($options)
{
    return Options::fill($options);
}

function cl_is_licensed(): bool
{
    return (new License(new Options()))->isLicensed();
}

// The index-dirty flag semantics live on \CherryLink\Support\Options.

/** Flag the index as out-of-sync (a change happened while unlicensed). Records when it began. */
function cl_mark_index_dirty(): void
{
    (new Options())->markIndexDirty();
}

function cl_clear_index_dirty(): void
{
    (new Options())->clearIndexDirty();
}
