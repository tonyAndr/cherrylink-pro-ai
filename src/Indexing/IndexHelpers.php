<?php

declare(strict_types=1);

namespace CherryLink\Indexing;

/**
 * Post-type + count helpers for the vector-API indexer, plus the pure
 * incremental-sync diff. Moved verbatim from the legacy CL_Index_Helpers
 * class (deleted).
 */
class IndexHelpers
{
    /** @var string[] */
    public $allowed_post_types;

    public function __construct()
    {
        $types                   = \get_post_types(['public' => true, '_builtin' => false], 'objects');
        $this->allowed_post_types = ['post', 'page'];
        foreach ($types as $type) {
            $this->allowed_post_types[] = $type->name;
        }
    }

    /**
     * @return string|null
     */
    public function get_indexable_posts_count()
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $allowed_types = $this->get_allowed_types_sql();
        return $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ($allowed_types) AND post_status = 'publish'");
    }

    public function get_allowed_types_sql(): string
    {
        return implode(', ', array_map(static function ($type) {
            return "'" . $type . "'";
        }, $this->allowed_post_types));
    }

    /**
     * Returns the allowed post-type list without requiring an instance.
     *
     * @return string[]
     */
    public static function get_allowed_types_list(): array
    {
        $types = \get_post_types(['public' => true, '_builtin' => false], 'objects');
        $list  = ['post', 'page'];
        foreach ($types as $type) {
            $list[] = $type->name;
        }
        return $list;
    }

    /**
     * Pure incremental-sync diff (no WP calls — unit-testable).
     *
     * @param array<int,array{modified?:string|null}> $manifest         API index state, keyed by post ID
     * @param array<int,string>                       $current_modified post_modified_gmt of published posts
     * @param bool                                    $force_full       re-upsert every published post
     * @return array{upsert:int[],delete:int[]}
     *   upsert = posts new to the index or whose modified timestamp changed;
     *   delete = indexed posts no longer published (orphans)
     */
    public static function compute_sync_plan(array $manifest, array $current_modified, bool $force_full = false): array
    {
        $upsert = [];
        foreach ($current_modified as $id => $modified) {
            $id = (int) $id;
            if ($force_full || !isset($manifest[$id]) || (string) ($manifest[$id]['modified'] ?? '') !== (string) $modified) {
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
