<?php
/*
 * CherryLink — legacy shim.
 * The link-card field getters moved to \CherryLink\Editor\LinkFields
 * (src/Editor/LinkFields.php). These global functions stay as thin delegators:
 * the render golden-master test dispatches through the linkate_otf_* names, and
 * linkate_link_fields()/linkate_unparse_url() are referenced from the test + the
 * suggestions pipeline.
 */

defined('ABSPATH') || exit;
define('LP_OT_LIBRARY', true);

use CherryLink\Editor\LinkFields;

function linkate_otf_postid($option_key, $result)
{
    return LinkFields::postId($option_key, $result);
}

function linkate_otf_title($option_key, $result)
{
    return LinkFields::title($option_key, $result);
}

function linkate_otf_url($option_key, $result)
{
    return LinkFields::url($option_key, $result);
}

function linkate_otf_author($option_key, $result)
{
    return LinkFields::author($option_key, $result);
}

function linkate_otf_date($option_key, $result)
{
    return LinkFields::date($option_key, $result);
}

function linkate_otf_anons($option_key, $result)
{
    return LinkFields::anons($option_key, $result);
}

function linkate_otf_suggestions($option_key, $result)
{
    return LinkFields::suggestions($option_key, $result);
}

function linkate_otf_categorynames($option_key, $result)
{
    return LinkFields::categoryNames($option_key, $result);
}

function linkate_otf_imagesrc($option_key, $result)
{
    return LinkFields::imageSrc($option_key, $result);
}

/**
 * @param \stdClass $result
 * @return array<string,mixed>
 */
function linkate_link_fields($option_key, $result)
{
    return LinkFields::forResult($option_key, $result);
}

function linkate_unparse_url($url, $opt)
{
    return LinkFields::unparseUrl((string) $url, (string) $opt);
}
