<?php

declare(strict_types=1);

namespace CherryLink\Api;

use CherryLink\Contracts\Registerable;

/**
 * API runtime configuration + the alternative-route connect hook.
 *
 * Defines the API constants (URL: wp-config.php override → saved setting → dev default;
 * plus the relay port/IP) and registers a single http_api_curl choke point that — for
 * our relay-port requests only — overrides the connect address to the relay IP while
 * keeping the real hostname (so SNI / Host / certificate validation are unchanged; the
 * relay just pipes encrypted bytes to the API). Used when the primary API IP is blocked
 * upstream (e.g. RKN). License → seocherry.ru, the GitHub updater, etc. are never touched.
 *
 * Ported from the legacy cherrylink_vector_api.php (now deleted).
 */
final class Relay implements Registerable
{
    /** Relay TCP port (transport-only TLS passthrough). */
    private const ALT_PORT = 9443;

    /** Relay IP the connect address is redirected to. */
    private const ALT_IP = '31.129.48.202';

    /**
     * Define the API runtime constants once, respecting any wp-config.php overrides.
     * Called from the bootstrap before any Api\Client is constructed.
     */
    public static function defineConstants(): void
    {
        if (! \defined('CHERRY_VECTOR_API_URL')) {
            $options = \get_option('linkate-posts', []);
            $url     = ! empty($options['vector_api_url']) ? $options['vector_api_url'] : 'https://seocherry.apov.dev';
            \define('CHERRY_VECTOR_API_URL', $url);
        }
        if (! \defined('CL_ALT_API_PORT')) {
            \define('CL_ALT_API_PORT', self::ALT_PORT);
        }
        if (! \defined('CL_ALT_API_IP')) {
            \define('CL_ALT_API_IP', self::ALT_IP);
        }
    }

    public function register(): void
    {
        \add_action('http_api_curl', [$this, 'resolveAltRoute'], 10, 3);
    }

    /**
     * For our relay-port requests only, pin the connect address to the relay IP while
     * keeping the real hostname on the request.
     *
     * @param resource            $handle cURL handle
     * @param array<string,mixed> $args   request args
     * @param string              $url    request URL
     */
    public function resolveAltRoute($handle, $args, $url): void
    {
        if (! Client::alt_enabled()) {
            return;
        }
        $parts = \wp_parse_url($url);
        if (empty($parts['host']) || (int) ($parts['port'] ?? 0) !== CL_ALT_API_PORT) {
            return;
        }
        \curl_setopt($handle, CURLOPT_RESOLVE, [$parts['host'] . ':' . CL_ALT_API_PORT . ':' . CL_ALT_API_IP]);
    }
}
