<?php
/**
 * Point the plugin at the local API. The API URL is a wp-config constant or a saved
 * setting (constant wins); we set the setting so later requests pick it up.
 */

use CherryLink\Support\Options;

$url = (string) getenv('E2E_API_URL');
$key = (string) getenv('E2E_API_KEY');
if ($url === '' || $key === '') {
    WP_CLI::error('E2E_API_URL and E2E_API_KEY are required');
}

$options                 = (new Options())->main();
$options['vector_api_url'] = $url;
$options['vector_api_key'] = $key;
(new Options())->saveMain($options);

// Mark the plugin licence active so the indexing services permit work. This is a
// local fixture only: no licensing authority is contacted and no real key is used.
$meta = (new Options())->meta();
$meta['key_valid']  = true;
$meta['expires_at'] = gmdate('Y-m-d H:i:s', time() + 86400);
$meta['key_error_reason'] = '';
(new Options())->saveMeta($meta);

WP_CLI::log('configured api=' . $url);
