<?php
/*
 * Cherry Vector API Client
 * Handles all communication with the Cherry Vector API service.
 */

defined('ABSPATH') || exit;

// URL priority: wp-config.php constant → admin settings → local dev default
if (!defined('CHERRY_VECTOR_API_URL')) {
    $options = get_option('linkate-posts', []);
    $saved_url = !empty($options['vector_api_url']) ? $options['vector_api_url'] : 'https://seocherry.apov.dev';
    define('CHERRY_VECTOR_API_URL', $saved_url);
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
        $this->license_key = !empty($options['vector_api_key'])
            ? $options['vector_api_key']
            : ($options['hash_field'] ?: '123123');
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
     * Batch-upsert multiple posts in a single API call.
     * Much faster than individual upserts — one TEI embed call for all posts.
     *
     * @param WP_Post[] $wp_posts
     * @return array ['indexed' => int, 'errors' => int]
     */
    public function upsert_batch(array $wp_posts): array
    {
        if (empty($wp_posts)) {
            return ['indexed' => 0, 'errors' => 0];
        }

        $documents = [];
        foreach ($wp_posts as $post) {
            $documents[] = [
                'post_id' => $post->ID,
                'title'   => $post->post_title,
                'text'    => wp_strip_all_tags($post->post_content),
                'url'     => get_permalink($post->ID),
                'lang'    => 'ru',
            ];
        }

        $response = $this->request('POST', '/v1/documents/batch', ['documents' => $documents]);

        if ($response === null) {
            return ['indexed' => 0, 'errors' => count($documents)];
        }

        return [
            'indexed' => (int)($response['indexed'] ?? 0),
            'errors'  => (int)($response['errors']  ?? 0),
        ];
    }

    /**
     * Fetch a batch of posts from DB and upsert them all in one API call.
     * Returns ['indexed' => int, 'errors' => int, 'total' => int].
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

        if (empty($posts)) {
            return ['indexed' => 0, 'errors' => 0, 'total' => 0];
        }

        $wp_posts = array_map(fn($p) => new WP_Post($p), $posts);
        $result   = $this->upsert_batch($wp_posts);

        return array_merge($result, ['total' => count($posts)]);
    }

    // ── HTTP ────────────────────────────────────────────────────────────────

    private function request(string $method, string $path, array $body = []): ?array
    {
        if (empty($this->license_key)) {
            cherry_write_log('CherryVectorAPI: no license key configured');
            return null;
        }

        $is_batch  = str_ends_with($path, '/batch');
        $is_search = str_ends_with($path, '/search');
        $args = [
            'method'  => $method,
            'timeout' => $is_batch ? 120 : ($is_search ? 60 : 15),
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
