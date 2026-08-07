<?php
/*
 * CherryLink — legacy shim.
 * The classic-editor integration (media button + panel, CSS/JS enqueues, inline
 * options bridge, and the get_linkate_links / get_linkate_suggestions AJAX) moved to
 * \CherryLink\Editor\ClassicPanel (src/Editor/ClassicPanel.php), registered from the
 * Plugin bootstrap behind the same admin + valid-license gate.
 *
 * These two globals stay as thin delegators: linkate_build_source_html() is called
 * by the orphan-donor stats (via function_exists), and create_quick_cat_select() by
 * the classic-panel template.
 */

defined('ABSPATH') || exit;

use CherryLink\Editor\ClassicPanel;

function linkate_build_source_html(string $content): string
{
    return ClassicPanel::buildSourceHtml($content);
}

function create_quick_cat_select()
{
    return ClassicPanel::createQuickCatSelect();
}
