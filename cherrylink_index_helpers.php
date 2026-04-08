<?php
/*
 * CherryLink Plugin - Index Helpers
 * Reduced to post-type and count helpers used by the vector API indexer.
 */

defined('ABSPATH') || exit;

define('LINKATE_INDEX_HELPERS', true);

class CL_Index_Helpers
{
    public $allowed_post_types;

    public function __construct()
    {
        $types = get_post_types(['public' => true, '_builtin' => false], 'objects');
        $this->allowed_post_types = ['post', 'page'];
        foreach ($types as $type) {
            $this->allowed_post_types[] = $type->name;
        }
    }

    public function get_indexable_posts_count()
    {
        global $wpdb;
        $allowed_types = $this->get_allowed_types_sql();
        return $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ($allowed_types) AND post_status = 'publish'");
    }

    public function get_allowed_types_sql()
    {
        return implode(', ', array_map(function ($type) {
            return "'" . $type . "'";
        }, $this->allowed_post_types));
    }

    /**
     * Returns the allowed post type list without requiring an instance.
     * Used by the vector API indexer.
     */
    public static function get_allowed_types_list(): array
    {
        $types = get_post_types(['public' => true, '_builtin' => false], 'objects');
        $list  = ['post', 'page'];
        foreach ($types as $type) {
            $list[] = $type->name;
        }
        return $list;
    }
}
