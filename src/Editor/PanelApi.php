<?php

declare(strict_types=1);

namespace CherryLink\Editor;

use CherryLink\Ajax\Input;
use CherryLink\Ajax\Security;
use CherryLink\Api\Client;
use CherryLink\Contracts\Registerable;
use CherryLink\Support\KeyPages;
use CherryLink\Support\License;
use CherryLink\Support\Options;
use CherryLink\Terms\TermsService;

/**
 * AJAX backend of the unified editor panel (specs/unified-panel.md section 4).
 *
 * Every action: Security::verify('edit_posts') + a per-object capability check, JSON
 * body (php://input) with $_POST fallback (every field sanitized by type, see FIELDS),
 * JSON reply `{ok:true,...}` or `{ok:false,error,message}`.
 *
 * All actions are registered for every administrator-side request, connected or not.
 * Actions that need the CherryLink service (links, incoming, donors, key_pin) answer
 * `not_connected` until the site is connected; the local ones (terms, donor_insert,
 * donor_undo) work without a connection.
 */
final class PanelApi implements Registerable
{
    private const UNDO_TTL = 3600;

    /** Request fields the panel may send, by sanitization type (see Ajax\Input). */
    private const FIELDS = [
        'post_id'     => 'uint',
        'object_id'   => 'uint',
        'donor_id'    => 'uint',
        'target_id'   => 'uint',
        'block_index' => 'int',
        'offset'      => 'uint',
        'is_term'     => 'bool',
        'query'       => 'text',
        'category'    => 'text',
        'taxonomy'    => 'key',
        'type'        => 'key',
        'mode'        => 'key',
        'phrase'      => 'text',
        'undo_token'  => 'key',
        'source_html' => 'html',
    ];

    /** @var Options */
    private $options;

    /** @var callable|null returns the raw request body */
    private $body_reader;

    /** @var callable|null returns an Api\Client */
    private $client_factory;

    /** @var callable|null returns whether the site is connected to the service */
    private $connected;

    public function __construct(?Options $options = null, ?callable $body_reader = null, ?callable $client_factory = null, ?callable $connected = null)
    {
        $this->options        = $options ?? new Options();
        $this->body_reader    = $body_reader;
        $this->client_factory = $client_factory;
        $this->connected      = $connected;
    }

    public function register(): void
    {
        if (! \is_admin()) {
            return;
        }
        foreach (['links', 'terms', 'incoming', 'donors', 'donor_insert', 'donor_undo', 'key_pin'] as $action) {
            \add_action('wp_ajax_cherrylink_panel_' . $action, [$this, 'ajax' . \str_replace('_', '', \ucwords($action, '_'))]);
        }
    }

    // ── Actions ─────────────────────────────────────────────────────────────────

    public function ajaxLinks(): void
    {
        $in      = $this->start(true);
        $id      = (int) ($in['post_id'] ?? 0);
        $is_term = ! empty($in['is_term']);
        if (! $this->canEditObject($id, $is_term)) {
            $this->fail('forbidden', 'Not allowed', 403);
            return;
        }
        $data = SuggestionsResults::rows([
            'post_id'     => $id,
            'is_term'     => $is_term,
            // Live editor HTML for the API's block extractor, already run through wp_kses_post().
            'source_html' => (string) ($in['source_html'] ?? ''),
            'query'       => (string) ($in['query'] ?? ''),
            'category'    => (string) ($in['category'] ?? ''),
            'offset'      => (int) ($in['offset'] ?? 0),
            'taxonomy'    => (string) ($in['taxonomy'] ?? ''),
        ]);
        $this->ok($data);
    }

    public function ajaxTerms(): void
    {
        $this->start();
        $main = $this->options->main();
        $this->ok(['items' => $this->decorateTerms(TermsService::gutenbergTree(), $main)]);
    }

    public function ajaxIncoming(): void
    {
        $in   = $this->start(true);
        $id   = (int) ($in['object_id'] ?? 0);
        $type = (string) ($in['type'] ?? 'post');
        $term = $type === 'term';
        if ($id < 1 || ! $this->canEditObject($id, $term)) {
            $this->fail('forbidden', 'Not allowed', 403);
            return;
        }
        $url = $term ? \get_term_link($id) : \get_permalink($id);
        if (\is_wp_error($url) || empty($url)) {
            $this->ok(['items' => []]);
            return;
        }
        $response = $this->client()->get_incoming_links((string) $url);
        $items    = [];
        foreach ((array) ($response['links'] ?? []) as $link) {
            $source_url = (string) ($link['source_url'] ?? '');
            $source_id  = (int) ($link['source_id'] ?? 0);
            if ($source_id < 1 && $source_url !== '') {
                $source_id = (int) \url_to_postid($source_url);
            }
            $title = $source_id ? (string) \get_the_title($source_id) : '';
            $edit  = $source_id ? (string) \get_edit_post_link($source_id, 'raw') : '';
            $items[] = [
                'source_id'    => $source_id,
                'source_title' => $title !== '' ? \html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $source_url,
                'source_url'   => $source_url,
                'edit_url'     => $edit,
                'anchor'       => (string) ($link['anchor_text'] ?? ''),
            ];
        }
        $this->ok(['items' => $items]);
    }

    public function ajaxDonors(): void
    {
        $in = $this->start(true);
        $id = (int) ($in['post_id'] ?? 0);
        if ($id < 1 || ! \current_user_can('edit_post', $id)) {
            $this->fail('forbidden', 'Not allowed', 403);
            return;
        }
        $this->ok(['items' => (new DonorFinder($this->client()))->find($id)]);
    }

    public function ajaxDonorInsert(): void
    {
        $in        = $this->start();
        $donor_id  = (int) ($in['donor_id'] ?? 0);
        $target_id = (int) ($in['target_id'] ?? 0);
        $phrase    = (string) ($in['phrase'] ?? '');
        $block     = isset($in['block_index']) ? (int) $in['block_index'] : -1;

        $donor  = $donor_id ? \get_post($donor_id) : null;
        $target = $target_id ? \get_post($target_id) : null;
        if (! $donor || ! $target || $phrase === '' || \strlen($phrase) > 300) {
            $this->fail('bad_request', 'Invalid request', 400);
            return;
        }
        if (! \current_user_can('edit_post', $donor_id) || ! \current_user_can('edit_post', $target_id)) {
            $this->fail('forbidden', 'Not allowed', 403);
            return;
        }
        if ($donor->post_status !== 'publish') {
            $this->fail('not_published', 'Donor is not published', 409);
            return;
        }
        $lock = \wp_check_post_lock($donor_id);
        if ($lock) {
            $this->fail('locked', 'Donor is being edited by another user', 409);
            return;
        }

        $content = (string) $donor->post_content;
        $target_url = (string) \get_permalink($target_id);
        if (ContentLinker::linksTo($content, $target_url)) {
            $this->fail('already_linked', 'Donor already links to this post', 409);
            return;
        }

        $markup = $this->markupFor($target);
        $new    = ContentLinker::link($content, $phrase, $block, $markup['open'], $markup['close']);
        if ($new === null) {
            $this->fail('phrase_not_found', 'Phrase not found', 422);
            return;
        }

        $saved = \wp_update_post(['ID' => $donor_id, 'post_content' => \wp_slash($new)], true);
        if (\is_wp_error($saved)) {
            $this->fail('save_failed', 'Could not save the donor', 500);
            return;
        }

        $stored = \get_post($donor_id);
        $hash   = \md5($stored ? (string) $stored->post_content : $new);
        $token  = \strtolower(\wp_generate_password(24, false)); // lower-case: the request field goes through sanitize_key()
        \set_transient($this->undoKey($donor_id, $token), ['previous_content' => $content, 'new_hash' => $hash], self::UNDO_TTL);

        $this->ok(['undo_token' => $token]);
    }

    public function ajaxDonorUndo(): void
    {
        $in       = $this->start();
        $donor_id = (int) ($in['donor_id'] ?? 0);
        $token    = (string) ($in['undo_token'] ?? ''); // sanitize_key lower-cases; tokens are generated lower-case
        if ($donor_id < 1 || \preg_match('/^[a-z0-9]{8,64}$/', $token) !== 1) {
            $this->fail('bad_request', 'Invalid request', 400);
            return;
        }
        if (! \current_user_can('edit_post', $donor_id)) {
            $this->fail('forbidden', 'Not allowed', 403);
            return;
        }
        if (\wp_check_post_lock($donor_id)) {
            $this->fail('locked', 'Donor is being edited by another user', 409);
            return;
        }
        $key   = $this->undoKey($donor_id, $token);
        $saved = \get_transient($key);
        if (! \is_array($saved) || ! isset($saved['previous_content'], $saved['new_hash'])) {
            $this->fail('expired', 'Undo is no longer available', 410);
            return;
        }
        $donor = \get_post($donor_id);
        if (! $donor || \md5((string) $donor->post_content) !== $saved['new_hash']) {
            $this->fail('changed', 'The article was changed after the link was added', 409);
            return;
        }
        $res = \wp_update_post(['ID' => $donor_id, 'post_content' => \wp_slash((string) $saved['previous_content'])], true);
        if (\is_wp_error($res)) {
            $this->fail('save_failed', 'Could not restore the donor', 500);
            return;
        }
        \delete_transient($key);
        $this->ok([]);
    }

    /** Mark / unmark the post being edited as a key page (pins are site-wide: edit_others_posts). */
    public function ajaxKeyPin(): void
    {
        $in   = $this->start(true);
        $id   = (int) ($in['post_id'] ?? 0);
        $mode = (string) ($in['mode'] ?? '');
        if ($id < 1 || ! \current_user_can('edit_post', $id) || ! \current_user_can('edit_others_posts')) {
            $this->fail('forbidden', 'Not allowed', 403);
            return;
        }
        $keys = new KeyPages($this->client_factory ? ($this->client_factory)() : new Client(null, Client::CONTEXT_BACKGROUND));
        if (! $keys->setMode($id, $mode)) {
            $this->fail('bad_request', 'Invalid request', 400);
            return;
        }
        $keys->refresh();
        $this->ok(['keyPage' => $keys->forPost($id)]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────────

    /**
     * Verify nonce + capability, then read the request body (JSON, else $_POST) through the
     * FIELDS whitelist so every value is sanitized by type. With $needs_service, a site that is
     * not connected gets the clean `not_connected` error instead (and the call ends here).
     *
     * @return array<string,mixed>
     */
    private function start(bool $needs_service = false): array
    {
        Security::verify('edit_posts');
        if ($needs_service && ! $this->isConnected()) {
            $this->fail('not_connected', \__('Connect CherryLink to get suggestions.', 'cherrylink-ai'), 403);
            return [];
        }
        $raw  = $this->body_reader ? (string) ($this->body_reader)() : (string) \file_get_contents('php://input');
        $data = \json_decode($raw, true);
        if (! \is_array($data)) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by Security::verify() above; every field is sanitized by Input::fields()
            $data = (array) \wp_unslash($_POST);
        }
        return Input::fields($data, self::FIELDS);
    }

    private function isConnected(): bool
    {
        if ($this->connected !== null) {
            return (bool) ($this->connected)();
        }
        return (new License($this->options))->isConnected();
    }

    private function canEditObject(int $id, bool $is_term): bool
    {
        if ($id < 1) {
            return false;
        }
        return (bool) \current_user_can($is_term ? 'edit_term' : 'edit_post', $id);
    }

    private function client(): Client
    {
        return $this->client_factory ? ($this->client_factory)() : new Client();
    }

    /**
     * Link markup for a donor (same builder for classic and block-serialized content).
     *
     * @param \WP_Post|\stdClass $target
     * @return array{open:string,close:string}
     */
    private function markupFor($target): array
    {
        $result     = new \stdClass();
        $result->ID = (int) $target->ID;
        $title      = \html_entity_decode((string) \get_the_title($result->ID), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return LinkMarkup::build(LinkFields::url(Options::MAIN, $result), $title, $this->options->main());
    }

    private function undoKey(int $donor_id, string $token): string
    {
        return 'cherrylink_undo_' . $donor_id . '_' . $token;
    }

    /**
     * @param array<int,array<string,mixed>> $nodes
     * @param array<string,mixed>            $main
     * @return array<int,array<string,mixed>>
     */
    private function decorateTerms(array $nodes, array $main): array
    {
        $out = [];
        foreach ($nodes as $n) {
            if (($n['is_divider'] ?? 'no') === 'yes') {
                $out[] = ['is_divider' => true, 'title' => (string) ($n['name'] ?? '')];
                continue;
            }
            $url    = (string) ($n['url'] ?? '');
            $title  = \html_entity_decode((string) ($n['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $markup = LinkMarkup::build($url, $title, $main);
            $out[]  = [
                'is_divider'  => false,
                'title'       => $title,
                'url'         => $url,
                'taxonomy'    => (string) ($n['taxonomy'] ?? ''),
                'post_count'  => (int) ($n['post_count'] ?? 0),
                'open'        => $markup['open'],
                'close'       => $markup['close'],
                'children'    => $this->decorateTerms((array) ($n['children'] ?? []), $main),
            ];
        }
        return $out;
    }

    /** @param array<string,mixed> $data */
    private function ok(array $data): void
    {
        \wp_send_json(['ok' => true] + $data);
    }

    private function fail(string $code, string $message, int $status): void
    {
        \wp_send_json(['ok' => false, 'error' => $code, 'message' => $message], $status);
    }
}
