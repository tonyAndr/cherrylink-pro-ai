<?php
/*
 * CherryLink Plugin
 */

// Disable direct access
defined('ABSPATH') || exit;


// ========================================================================================= //
// ============================== CherryLink Scheme Creation  ============================== //
// ========================================================================================= //

class CL_Stats_Scheme
{
    public $options;
    public $wpdb;
    public $table_prefix;

    public function __construct($options, $wpdb)
    {
        $this->options      = $options;
        $this->wpdb         = $wpdb;
        $this->table_prefix = $wpdb->prefix;
    }

    /**
     * Full-site link scan. Called in batches from the admin AJAX handler.
     *
     * On offset=0 the entire link graph for this client is cleared first,
     * then each post's raw HTML is sent to the API for parsing.
     * The API handles all <a> extraction, classification, and storage.
     */
    public function linkate_create_links_scheme(int $offset = 0, int $batch = 200): void
    {
        $api = new CherryVectorAPI();

        // Clear all existing links at the start of a full rescan
        if ($offset === 0) {
            $api->delete_all_links();
        }

        $index_helpers = new CL_Index_Helpers(null, null, $this->wpdb);
        $allowed_types = $index_helpers->get_allowed_types_sql();

        $posts = $this->wpdb->get_results(
            "SELECT ID, post_content FROM {$this->wpdb->posts}
             WHERE post_type IN ($allowed_types)
             LIMIT $offset, $batch",
            ARRAY_A
        );

        if (empty($posts)) {
            return;
        }

        foreach ($posts as $post) {
            $post_id    = (int) $post['ID'];
            $source_url = get_permalink($post_id);

            if (empty($post['post_content']) || empty($source_url)) {
                continue;
            }

            $count = $api->parse_links($post_id, 0, $source_url, $post['post_content']);
            update_post_meta($post_id, 'cherry_outgoing', $count);
        }
    }

    public function linkate_scheme_update_option_timestamp(): void
    {
        $options = get_option('linkate-posts', []);
        $options['linkate_scheme_exists'] = true;
        $options['linkate_scheme_time']   = time();
        update_option('linkate-posts', $options);
    }

    /**
     * Remove all links from a single post/term.
     * Called on post delete and before re-parsing on post save.
     */
    public function linkate_scheme_delete_record(int $id, int $type): int
    {
        $api = new CherryVectorAPI();
        $api->delete_links($id, $type);
        return $id;
    }

    /**
     * Parse and store links for a single post from its raw HTML.
     * Used on individual post save (via cherrylink_index.php).
     */
    public function linkate_scheme_add_row(string $html, int $post_id, int $is_term): void
    {
        $source_url = $is_term ? get_term_link($post_id) : get_permalink($post_id);
        if (is_wp_error($source_url) || empty($source_url)) {
            return;
        }

        $api   = new CherryVectorAPI();
        $count = $api->parse_links($post_id, $is_term, $source_url, $html);
        if (!$is_term) {
            update_post_meta($post_id, 'cherry_outgoing', $count);
        }
    }
}
