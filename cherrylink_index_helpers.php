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

    /**
     * Pure incremental-sync diff (no WP calls — unit-testable).
     *
     * @param array $manifest         [post_id => ['modified' => string|null, 'hash' => ...]] from the API
     * @param array $current_modified [post_id => post_modified_gmt] for currently published posts
     * @return array ['upsert' => int[], 'delete' => int[]]
     *   upsert = posts new to the index or whose modified timestamp changed
     *   delete = indexed posts that are no longer published (orphans)
     */
    public static function compute_sync_plan(array $manifest, array $current_modified): array
    {
        $upsert = [];
        foreach ($current_modified as $id => $modified) {
            $id = (int)$id;
            if (!isset($manifest[$id]) || (string)($manifest[$id]['modified'] ?? '') !== (string)$modified) {
                $upsert[] = $id;
            }
        }
        $delete = array_values(array_diff(
            array_map('intval', array_keys($manifest)),
            array_map('intval', array_keys($current_modified))
        ));
        return ['upsert' => $upsert, 'delete' => $delete];
    }
}
