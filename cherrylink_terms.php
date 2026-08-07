<?php
/*
 * CherryLink — legacy shim.
 * The taxonomy term trees + quick-filter builders + their AJAX moved to
 * \CherryLink\Terms\TermsService (src/Terms/TermsService.php), registered from
 * the Plugin bootstrap.
 *
 * hierarchical_term_tree()/linkate_get_all_categories() stay as thin delegators
 * because the classic-panel template and the editor UI call them directly.
 */

defined('ABSPATH') || exit;
define('LINKATE_TERMS_LIBRARY', true);

use CherryLink\Terms\TermsService;

/**
 * @param int|string          $category
 * @param string|array<mixed> $taxonomy
 */
function hierarchical_term_tree($category = 0, $taxonomy = array())
{
    return TermsService::classicTree($category, $taxonomy);
}

/**
 * @param int|string $category
 */
function linkate_get_all_categories($category = 0, $level = 0)
{
    return TermsService::allCategories($category, (int) $level);
}
