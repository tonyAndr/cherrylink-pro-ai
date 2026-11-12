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
        $this->allowed_post_types = self::get_allowed_types_list();
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
        // The content types chosen in the admin (post always; pages and custom types opt-in)
        // decide what is indexed, counted against the plan, and suggested in the editor.
        $options = (array) \get_option('linkate-posts', []);
        $customs = \array_filter(\array_map('trim', \explode(',', (string) ($options['show_customs'] ?? ''))));
        $list    = ['post'];
        foreach (self::get_public_types_list() as $type) {
            if (($type === 'page' && ($options['show_pages'] ?? 'false') === 'true') || \in_array($type, $customs, true)) {
                $list[] = $type;
            }
        }
        return $list;
    }

    /**
     * Every public content type the site has (post, page, custom types), enabled or not.
     *
     * @return string[]
     */
    public static function get_public_types_list(): array
    {
        $list = ['post', 'page'];
        foreach (\get_post_types(['public' => true, '_builtin' => false], 'objects') as $type) {
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
     * @param int|null                                $post_limit       plan limit; null/0 = unlimited (legacy plan shape)
     * @return array{upsert:int[],delete:int[]}|array{upsert:int[],delete:int[],drop:int[],links:int[]}
     *   upsert = posts new to the index or whose modified timestamp changed;
     *   delete = indexed posts no longer published (orphans).
     *   With a post limit only the newest `post_limit` posts (by modified, newest first)
     *   are upserted; `drop` = indexed published posts outside that set (vector only,
     *   their link graph is kept); `links` = the upserts (links follow upserts).
     */
    public static function compute_sync_plan(array $manifest, array $current_modified, bool $force_full = false, ?int $post_limit = null): array
    {
        if ($post_limit !== null && $post_limit > 0) {
            return self::compute_limited_plan($manifest, $current_modified, $force_full, $post_limit);
        }
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

    /**
     * @param array<int,array{modified?:string|null}> $manifest
     * @param array<int,string>                       $current_modified
     * @return array{upsert:int[],delete:int[],drop:int[],links:int[]}
     */
    private static function compute_limited_plan(array $manifest, array $current_modified, bool $force_full, int $limit): array
    {
        $ids = array_map('intval', array_keys($current_modified));
        usort($ids, static function (int $a, int $b) use ($current_modified): int {
            $cmp = strcmp((string) $current_modified[$b], (string) $current_modified[$a]); // newest first
            return $cmp !== 0 ? $cmp : $b <=> $a;
        });
        $top  = array_slice($ids, 0, $limit);
        $rest = array_slice($ids, $limit);

        $upsert = [];
        foreach ($top as $id) {
            if ($force_full || !isset($manifest[$id]) || (string) ($manifest[$id]['modified'] ?? '') !== (string) $current_modified[$id]) {
                $upsert[] = $id;
            }
        }
        $indexed = array_map('intval', array_keys($manifest));
        $delete  = array_values(array_diff($indexed, $ids));
        $drop    = array_values(array_intersect($rest, $indexed));

        // Links follow upserts, as in the unlimited path (the old "links for every over-limit
        // post" branch re-sent the whole tail on each scan; the server-side plan replaces it).
        return ['upsert' => $upsert, 'delete' => $delete, 'drop' => $drop, 'links' => $upsert];
    }

    /**
     * Map a `/v1/sync/plan` response onto the persisted plan shape (pure).
     *
     * index -> upsert, links -> links, drop_vectors -> drop (vector only), drop_links ->
     * delete (vector + links). With $force_full every eligible post is re-uploaded
     * (index + links), restricted to the newest `post_limit` posts when the plan has one.
     *
     * @param array<string,mixed> $response         decoded plan response
     * @param array<int,string>   $current_modified post_modified_gmt of the eligible posts, by ID
     * @return array{upsert:int[],delete:int[],drop:int[],links:int[]}
     */
    public static function plan_from_server(array $response, array $current_modified, bool $force_full = false, ?int $post_limit = null): array
    {
        $ints   = static function ($v): array {
            return \is_array($v) ? \array_values(\array_unique(\array_map('intval', $v))) : [];
        };
        $delete = $ints($response['drop_links'] ?? []);
        $drop   = \array_values(\array_diff($ints($response['drop_vectors'] ?? []), $delete));
        $upsert = $ints($response['index'] ?? []);
        $links  = $ints($response['links'] ?? []);

        if ($force_full) {
            $ids = \array_map('intval', \array_keys($current_modified));
            \usort($ids, static function (int $a, int $b) use ($current_modified): int {
                $cmp = \strcmp((string) $current_modified[$b], (string) $current_modified[$a]);
                return $cmp !== 0 ? $cmp : $b <=> $a;
            });
            $upsert = ($post_limit !== null && $post_limit > 0) ? \array_slice($ids, 0, $post_limit) : $ids;
            $links  = $ids;
        }

        return ['upsert' => $upsert, 'delete' => $delete, 'drop' => $drop, 'links' => $links];
    }
}
