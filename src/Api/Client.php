<?php

declare(strict_types=1);

namespace CherryLink\Api;

use CherryLink\Indexing\IndexHelpers;
use CherryLink\Support\Logger;
use WP_Post;

/**
 * Cherry Vector API client — all communication with the Cherry Vector API service
 * (semantic search, link graph, indexing).
 *
 * Moved from the legacy CherryVectorAPI. The runtime CHERRY_VECTOR_API_URL /
 * CL_ALT_API_* constants and the http_api_curl alternative-route hook now live in
 * Api\Relay. Explicit interactive/status/background contexts bound UI requests while
 * preserving long-running background scan transport and structured HTTP results.
 */
class Client
{
    /** Maximum results the API accepts for /v1/search (SearchRequest le=50). */
    public const SEARCH_LIMIT_MAX = 50;

    public const CONTEXT_INTERACTIVE = 'interactive';
    public const CONTEXT_BACKGROUND = 'background';
    public const CONTEXT_STATUS = 'status';

    /** @var string */
    private $context;

    /** @var callable */
    private $clock;

    /** @var float|null Deadline starts on the first outbound call, not construction. */
    private $deadline;


    /** @var string */
    private $base_url;

    /** @var string */
    private $license_key;

    /** @var string */
    private $domain;

    /** @var Logger */
    private $logger;

    /** Is the alternative-server route toggled on in settings? */
    public static function alt_enabled(): bool
    {
        $options = \get_option('linkate-posts', []);
        return isset($options['use_alt_server']) && $options['use_alt_server'] === 'true';
    }

    public function __construct(
        ?Logger $logger = null,
        string $context = self::CONTEXT_INTERACTIVE,
        ?callable $clock = null
    ) {
        if (!\in_array($context, [self::CONTEXT_INTERACTIVE, self::CONTEXT_BACKGROUND, self::CONTEXT_STATUS], true)) {
            throw new \InvalidArgumentException('Unknown CherryLink request context');
        }
        $this->context = $context;
        $this->clock = $clock ?? static function (): float {
            return \hrtime(true) / 1e9;
        };
        $this->deadline = null;
        $this->logger   = $logger ?? new Logger();
        $this->base_url = \rtrim(CHERRY_VECTOR_API_URL, '/');
        // When the alternative route is on, hit the relay port on the SAME hostname (so TLS is
        // unchanged); the http_api_curl hook redirects the connection to the relay IP.
        if (self::alt_enabled()) {
            $host = \wp_parse_url($this->base_url, PHP_URL_HOST);
            if ($host) {
                $this->base_url = 'https://' . $host . ':' . CL_ALT_API_PORT;
            }
        }
        $options        = \get_option('linkate-posts', []);
        // Auth-key priority: wp-config constant (for dev) → explicit API-key setting →
        // the license key. NO hardcoded fallback — an unconfigured site sends nothing and
        // the API correctly refuses. Never ship the server ADMIN_KEY in plugin code.
        if (\defined('CHERRY_VECTOR_API_KEY') && CHERRY_VECTOR_API_KEY !== '') {
            $this->license_key = CHERRY_VECTOR_API_KEY;
        } elseif (!empty($options['vector_api_key'])) {
            $this->license_key = $options['vector_api_key'];
        } else {
            $this->license_key = $options['hash_field'] ?? '';
        }
        // Stable site identity: derive from home_url() (a stored WP option), NOT $_SERVER['HTTP_HOST'].
        // HTTP_HOST varies by how the request arrives (localhost vs 127.0.0.1) and is absent in
        // cron/WP-CLI, which would scatter documents across different API buckets. The HTTP_HOST
        // fallback (only when home_url has no host) is unslashed + sanitized per WP input rules.
        $fallback_host     = isset($_SERVER['HTTP_HOST'])
            ? \sanitize_text_field(\wp_unslash($_SERVER['HTTP_HOST']))
            : '';
        $this->domain      = \wp_parse_url(\home_url(), PHP_URL_HOST) ?: $fallback_host;
    }

    // ── Public API ──────────────────────────────────────────────────────────

    /**
     * Return the number of documents indexed for this client, or null if the API could not
     * be reached (so callers can distinguish "unreachable" from a genuine empty index of 0).
     */
    public function get_count(): ?int
    {
        $response = $this->request('GET', '/v1/documents/count');
        if ($response === null) {
            return null;
        }
        return (int)($response['count'] ?? 0);
    }

    /**
     * Build the text to vectorize for a post: content + any configured custom fields.
     */
    private function build_text(WP_Post $post): string
    {
        $options = \get_option('linkate-posts', []);
        $meta    = [];
        foreach ($this->configured_meta_fields($options) as $field) {
            $meta[$field] = \get_post_meta($post->ID, $field, true);
        }

        return $this->build_text_from((string) $post->post_content, $meta);
    }

    /**
     * Custom meta field names configured for embedding.
     *
     * @param array<string,mixed> $options
     * @return string[]
     */
    public function configured_meta_fields(array $options): array
    {
        return \array_values(\array_filter(\array_map(
            'trim',
            \explode("\n", (string) ($options['vector_custom_fields'] ?? ''))
        )));
    }

    /**
     * Build the embedding text from already-resolved content and meta values.
     *
     * Kept separate from `build_text()` so the durable path can supply values read
     * authoritatively from the database (the object cache may be stale while
     * WordPress cache invalidation is suspended).
     *
     * @param array<string,mixed> $meta_values keyed by configured field name
     */
    public function build_text_from(string $content, array $meta_values = []): string
    {
        $text = \wp_strip_all_tags($content);
        foreach ($meta_values as $value) {
            $flat = $this->flatten_meta_value($value);
            if ($flat !== '') {
                $text .= "\n" . $flat;
            }
        }

        return \trim($text);
    }

    /**
     * Flatten a post-meta value into plain text for embedding. Strings are tag-stripped,
     * numbers stringified, and arrays (e.g. ACF repeaters / groups / multi-value fields)
     * walked recursively with their scalar leaves joined. Non-scalar leaves (objects,
     * booleans, null) are skipped.
     *
     * @param mixed $value
     */
    private function flatten_meta_value($value): string
    {
        if (\is_string($value)) {
            return \wp_strip_all_tags($value);
        }
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }
        if (\is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                $flat = $this->flatten_meta_value($item);
                if ($flat !== '') {
                    $parts[] = $flat;
                }
            }
            return \implode("\n", $parts);
        }
        return '';
    }

    /**
     * Fingerprint of what we actually embed (text + title). A hash match means the stored
     * vector is current. Used for incremental sync so unchanged posts are not re-embedded.
     */
    public function content_hash(WP_Post $post): string
    {
        return \md5($this->build_text($post) . '|' . $post->post_title);
    }

    /**
     * Index (upsert) a single post. Safe to call on every post save.
     */
    public function upsert_post(WP_Post $post): bool
    {
        $body = [
            'post_id'      => $post->ID,
            'title'        => $post->post_title,
            'text'         => $this->build_text($post),
            'url'          => \get_permalink($post->ID),
            'lang'         => 'ru',
            'content_hash' => $this->content_hash($post),
            'modified_gmt' => $post->post_modified_gmt,
        ];

        $response = $this->request('POST', '/v1/documents', $body);
        return $response !== null;
    }

    /**
     * Payload snapshot for the durable async outbox (no source version).
     *
     * Captured AFTER the outbox allocates a source revision, so a concurrent save
     * always wins by revision rather than by arrival order. Reuses the same text
     * and content-hash construction as the synchronous upsert so both adapters
     * embed identical input.
     *
     * @return array{post_id:int,title:string,text:string,url:string,lang:string,content_hash:string,modified_gmt:string,link_html:string}
     */
    public function capture_payload(WP_Post $post): array
    {
        return $this->capture_payload_from_row([
            'post_id'          => (int) $post->ID,
            'post_title'       => (string) $post->post_title,
            'post_content'     => (string) $post->post_content,
            'post_modified_gmt' => (string) $post->post_modified_gmt,
        ]);
    }

    /**
     * Build the outbox payload from authoritative post values.
     *
     * `$row` carries what was read straight from the database (plus optional meta
     * values read from `postmeta`), so the payload never depends on a cached object
     * that a concurrent save may already have replaced. Two saves inside the same
     * second therefore produce different payloads whenever the content differs,
     * even though `post_modified_gmt` is identical.
     *
     * @param array{post_id:int,post_title:string,post_content:string,post_modified_gmt:string,url?:string} $row
     * @param array<string,mixed> $meta_values keyed by configured field name
     * @return array{post_id:int,title:string,text:string,url:string,lang:string,content_hash:string,modified_gmt:string,link_html:string}
     */
    public function capture_payload_from_row(array $row, array $meta_values = []): array
    {
        $title   = (string) $row['post_title'];
        $content = (string) $row['post_content'];
        $text    = $this->build_text_from($content, $meta_values);
        $url     = $row['url'] ?? (\function_exists('get_permalink') ? \get_permalink($row['post_id']) : '');

        return [
            'post_id'      => (int) $row['post_id'],
            'title'        => $title,
            'text'         => $text,
            'url'          => (string) ($url ?: ''),
            'lang'         => 'ru',
            'content_hash' => \md5($text . '|' . $title),
            'modified_gmt' => (string) $row['post_modified_gmt'],
            'link_html'    => $content,
        ];
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
            // The API validates 1..50 (le=50) and answers 422 outside it. Callers ask
            // for headroom (e.g. limit*3) so filtering can drop rows; with the default
            // settings that was 150 and every editor request came back empty.
            'limit' => \max(1, \min(self::SEARCH_LIMIT_MAX, $limit)),
        ];

        if (!empty($source_html)) {
            $body['source_html'] = $source_html;
        }

        $response = $this->request('POST', '/v1/search', $body);
        return $response['results'] ?? null;
    }

    /**
     * On-demand anchor suggestions for a single target article (fetched when the editor
     * expands a hit's suggestion panel). Returns a list of suggestion arrays.
     */
    public function suggest_anchors(string $source_html, string $target_title, int $limit = 10): array
    {
        if (empty($source_html) || empty($target_title)) {
            return [];
        }
        $response = $this->request('POST', '/v1/suggest-anchors', [
            'source_html'  => $source_html,
            'target_title' => $target_title,
            'limit'        => $limit,
        ], 60);
        return $response['suggestions'] ?? [];
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
        $result = $this->upsert_batch_result($wp_posts);

        return [
            'indexed' => $result['indexed'],
            'errors'  => $result['errors'],
        ];
    }

    /**
     * Batch-upsert with transport and partial-result metadata for background sync.
     *
     * @param WP_Post[] $wp_posts
     * @return array{ok:bool,retryable:bool,status:int,retry_after:int|null,indexed:int,errors:int,error:string}
     */
    public function upsert_batch_result(array $wp_posts): array
    {
        if (empty($wp_posts)) {
            return [
                'ok'          => true,
                'retryable'   => false,
                'status'      => 200,
                'retry_after' => null,
                'indexed'     => 0,
                'errors'      => 0,
                'error'       => '',
            ];
        }

        $documents = [];
        foreach ($wp_posts as $post) {
            $documents[] = [
                'post_id'      => $post->ID,
                'title'        => $post->post_title,
                'text'         => $this->build_text($post),
                'url'          => \get_permalink($post->ID),
                'lang'         => 'ru',
                'content_hash' => $this->content_hash($post),
                'modified_gmt' => $post->post_modified_gmt,
            ];
        }

        $response = $this->request_result('POST', '/v1/documents/batch', ['documents' => $documents]);
        $body     = \is_array($response['body']) ? $response['body'] : [];
        $valid    = \array_key_exists('indexed', $body) && \array_key_exists('errors', $body);
        $indexed  = $valid ? (int)$body['indexed'] : 0;
        $errors   = $response['ok'] && $valid ? (int)$body['errors'] : count($documents);
        $ok       = $response['ok'] && $valid && $errors === 0;

        return [
            'ok'          => $ok,
            'retryable'   => !$valid || $errors > 0 || self::is_retryable_result($response),
            'status'      => $response['status'],
            'retry_after' => $response['retry_after'],
            'indexed'     => $indexed,
            'errors'      => $errors,
            'error'       => $response['error'] ?: (!$valid ? 'invalid batch response' : ($errors > 0 ? 'batch contained errors' : '')),
        ];
    }

    /**
     * Fetch a batch of posts from DB and upsert them all in one API call.
     * Returns ['indexed' => int, 'errors' => int, 'total' => int].
     */
    public function reindex_all(int $batch_size = 50, int $offset = 0): array
    {
        global $wpdb;
        /** @var \wpdb $wpdb */

        $allowed_types = IndexHelpers::get_allowed_types_list();
        $placeholders  = \implode(',', \array_fill(0, count($allowed_types), '%s'));

        $posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_title, post_content, post_status
                 FROM {$wpdb->posts}
                 WHERE post_type IN ($placeholders)
                   AND post_status = 'publish'
                 LIMIT %d OFFSET %d",
                \array_merge($allowed_types, [$batch_size, $offset])
            )
        );

        if (empty($posts)) {
            return ['indexed' => 0, 'errors' => 0, 'total' => 0];
        }

        $wp_posts = \array_map(static function ($p) {
            return new WP_Post($p);
        }, $posts);
        $result   = $this->upsert_batch($wp_posts);

        return \array_merge($result, ['total' => count($posts)]);
    }

    // ── Incremental sync helpers ──────────────────────────────────────────────

    /**
     * Fetch the full index manifest as [post_id => ['hash' => ?, 'modified' => ?]].
     * Loops the paginated endpoint. This compatibility wrapper returns an empty map on
     * failure; SyncService uses fetch_manifest_result() so an outage fails closed.
     */
    public function fetch_manifest_map(): array
    {
        $result = $this->fetch_manifest_result();

        return $result['ok'] ? $result['map'] : [];
    }

    /**
     * Fetch the manifest while retaining failure metadata for a fail-closed sync.
     *
     * @return array{ok:bool,map:array<int,array{hash:mixed,modified:mixed}>,status:int,retry_after:int|null,retryable:bool,error:string}
     */
    public function fetch_manifest_result(): array
    {
        $map    = [];
        $cursor = null;
        $guard  = 0;
        do {
            $path = '/v1/documents/manifest?limit=1000';
            if ($cursor) {
                $path .= '&cursor=' . \rawurlencode($cursor);
            }
            $response = $this->request_result('GET', $path, [], 30);
            if (!$response['ok']) {
                return [
                    'ok'          => false,
                    'map'         => [],
                    'status'      => $response['status'],
                    'retry_after' => $response['retry_after'],
                    'retryable'   => self::is_retryable_result($response),
                    'error'       => $response['error'] ?: 'manifest request failed',
                ];
            }
            $resp = \is_array($response['body']) ? $response['body'] : [];
            if (!isset($resp['docs']) || !\is_array($resp['docs'])) {
                return [
                    'ok'          => false,
                    'map'         => [],
                    'status'      => $response['status'],
                    'retry_after' => null,
                    'retryable'   => false,
                    'error'       => 'manifest response missing docs',
                ];
            }
            foreach ($resp['docs'] as $d) {
                if (!\is_array($d) || !isset($d['post_id'])) {
                    return [
                        'ok'          => false,
                        'map'         => [],
                        'status'      => $response['status'],
                        'retry_after' => null,
                        'retryable'   => false,
                        'error'       => 'manifest response contains an invalid document',
                    ];
                }
                $map[(int)$d['post_id']] = [
                    'hash'     => $d['content_hash'] ?? null,
                    'modified' => $d['modified_gmt'] ?? null,
                ];
            }
            $cursor = $resp['next_cursor'] ?? null;
            ++$guard;
            if ($cursor && $guard >= 1000) {
                return [
                    'ok'          => false,
                    'map'         => [],
                    'status'      => 0,
                    'retry_after' => null,
                    'retryable'   => false,
                    'error'       => 'manifest pagination limit exceeded',
                ];
            }
        } while ($cursor);

        return [
            'ok'          => true,
            'map'         => $map,
            'status'      => 200,
            'retry_after' => null,
            'retryable'   => false,
            'error'       => '',
        ];
    }

    /**
     * Load posts by ID and upsert them in one batch. Returns ['indexed'=>int,'errors'=>int].
     *
     * @param int[] $post_ids
     */
    public function upsert_posts_by_ids(array $post_ids): array
    {
        $result = $this->upsert_posts_by_ids_result($post_ids);

        return [
            'indexed' => $result['indexed'],
            'errors'  => $result['errors'],
        ];
    }

    /**
     * @param int[] $post_ids
     * @return array{ok:bool,retryable:bool,status:int,retry_after:int|null,indexed:int,errors:int,error:string}
     */
    public function upsert_posts_by_ids_result(array $post_ids): array
    {
        $wp_posts = \array_filter(\array_map('get_post', $post_ids));
        return $this->upsert_batch_result(\array_values($wp_posts));
    }

    /**
     * Build the link graph for the given posts in one batched API call.
     * Returns the total number of links parsed across all posts.
     *
     * @param int[] $post_ids
     */
    public function parse_links_for_ids(array $post_ids): int
    {
        return $this->parse_links_for_ids_result($post_ids)['count'];
    }

    /**
     * @param int[] $post_ids
     * @return array{ok:bool,retryable:bool,status:int,retry_after:int|null,count:int,errors:int,error:string}
     */
    public function parse_links_for_ids_result(array $post_ids): array
    {
        $items = [];
        foreach ($post_ids as $pid) {
            $post = \get_post($pid);
            if (!$post || $post->post_status !== 'publish') {
                continue;
            }
            $url = \get_permalink($pid);
            if (empty($url)) {
                continue;
            }
            $items[] = [
                'source_id'   => (int)$pid,
                'source_type' => 0,
                'source_url'  => $url,
                'html'        => $post->post_content,
            ];
        }
        if (empty($items)) {
            return [
                'ok'          => true,
                'retryable'   => false,
                'status'      => 200,
                'retry_after' => null,
                'count'       => 0,
                'errors'      => 0,
                'error'       => '',
            ];
        }
        $result = $this->parse_links_batch_result($items);
        $body   = $result['body'];
        $count  = \array_sum(\array_map(static function ($r) {
            return (int)($r['count'] ?? 0);
        }, $body['results']));

        return [
            'ok'          => $result['ok'],
            'retryable'   => $result['retryable'],
            'status'      => $result['status'],
            'retry_after' => $result['retry_after'],
            'count'       => $count,
            'errors'      => $result['errors'],
            'error'       => $result['error'],
        ];
    }

    /**
     * Batched link parse — one broken-check + one transaction on the API side.
     */
    public function parse_links_batch(array $items): array
    {
        return $this->parse_links_batch_result($items)['body'];
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array{ok:bool,retryable:bool,status:int,retry_after:int|null,body:array{results:array<int,array<string,mixed>>,errors:int},errors:int,error:string}
     */
    public function parse_links_batch_result(array $items): array
    {
        if (empty($items)) {
            return [
                'ok'          => true,
                'retryable'   => false,
                'status'      => 200,
                'retry_after' => null,
                'body'        => ['results' => [], 'errors' => 0],
                'errors'      => 0,
                'error'       => '',
            ];
        }
        $response = $this->request_result('POST', '/v1/links/parse-batch', ['items' => $items], 120);
        $body     = \is_array($response['body']) ? $response['body'] : [];
        $valid    = \array_key_exists('results', $body)
            && \array_key_exists('errors', $body)
            && \is_array($body['results']);
        $errors   = $response['ok'] && $valid ? (int)$body['errors'] : count($items);
        $normalized = [
            'results' => \is_array($body['results'] ?? null) ? $body['results'] : [],
            'errors'  => $errors,
        ];

        return [
            'ok'          => $response['ok'] && $valid && $errors === 0,
            'retryable'   => !$valid || $errors > 0 || self::is_retryable_result($response),
            'status'      => $response['status'],
            'retry_after' => $response['retry_after'],
            'body'        => $normalized,
            'errors'      => $errors,
            'error'       => $response['error'] ?: (!$valid ? 'invalid link batch response' : ($errors > 0 ? 'link batch contained errors' : '')),
        ];
    }

    /**
     * Bulk-remove documents (orphan prune). Returns number deleted.
     *
     * @param int[] $post_ids
     */
    public function delete_documents_batch(array $post_ids): int
    {
        return $this->delete_documents_batch_result($post_ids)['deleted'];
    }

    /**
     * @param int[] $post_ids
     * @return array{ok:bool,retryable:bool,status:int,retry_after:int|null,deleted:int,error:string}
     */
    public function delete_documents_batch_result(array $post_ids): array
    {
        if (empty($post_ids)) {
            return [
                'ok'          => true,
                'retryable'   => false,
                'status'      => 200,
                'retry_after' => null,
                'deleted'     => 0,
                'error'       => '',
            ];
        }
        $response = $this->request_result(
            'POST',
            '/v1/documents/delete-batch',
            ['post_ids' => \array_values($post_ids)]
        );
        $body = \is_array($response['body']) ? $response['body'] : [];
        $valid = \array_key_exists('deleted', $body);

        return [
            'ok'          => $response['ok'] && $valid,
            'retryable'   => !$valid || self::is_retryable_result($response),
            'status'      => $response['status'],
            'retry_after' => $response['retry_after'],
            'deleted'     => (int)($body['deleted'] ?? 0),
            'error'       => $response['error'] ?: ($valid ? '' : 'invalid delete response'),
        ];
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
        return $this->delete_links_result($source_id, $source_type)['ok'];
    }

    /**
     * @return array{ok:bool,retryable:bool,status:int,retry_after:int|null,error:string}
     */
    public function delete_links_result(int $source_id, int $source_type = 0): array
    {
        $response = $this->request_result(
            'DELETE',
            "/v1/links/source/{$source_id}?source_type={$source_type}"
        );

        return [
            'ok'          => $response['ok'],
            'retryable'   => self::is_retryable_result($response),
            'status'      => $response['status'],
            'retry_after' => $response['retry_after'],
            'error'       => $response['error'],
        ];
    }

    /**
     * Wipe all vectors for this client from Qdrant. Full re-index required after.
     */
    public function delete_all_documents(): bool
    {
        $response = $this->request('DELETE', '/v1/documents/all');
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
        $response = $this->request('GET', '/v1/links/incoming?target_url=' . \rawurlencode($target_url));
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
    public function get_links_count(): ?int
    {
        $response = $this->request('GET', '/v1/links/count');
        if ($response === null) {
            return null;
        }
        return (int)($response['count'] ?? 0);
    }

    /**
     * Full link-graph health analysis — powers "Поиск проблем с перелинковкой".
     */
    public function get_link_problems(): ?array
    {
        return $this->request('GET', '/v1/links/problems', [], 120);
    }

    // ── Error reporting ─────────────────────────────────────────────────────

    /**
     * Push an error/warning event to the API log.
     * Non-blocking best-effort — failures are silently ignored.
     */
    public function log_error(string $message, string $context = 'php_index', int $post_id = 0, string $severity = 'error', string $detail = ''): void
    {
        $body = \array_filter([
            'context'  => $context,
            'message'  => $message,
            'severity' => $severity,
            'post_id'  => $post_id ?: null,
            'detail'   => $detail ?: null,
        ]);
        $this->request('POST', '/v1/logs', $body, 5);
    }

    // ── Connectivity diagnostics ──────────────────────────────────────────────

    /** Explicit diagnostics: at most three two-second probes, no redirects. */
    public function diagnostics(): array
    {
        $deadline = ($this->clock)() + 6.0;
        $health = $this->probe('GET', '/health', false, 2, $deadline);
        $skipped = ['ok' => false, 'ms' => 0, 'http_code' => 0,
                    'error' => 'not checked: server unavailable', 'body' => null];
        return [
            'generated_at'   => \gmdate('c'),
            'api_url'        => $this->base_url,
            'site_domain'    => $this->domain,
            'has_key'        => $this->license_key !== '',
            'read_timeout_s' => 5,
            'probe_timeout_s' => 2,
            'budget_s'      => 6,
            'probes'        => [
                'health' => $health,
                'documents_count' => $health['ok']
                    ? $this->probe('GET', '/v1/documents/count', true, 2, $deadline) : $skipped,
                'links_count' => $health['ok']
                    ? $this->probe('GET', '/v1/links/count', true, 2, $deadline) : $skipped,
            ],
        ];
    }

    private function probe(string $method, string $path, bool $auth, int $timeout, float $deadline): array
    {
        $remaining = $deadline - ($this->clock)();
        if ($remaining <= 0 || ($auth && $this->license_key === '')) {
            return ['ok' => false, 'ms' => 0, 'http_code' => 0,
                    'error' => $remaining <= 0 ? 'request budget exhausted' : 'no license key configured', 'body' => null];
        }
        $timeout = \min($timeout, $remaining);
        $args = ['method' => $method, 'timeout' => $timeout, 'redirection' => 0, 'headers' => ['Content-Type' => 'application/json']];
        if ($auth) {
            $args['headers']['X-License-Key'] = $this->license_key;
            $args['headers']['X-Site-Domain'] = $this->domain;
        }
        $start    = \microtime(true);
        $response = \wp_remote_request($this->base_url . $path, $args);
        $ms       = (int) \round((\microtime(true) - $start) * 1000);

        if (\is_wp_error($response)) {
            return ['ok' => false, 'ms' => $ms, 'http_code' => 0, 'error' => $response->get_error_message(), 'body' => null];
        }
        $code    = (int) \wp_remote_retrieve_response_code($response);
        $raw     = \wp_remote_retrieve_body($response);
        $decoded = \json_decode($raw, true);
        $ok      = $code >= 200 && $code < 300;
        return [
            'ok'        => $ok,
            'ms'        => $ms,
            'http_code' => $code,
            'error'     => $ok ? '' : ('HTTP ' . $code),
            'body'      => \is_array($decoded) ? $decoded : (\strlen($raw) > 300 ? \substr($raw, 0, 300) . '…' : $raw),
        ];
    }

    // ── HTTP ────────────────────────────────────────────────────────────────

    /**
     * Execute an authenticated API request and retain transport metadata.
     *
     * The compatibility methods above intentionally continue to expose the old
     * array|null contract. New background work can use this result to distinguish
     * a transport failure from an HTTP response and to honor Retry-After without
     * parsing WordPress' response object a second time.
     *
     * @param array<string,mixed>  $body
     * @param array<string,string> $extra_headers
     * @return array{ok:bool,status:int,headers:array<string,string>,body:array|string|null,error:string,retry_after:int|null}
     */
    public function request_result(
        string $method,
        string $path,
        array $body = [],
        int $timeout = 0,
        array $extra_headers = []
    ): array {
        if (empty($this->license_key)) {
            $this->logger->log('CherryVectorAPI: no license key configured');
            return [
                'ok'          => false,
                'status'      => 0,
                'headers'     => [],
                'body'        => null,
                'error'       => 'no license key configured',
                'retry_after' => null,
            ];
        }

        if ($timeout === 0) {
            // Plain substr/strpos (not str_ends_with/str_contains) — those are PHP 8.0+
            // and the plugin supports PHP 7.4.
            $is_batch  = \substr($path, -6) === '/batch'
                || \substr($path, -4) === '/all'
                || \strpos($path, '/parse') !== false;
            $is_search = \substr($path, -7) === '/search'
                || \substr($path, -9) === '/problems'
                || \substr($path, -6) === '/stats';
            $timeout   = $is_batch ? 120 : ($is_search ? 60 : 15);
        }
        if ($this->context !== self::CONTEXT_BACKGROUND) {
            $now = ($this->clock)();
            if ($this->deadline === null) {
                $this->deadline = $now + ($this->context === self::CONTEXT_STATUS ? 4.0 : 8.0);
            }
            $remaining = $this->deadline - $now;
            if ($remaining <= 0) {
                return ['ok' => false, 'status' => 0, 'headers' => [], 'body' => null,
                        'error' => 'request budget exhausted', 'retry_after' => null];
            }
            $timeout = \min($timeout, $this->context === self::CONTEXT_STATUS ? 2 : 5, $remaining);
        }
        $headers = [
            'Content-Type'  => 'application/json',
            'X-License-Key' => $this->license_key,
            'X-Site-Domain' => $this->domain,
        ];
        foreach ($extra_headers as $name => $value) {
            $headers[$name] = $value;
        }

        $args = [
            'method'  => $method,
            'timeout' => $timeout,
            'redirection' => 0,
            'headers' => $headers,
        ];

        if (!empty($body)) {
            $args['body'] = \wp_json_encode($body);
        }

        $response = \wp_remote_request($this->base_url . $path, $args);

        if (\is_wp_error($response)) {
            $error = $response->get_error_message();
            $this->logger->log('CherryVectorAPI error: ' . $error);
            return [
                'ok'          => false,
                'status'      => 0,
                'headers'     => [],
                'body'        => null,
                'error'       => $error,
                'retry_after' => null,
            ];
        }

        $code       = (int) \wp_remote_retrieve_response_code($response);
        $raw_headers = \wp_remote_retrieve_headers($response);
        $response_headers = [];
        if (\is_object($raw_headers) && \method_exists($raw_headers, 'getAll')) {
            $raw_headers = $raw_headers->getAll();
        }
        if (\is_iterable($raw_headers)) {
            foreach ($raw_headers as $name => $value) {
                $response_headers[\strtolower((string) $name)] = \is_scalar($value)
                    ? (string) $value
                    : \implode(', ', \array_map('strval', (array) $value));
            }
        }

        $raw     = \wp_remote_retrieve_body($response);
        $decoded = \json_decode($raw, true);
        $body_response = \is_array($decoded) ? $decoded : ($raw === '' ? [] : $raw);
        $ok = $code >= 200 && $code < 300;
        if (!$ok) {
            $this->logger->log("CherryVectorAPI HTTP {$code} on {$method} {$path}");
        }

        return [
            'ok'          => $ok,
            'status'      => $code,
            'headers'     => $response_headers,
            'body'        => $body_response,
            'error'       => $ok ? '' : ('HTTP ' . $code),
            'retry_after' => self::parse_retry_after($response_headers['retry-after'] ?? null),
        ];
    }

    /**
     * Parse either the seconds or HTTP-date form of Retry-After.
     *
     * @param string|null $value
     */
    public static function parse_retry_after(?string $value, ?int $now = null): ?int
    {
        if ($value === null) {
            return null;
        }
        $value = \trim($value);
        if ($value === '' || \preg_match('/^-\d+$/', $value)) {
            return null;
        }
        if (!\preg_match('/^\d+$/', $value)) {
            $timestamp = \strtotime($value);
            if ($timestamp === false) {
                return null;
            }
            $now = $now ?? \time();
            return \max(0, $timestamp - $now);
        }

        return (int) $value;
    }

    /**
     * Transport/status failures that are safe for the current idempotent batch to retry.
     *
     * @param array{status?:int,error?:string} $result
     */
    public static function is_retryable_result(array $result): bool
    {
        $status = (int)($result['status'] ?? 0);
        if ($status === 0) {
            return ($result['error'] ?? '') !== 'no license key configured';
        }

        return $status === 408 || $status === 429 || $status >= 500;
    }

    /**
     * @param array<string,mixed> $body
     * @return array|null
     */
    private function request(string $method, string $path, array $body = [], int $timeout = 0): ?array
    {
        $result = $this->request_result($method, $path, $body, $timeout);
        if (!$result['ok']) {
            return null;
        }

        return \is_array($result['body']) ? $result['body'] : [];
    }
}
