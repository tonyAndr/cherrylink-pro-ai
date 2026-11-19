<?php

declare(strict_types=1);

namespace CherryLink\Support;

use CherryLink\Api\Client;
use CherryLink\Contracts\Registerable;
use CherryLink\Indexing\IndexHooks;

/**
 * "Connect" flow: obtains a free, domain-bound key from the licensing authority.
 *
 * This is the only outbound request the plugin makes without a stored key, and it
 * runs only when an administrator presses Connect. Domain proof: the plugin stores a
 * random nonce in a short-lived transient and exposes it at a public REST route; the
 * authority fetches it over HTTPS. The route answers 404 unless a connect is pending.
 */
final class Connect implements Registerable
{
    public const NONCE_TRANSIENT = 'cherrylink_connect_nonce';
    public const NONCE_TTL       = 600;
    public const ACTION          = 'cherrylink_connect';
    public const PROOF_ACTION    = 'cherrylink_connect_proof';

    /** @var License */
    private $license;

    public function __construct(License $license)
    {
        $this->license = $license;
    }

    public function register(): void
    {
        \add_action('rest_api_init', [$this, 'registerRoute']);
        \add_action('admin_post_' . self::ACTION, [$this, 'handleAdminPost']);
        // Same proof for sites where the REST API is blocked or unreachable (plain permalinks,
        // security plugins). Public on purpose, like the REST route.
        \add_action('wp_ajax_nopriv_' . self::PROOF_ACTION, [$this, 'handleAjaxProof']);
        \add_action('wp_ajax_' . self::PROOF_ACTION, [$this, 'handleAjaxProof']);
    }

    public function registerRoute(): void
    {
        \register_rest_route('cherrylink/v1', '/connect-proof', [
            'methods'             => 'GET',
            'callback'            => [$this, 'proof'],
            // Public on purpose: the authority fetches the nonce without credentials.
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * @return array{nonce:string}|\WP_Error
     */
    public function proof()
    {
        $nonce = \get_transient(self::NONCE_TRANSIENT);
        if (! \is_string($nonce) || $nonce === '') {
            return new \WP_Error('cherrylink_no_connect', 'Not found', ['status' => 404]);
        }

        return ['nonce' => $nonce];
    }

    /** admin-ajax twin of the REST proof route: `{"nonce":...}` while a connect is pending, else 404. */
    public function handleAjaxProof(): void
    {
        $proof = $this->proof();
        if ($proof instanceof \WP_Error) {
            \wp_send_json(['code' => 'cherrylink_no_connect'], 404);
        } else {
            \wp_send_json($proof);
        }
    }

    /** Dashboard URL (the setup wizard lives there until connected). */
    public static function settingsUrl(): string
    {
        return \admin_url('admin.php?page=cherrylink');
    }

    /** admin-post handler: nonce + capability, run connect, redirect back with the outcome. */
    public function handleAdminPost(): void
    {
        if (! \current_user_can('cherrylink_settings')) {
            \wp_die(\esc_html__('Insufficient permissions.', 'cherrylink-ai'), '', ['response' => 403]);
        }
        \check_admin_referer(self::ACTION);

        $result = $this->connect();
        $args   = ['cherrylink_result' => $result['ok'] ? 'ok' : $result['code']];
        if (! empty($result['retry_after'])) {
            $args['cherrylink_retry'] = (int) $result['retry_after'];
        }
        \wp_safe_redirect(\add_query_arg($args, self::settingsUrl()));
        exit;
    }

    /**
     * Run the connect round trip.
     *
     * @return array{ok:bool,code:string,retry_after:int|null}
     */
    public function connect(): array
    {
        $domain = \sanitize_text_field((string) \wp_parse_url(\home_url(), PHP_URL_HOST));
        if ($domain === '') {
            return ['ok' => false, 'code' => 'invalid_domain', 'retry_after' => null];
        }

        // https only (the authority refuses anything else); omit the field when none remain.
        $proof_urls = \array_values(\array_filter([
            \rest_url('cherrylink/v1/connect-proof'),
            \admin_url('admin-ajax.php?action=' . self::PROOF_ACTION),
        ], static function ($url): bool {
            return \is_string($url) && \strpos($url, 'https://') === 0;
        }));

        $nonce = \wp_generate_password(40, false);
        \set_transient(self::NONCE_TRANSIENT, $nonce, self::NONCE_TTL);

        try {
            $response = \wp_remote_post(License::licensingUrl() . 'connect', [
                'timeout'     => 20, // the authority fetches the proof (<= 5 s) before answering
                'redirection' => 0,
                'headers'     => ['Content-Type' => 'application/json'],
                'body'        => \wp_json_encode(\array_filter(
                    ['domain' => $domain, 'nonce' => $nonce, 'proof_urls' => $proof_urls]
                )),
            ]);
        } finally {
            \delete_transient(self::NONCE_TRANSIENT);
        }

        if (\is_wp_error($response)) {
            return ['ok' => false, 'code' => 'unavailable', 'retry_after' => null];
        }

        $status  = (int) \wp_remote_retrieve_response_code($response);
        $decoded = \json_decode((string) \wp_remote_retrieve_body($response), true);
        $decoded = \is_array($decoded) ? $decoded : [];

        if ($status === 200) {
            $key = isset($decoded['license_key']) && \is_string($decoded['license_key']) ? \trim($decoded['license_key']) : '';
            if ($key === '') {
                return ['ok' => false, 'code' => 'unavailable', 'retry_after' => null];
            }
            $this->license->storeConnected($key, $decoded);
            IndexHooks::clearStatusCaches();
            $capabilities = new Capabilities();
            $capabilities->forget();
            Capabilities::refreshSoon(); // warm from cron, never from this request

            return ['ok' => true, 'code' => 'ok', 'retry_after' => null];
        }

        $detail = isset($decoded['detail']) && \is_string($decoded['detail']) ? $decoded['detail'] : '';
        if ($status === 429) {
            $retry = Client::parse_retry_after((string) \wp_remote_retrieve_header($response, 'retry-after'));

            return ['ok' => false, 'code' => 'rate_limited', 'retry_after' => $retry];
        }
        if ($status === 409 && \in_array($detail, ['domain_proof_failed', 'subscription_not_eligible'], true)) {
            return ['ok' => false, 'code' => $detail, 'retry_after' => null];
        }
        if ($status === 422) {
            return ['ok' => false, 'code' => 'invalid_domain', 'retry_after' => null];
        }

        return ['ok' => false, 'code' => 'unavailable', 'retry_after' => null];
    }
}
