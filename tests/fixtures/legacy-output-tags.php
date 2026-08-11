<?php

declare(strict_types=1);

/*
 * Test fixture — the legacy linkate_otf_* link-card field getters.
 *
 * The render golden-master (RenderOutputTest) reproduces the ORIGINAL {tag}-dispatch
 * mechanism, which resolved each template placeholder by calling a global function named
 * "linkate_otf_<tag>" (via function_exists). Those globals used to live in the plugin
 * root (cherrylink_output_tags.php); they have no production callers anymore — the code
 * calls \CherryLink\Editor\LinkFields directly — so they live here, as test scaffolding
 * for the oracle only. Each delegates to LinkFields, exactly as the old root shim did.
 */

use CherryLink\Editor\LinkFields;

if (! function_exists('linkate_otf_postid')) {
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
}
