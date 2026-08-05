<?php
/*
 * CherryLink — legacy shim.
 * Implementation moved to \CherryLink\Indexing\IndexHelpers (src/Indexing/IndexHelpers.php).
 * This alias keeps existing CL_Index_Helpers references working.
 */

defined('ABSPATH') || exit;

define('LINKATE_INDEX_HELPERS', true);

class_alias(\CherryLink\Indexing\IndexHelpers::class, 'CL_Index_Helpers');
