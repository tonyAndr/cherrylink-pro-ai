<?php

declare(strict_types=1);

namespace CherryLink\Ajax;

/**
 * Shared security envelope for the plugin's admin-ajax handlers.
 *
 * Every CherryLink AJAX action used to run with no nonce verification (and some with
 * no capability check either). verify() is called as the first line of each handler:
 * it validates the shared nonce and the caller's capability, dying the WP-standard way
 * on failure so the handler body can assume an authorized request.
 *
 * The class-per-handler extraction is deferred to Stage 6 (when each handler's domain
 * logic moves into its owning service); this keeps Stage 4 a focused security pass.
 */
final class Security
{
    /**
     * One shared nonce action for all plugin AJAX. The Gutenberg sidebar already
     * localizes wp_create_nonce('cherry_nonce'); reusing it keeps a single token.
     */
    public const NONCE = 'cherry_nonce';

    /**
     * Verify the request nonce and the caller's capability. On a bad/missing nonce
     * check_ajax_referer() emits the standard "-1"/403 and dies; on an insufficient
     * capability we send a 403 JSON error and exit. Returns only for authorized calls.
     *
     * The nonce is read from $_REQUEST['_ajax_nonce'] (default of check_ajax_referer),
     * so JS callers must send that field — appended to the query string for the
     * JSON-body Gutenberg fetches, or as a data field for the jQuery calls.
     */
    public static function verify(string $capability = 'edit_posts'): void
    {
        \check_ajax_referer(self::NONCE);

        if (!\current_user_can($capability)) {
            \wp_send_json_error(['message' => 'forbidden'], 403);
        }
    }

    /** Mint the shared nonce for localizing into a script. */
    public static function nonce(): string
    {
        return \wp_create_nonce(self::NONCE);
    }
}
