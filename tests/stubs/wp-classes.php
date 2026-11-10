<?php

/**
 * Minimal WordPress class stubs for unit tests that need real instances of WP
 * types as type-hint arguments. These carry data only — behavior is never tested
 * here because WP itself is not loaded.
 */

if (!class_exists('WP_Post')) {
    class WP_Post
    {
        public $ID = 0;
        public $post_author = '0';
        public $post_content = '';
        public $post_title = '';
        public $post_excerpt = '';
        public $post_status = 'publish';
        public $post_type = 'post';
        public $post_name = '';
        public $post_modified = '2026-01-01 00:00:00';
        public $post_modified_gmt = '2026-01-01 00:00:00';
        public $post_date = '2026-01-01 00:00:00';
        public $post_date_gmt = '2026-01-01 00:00:00';
        public $guid = '';
        public $filter = 'raw';
    }
}
