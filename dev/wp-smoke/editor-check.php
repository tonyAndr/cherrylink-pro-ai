<?php
/**
 * Editor-path smoke: the AJAX request the block/classic sidebar makes on load.
 *
 * Unit tests cannot catch this class of bug: the fatal came from a strict_types
 * TypeError on a real option value, and the empty list from an API limit the
 * mocks never validate. Both are only visible with WordPress + real options.
 *
 * Run through `wp eval-file` (see run.sh).
 */

use CherryLink\Api\Client;
use CherryLink\CustomFields\Cf;
use CherryLink\Editor\SuggestionsResults;

function smoke_check(string $label, bool $ok, string $detail = ''): void
{
    if (! $ok) {
        WP_CLI::error('FAILED: ' . $label . ($detail !== '' ? ' — ' . $detail : ''));
    }
    WP_CLI::log('ok  ' . $label . ($detail !== '' ? ' — ' . $detail : ''));
}

// 1. Real stored options must not break the option merge (the PHP 8 fatal).
$options = (array) get_option('linkate-posts', []);
smoke_check('options loaded', $options !== []);
$merged = Cf::setOptions('linkate-posts', []);
smoke_check('Cf::setOptions survives real option types', isset($merged['limit_ajax']));

// 2. The search request must stay inside the API's accepted range.
$limit = (int) ($merged['limit_ajax'] ?? 50);
$api   = new Client(null, Client::CONTEXT_BACKGROUND);
$raw   = $api->request_result('POST', '/v1/search', [
    'query' => 'smoke query',
    'limit' => max(1, min(Client::SEARCH_LIMIT_MAX, $limit * 3)),
]);
$status = (int) $raw['status'];
if ($status === 0) {
    WP_CLI::log('skip  search request — no API configured in this harness');
} else {
    smoke_check('search request accepted by the API (limit within range)', $status !== 422, 'status=' . $status);
}

// 3. The full editor pipeline returns a result array (no fatal, valid shape).
$post_id = (int) (get_posts(['numberposts' => 1, 'post_status' => 'publish', 'fields' => 'ids'])[0] ?? 0);
smoke_check('a published post exists', $post_id > 0);
$data = SuggestionsResults::rows(['post_id' => $post_id]);
smoke_check(
    'editor pipeline returns the expected shape without a fatal',
    \is_array($data['items'] ?? null) && \is_bool($data['has_more'] ?? null),
    'items=' . \count((array) ($data['items'] ?? []))
);
if (! $data['items']) {
    WP_CLI::log('note  no rows (the API is not configured here or returned no hits)');
}

// 4. No PHP warnings during the option merge (AJAX has no post loop).
$GLOBALS['wp_the_query'] = (object) ['post' => null, 'posts' => []];
unset($GLOBALS['post']);
smoke_check('currentPostId is null-safe', Cf::currentPostId(0) === false);

WP_CLI::success('editor path smoke passed');
