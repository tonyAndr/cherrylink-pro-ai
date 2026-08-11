<?php
/*
 * CherryLink — API runtime bootstrap.
 * The API client moved to \CherryLink\Api\Client (src/Api/Client.php).
 *
 * This file stays as the runtime home for the API constants (derived from saved
 * options at include time) and the alternative-route connect hook. (Slated to move
 * into the Api\Client bootstrap in a later refactor step.)
 */

defined('ABSPATH') || exit;

// URL priority: wp-config.php constant → admin settings → local dev default
if (!defined('CHERRY_VECTOR_API_URL')) {
    $options = get_option('linkate-posts', []);
    $saved_url = !empty($options['vector_api_url']) ? $options['vector_api_url'] : 'https://seocherry.apov.dev';
    define('CHERRY_VECTOR_API_URL', $saved_url);
}

// Alternative connection endpoint — a transport-only TCP relay used when the primary API IP
// is blocked upstream (e.g. RKN). We keep the real hostname on the request and only override
// the connect address (via the http_api_curl resolve hook below), so end-to-end TLS and
// certificate validation are unchanged — the relay just pipes encrypted bytes to the API.
if (!defined('CL_ALT_API_PORT')) define('CL_ALT_API_PORT', 9443);
if (!defined('CL_ALT_API_IP'))   define('CL_ALT_API_IP', '31.129.48.202');

// Single choke point for the alternative route: for our relay-port requests only, override the
// connect address to the relay IP while keeping the real hostname (SNI/Host/cert unchanged).
// Other requests (license → seocherry.ru, GitHub updater, etc.) are never touched.
add_action('http_api_curl', function ($handle, $args, $url) {
    if (!\CherryLink\Api\Client::alt_enabled()) {
        return;
    }
    $parts = wp_parse_url($url);
    if (empty($parts['host']) || (int)($parts['port'] ?? 0) !== CL_ALT_API_PORT) {
        return;
    }
    curl_setopt($handle, CURLOPT_RESOLVE, array($parts['host'] . ':' . CL_ALT_API_PORT . ':' . CL_ALT_API_IP));
}, 10, 3);
