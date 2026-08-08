<?php

declare(strict_types=1);

namespace CherryLink\Admin;

use CherryLink\Contracts\Registerable;

/**
 * Adds the "Исх"/"Вх" (outgoing/incoming link count) columns to the post & page
 * list tables, populates them from post meta, and makes them sortable.
 *
 * Ported verbatim from the legacy cherrylink_stats_column.php (now a thin shim).
 * register() reproduces the original file-scope guard: only wire the columns on
 * the edit.php list screen in the admin.
 */
final class StatsColumn implements Registerable
{
    public function register(): void
    {
        global $pagenow;

        if (! \is_admin() || 'edit.php' !== $pagenow) {
            return;
        }

        \add_filter('manage_posts_columns', [$this, 'columns']);
        \add_filter('manage_pages_columns', [$this, 'columns']);

        \add_filter('manage_edit-page_sortable_columns', [$this, 'sortableColumns']);
        \add_filter('manage_edit-post_sortable_columns', [$this, 'sortableColumns']);

        \add_action('manage_posts_custom_column', [$this, 'columnValue'], 10, 2);
        \add_action('manage_pages_custom_column', [$this, 'columnValue'], 10, 2);

        // pre_get_posts is an action-style hook (return value ignored); the legacy
        // code used add_filter, which is mechanically identical to add_action.
        \add_action('pre_get_posts', [$this, 'applySort']);
    }

    /**
     * @param array<string,string> $cols
     * @return array<string,string>
     */
    public function columns(array $cols): array
    {
        $cols['cherry_outgoing'] = \__('Исх', 'cherrylink');
        $cols['cherry_income']   = \__('Вх', 'cherrylink');

        return $cols;
    }

    /**
     * @param int|string $post_id
     */
    public function columnValue(string $column_name, $post_id): void
    {
        if ('cherry_income' === $column_name) {
            echo (int) \get_post_meta((int) $post_id, 'cherry_income', true);
        }

        if ('cherry_outgoing' === $column_name) {
            echo (int) \get_post_meta((int) $post_id, 'cherry_outgoing', true);
        }
    }

    /**
     * @param array<string,string> $columns
     * @return array<string,string>
     */
    public function sortableColumns(array $columns): array
    {
        $columns['cherry_outgoing'] = '_cherry_outgoing';
        $columns['cherry_income']   = '_cherry_income';

        return $columns;
    }

    /**
     * @param \WP_Query $query
     */
    public function applySort($query): void
    {
        $orderby = $query->get('orderby');

        if ('_cherry_outgoing' === $orderby) {
            $query->set('meta_query', $this->sortMetaQuery('cherry_outgoing'));
            $query->set('orderby', 'meta_value_num');
        }

        if ('_cherry_income' === $orderby) {
            $query->set('meta_query', $this->sortMetaQuery('cherry_income'));
            $query->set('orderby', 'meta_value_num');
        }
    }

    /**
     * OR meta_query that also matches posts missing the meta key, so sorting keeps
     * rows that were never scanned instead of dropping them.
     *
     * @return array<int|string,mixed>
     */
    private function sortMetaQuery(string $key): array
    {
        return [
            'relation' => 'OR',
            [
                'key'     => $key,
                'compare' => 'NOT EXISTS',
            ],
            [
                'key' => $key,
            ],
        ];
    }
}
