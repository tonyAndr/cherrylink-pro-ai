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
     * Return the number of documents indexed for this client.
     */
    public function get_count(): int
    {
        $response = $this->request('GET', '/v1/documents/count');
        return (int)($response['count'] ?? 0);
    }

    /**
     * Build the text to vectorize for a post: content + any configured custom fields.
     */
    private function build_text(WP_Post $post): string
    {
        $options = get_option('linkate-posts', []);
        $text    = wp_strip_all_tags($post->post_content);

        $custom_fields = array_filter(array_map('trim', explode("\n", $options['vector_custom_fields'] ?? '')));
        foreach ($custom_fields as $field) {
            $value = get_post_meta($post->ID, $field, true);
            if (!empty($value) && is_string($value)) {
                $text .= "\n" . wp_strip_all_tags($value);
            }
        }

        return trim($text);
    }

    /**
     * Index (upsert) a single post. Safe to call on every post save.
     */
    public function upsert_post(WP_Post $post): bool
    {
        $body = [
            'post_id' => $post->ID,
            'title'   => $post->post_title,
            'text'    => $this->build_text($post),
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
                'text'    => $this->build_text($post),
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

    // ── Link graph API ──────────────────────────────────────────────────────

    /**
     * Send raw post HTML to the API for link extraction and storage.
     * Returns the number of outgoing links found (for cherry_outgoing post meta).
     */
    public function parse_links(int $source_id, int $source_type, string $source_url, string $html): int
    {
        $response = $this->request('POST', '/v1/links/parse', [
            'source_id'   => $source_id,
            'source_type' => $source_type,
            'source_url'  => $source_url,
            'html'        => $html,
        ], 60);
        return (int)($response['count'] ?? 0);
    }

    /**
     * Remove all links originating from a post/term (call on post delete or before re-parse).
     */
    public function delete_links(int $source_id, int $source_type = 0): bool
    {
        $response = $this->request('DELETE', "/v1/links/source/{$source_id}?source_type={$source_type}");
        return $response !== null;
    }

    /**
     * Clear the entire link graph for this client (call before a full-site rescan).
     */
    public function delete_all_links(): bool
    {
        $response = $this->request('DELETE', '/v1/links/all');
        return $response !== null;
    }

    /**
     * Get all posts that link to a given URL — for the editor incoming-links panel.
     */
    public function get_incoming_links(string $target_url): ?array
    {
        $response = $this->request('GET', '/v1/links/incoming?target_url=' . rawurlencode($target_url));
        return $response;
    }

    /**
     * Paginated link stats for CSV/JSON export.
     * $direction: 'forward' or 'backward'
     */
    public function get_links_stats(string $direction, int $offset, int $limit): ?array
    {
        $path = '/v1/links/stats?direction=' . $direction . '&offset=' . $offset . '&limit=' . $limit;
        return $this->request('GET', $path, [], 120);
    }

    /**
     * Total link count for this client (replaces SELECT COUNT(*) from linkate_scheme).
     */
    public function get_links_count(): int
    {
        $response = $this->request('GET', '/v1/links/count');
        return (int)($response['count'] ?? 0);
    }

    /**
     * Full link-graph health analysis — powers "Поиск проблем с перелинковкой".
     */
    public function get_link_problems(): ?array
    {
        return $this->request('GET', '/v1/links/problems', [], 120);
    }

    // ── HTTP ────────────────────────────────────────────────────────────────

    private function request(string $method, string $path, array $body = [], int $timeout = 0): ?array
    {
        if (empty($this->license_key)) {
            cherry_write_log('CherryVectorAPI: no license key configured');
            return null;
        }

        if ($timeout === 0) {
            $is_batch  = str_ends_with($path, '/batch') || str_ends_with($path, '/all') || str_contains($path, '/parse');
            $is_search = str_ends_with($path, '/search') || str_ends_with($path, '/problems') || str_ends_with($path, '/stats');
            $timeout   = $is_batch ? 120 : ($is_search ? 60 : 15);
        }
        $args = [
            'method'  => $method,
            'timeout' => $timeout,
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
