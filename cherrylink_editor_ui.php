<?php
/*
 * CherryLink — legacy shim.
 * The classic-editor integration (media button + panel, CSS/JS enqueues, inline
 * options bridge, and the get_linkate_links / get_linkate_suggestions AJAX) moved to
 * \CherryLink\Editor\ClassicPanel (src/Editor/ClassicPanel.php), registered from the
 * Plugin bootstrap behind the same admin + valid-license gate.
 *
 * create_quick_cat_select() stays as a thin delegator because the classic-panel
 * template calls it directly during render.
 */

defined('ABSPATH') || exit;

use CherryLink\Editor\ClassicPanel;

function create_quick_cat_select()
{
    return ClassicPanel::createQuickCatSelect();
}
