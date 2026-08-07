<?php
/*
 * CherryLink — legacy shim.
 * The suggestions query/args helpers moved to \CherryLink\CustomFields\Cf
 * (src/CustomFields/Cf.php). These global functions stay as thin delegators for
 * back-compat (the render test requires this file; linkate_get_image_sizes is used
 * by the ACF settings form).
 */

defined('ABSPATH') || exit;
define('LINKATE_CF_LIBRARY', true);

use CherryLink\CustomFields\Cf;

function link_cf_parse_args($args)
{
    return Cf::parseArgs((string) $args);
}

function link_cf_set_options($option_key, $arg)
{
    return Cf::setOptions($option_key, (array) $arg);
}

function link_cf_current_post_id($manual_current_ID = -1)
{
    return Cf::currentPostId($manual_current_ID);
}

function link_cf_where_show_pages($show_pages, $show_customs)
{
    return Cf::whereShowPages((string) $show_pages, (string) $show_customs);
}

function link_cf_where_show_status($status)
{
    return Cf::whereShowStatus($status);
}

function link_cf_where_match_category($post_id)
{
    return Cf::whereMatchCategory($post_id);
}

function link_cf_where_excluded_cats($excluded_cats)
{
    return Cf::whereExcludedCats((string) $excluded_cats);
}

function link_cf_where_excluded_authors($excluded_authors)
{
    return Cf::whereExcludedAuthors((string) $excluded_authors);
}

function link_cf_where_hide_pass()
{
    return Cf::whereHidePass();
}

function link_cf_get_suggestions_for_ids($results)
{
    return Cf::getSuggestionsForIds((array) $results);
}

function linkate_get_image_sizes()
{
    return Cf::imageSizes();
}
