<?php
/*
 * Cherry Vector API Client
 * Handles all communication with the Cherry Vector API service.
 */

defined('ABSPATH') || exit;

// URL is defined in wp-config.php or falls back to the local dev stack default
if (!defined('CHERRY_VECTOR_API_URL')) {
    define('CHERRY_VECTOR_API_URL', 'http://api:8000');
}

class CherryVectorAPI
{
    private string $base_url;
    private string $license_key;
    private string $domain;

    public function __construct()
    {
        $this->base_url    = rtrim(CHERRY_VECTOR_API_URL, '/');
        $options           = get_option('linkate-posts', []);
        $this->license_key = $options['hash_field'] ?? '';
        $this->domain      = $_SERVER['HTTP_HOST'] ?? parse_url(home_url(), PHP_URL_HOST);
    }

    // ── Public API ──────────────────────────────────────────────────────────

    /**
     * Index (upsert) a single post. Safe to call on every post save.
     */
    public function upsert_post(WP_Post $post): bool
    {
        $body = [
            'post_id' => $post->ID,
            'title'   => $post->post_title,
            'text'    => wp_strip_all_tags($post->post_content),
            'url'     => get_permalink($post->ID),
            'lang'    => 'ru',
        ];

        $response = $this->request('POST', '/v1/documents', $body);
        return $response !== null;
    }

    /**
     * Remove a post from the vector index.
     */
    public function delete_post(int $post_id): bool
    {
        $response = $this->request('DELETE', "/v1/documents/{$post_id}");
        return $response !== null;
    }

    /**
     * Search for semantically similar posts.
     *
     * @param string $query        Search text (current post title + content).
     * @param string $source_html  Raw HTML of the current post (for anchor suggestions).
     * @param int    $limit        Maximum number of results.
     * @return array|null          Array of results or null on failure.
     */
    public function search(string $query, string $source_html = '', int $limit = 20): ?array
    {
        $body = [
            'query' => $query,
            'limit' => $limit,
        ];

        if (!empty($source_html)) {
            $body['source_html'] = $source_html;
        }

        $response = $this->request('POST', '/v1/search', $body);
        return $response['results'] ?? null;
    }

    /**
     * Batch-upsert all indexable posts. Runs in batches to avoid timeouts.
     * Returns ['indexed' => int, 'errors' => int].
     */
    public function reindex_all(int $batch_size = 50, int $offset = 0): array
    {
        global $wpdb;

        $allowed_types = CL_Index_Helpers::get_allowed_types_list();
        $placeholders  = implode(',', array_fill(0, count($allowed_types), '%s'));

        $posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_title, post_content, post_status
                 FROM {$wpdb->posts}
                 WHERE post_type IN ($placeholders)
                   AND post_status = 'publish'
                 LIMIT %d OFFSET %d",
                array_merge($allowed_types, [$batch_size, $offset])
            )
        );

        $indexed = 0;
        $errors  = 0;

        foreach ($posts as $post) {
            $wp_post = new WP_Post($post);
            if ($this->upsert_post($wp_post)) {
                $indexed++;
            } else {
                $errors++;
            }
        }

        return [
            'indexed' => $indexed,
            'errors'  => $errors,
            'total'   => count($posts),
        ];
    }

    // ── HTTP ────────────────────────────────────────────────────────────────

    private function request(string $method, string $path, array $body = []): ?array
    {
        if (empty($this->license_key)) {
            cherry_write_log('CherryVectorAPI: no license key configured');
            return null;
        }

        $args = [
            'method'  => $method,
            'timeout' => 15,
            'headers' => [
                'Content-Type'   => 'application/json',
                'X-License-Key'  => $this->license_key,
                'X-Site-Domain'  => $this->domain,
            ],
        ];

        if (!empty($body)) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($this->base_url . $path, $args);

        if (is_wp_error($response)) {
            cherry_write_log('CherryVectorAPI error: ' . $response->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            cherry_write_log("CherryVectorAPI HTTP {$code} on {$method} {$path}");
            return null;
        }

        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($decoded) ? $decoded : [];
    }
}
