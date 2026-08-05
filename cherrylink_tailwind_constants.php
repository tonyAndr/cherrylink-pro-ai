<?php
/*
 * CherryLink — legacy shim.
 * Implementation moved to \CherryLink\Support\Tailwind (src/Support/Tailwind.php).
 * This alias keeps existing CL_TWC references working.
 */

defined('ABSPATH') || exit;

define('LINKATE_TAILWIND_CONSTANTS', true);

class_alias(\CherryLink\Support\Tailwind::class, 'CL_TWC');
