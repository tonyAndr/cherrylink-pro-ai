<?php

declare(strict_types=1);

namespace CherryLink\Editor;

use CherryLink\Ajax\Security;
use CherryLink\Api\Client;
use CherryLink\Contracts\Registerable;
use CherryLink\Support\Options;
use CherryLink\Support\View;
use CherryLink\Terms\TermsService;

/**
 * The classic (TinyMCE) editor integration: the CherryLink media button + panel,
 * its CSS/JS enqueues, the inline options bridge, and the two suggestion AJAX
 * endpoints (get_linkate_links / get_linkate_suggestions).
 *
 * Ported verbatim from the legacy cherrylink_editor_ui.php (now a shim that keeps
 * linkate_build_source_html()/create_quick_cat_select() as delegators — the former
 * is called by the orphan-donor stats, the latter by the classic-panel template).
 *
 * register() reproduces the old file-scope gate: the panel only wires up in the
 * admin for a licensed site.
 */
final class ClassicPanel implements Registerable
{
    /** @var Options */
    private $options;

    public function __construct(?Options $options = null)
    {
        $this->options = $options ?? new Options();
    }

    public function register(): void
    {
        if (! \is_admin() || empty($this->options->meta()['key_valid'])) {
            return;
        }

        \add_action('admin_head', [$this, 'sendOptionsFrontend']);
        \add_action('wp_ajax_get_linkate_links', [$this, 'ajaxLinks']);
        \add_action('wp_ajax_get_linkate_suggestions', [$this, 'ajaxSuggestions']);
        \add_action('admin_enqueue_scripts', [$this, 'hookTermEdit'], 10);
    }

    // ── AJAX ─────────────────────────────────────────────────────────────────

    /** Relevant-results for the panel (classic $_POST, Gutenberg JSON body). */
    public function ajaxLinks(): void
    {
        Security::verify('edit_posts');

        $body = \json_decode(\file_get_contents('php://input'), true);

        if (\is_array($body)) {
            $post_id     = $body['post_id'] ?? '';
            $is_term     = $body['is_term'] ?? '';
            $offset      = $body['offset'] ?? '';
            $custom_text = $body['custom_text'] ?? '';
            $mode        = 'gutenberg';
        } else {
            // phpcs:disable WordPress.Security.NonceVerification -- gated by Security::verify()
            $post_id     = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
            $is_term     = isset($_POST['is_term']) ? (int) $_POST['is_term'] : 0;
            $offset      = isset($_POST['offset']) ? (int) $_POST['offset'] : 0;
            $custom_text = isset($_POST['custom_text']) ? \sanitize_text_field(\wp_unslash($_POST['custom_text'])) : '';
            // phpcs:enable WordPress.Security.NonceVerification
            $mode = 'classic';
        }

        $data = SuggestionsResults::execute(
            'manual_ID=' . $post_id . '&is_term=' . $is_term . '&offset=' . $offset . '&mode=' . $mode . '&custom_text=' . $custom_text . '&'
        );
        \wp_send_json($data);
    }

    /**
     * On-demand anchor suggestions for one target hit — called when the editor expands a
     * suggestion panel. Source is the post being edited; target is the hit clicked.
     */
    public function ajaxSuggestions(): void
    {
        Security::verify('edit_posts');

        $data = \json_decode(\file_get_contents('php://input'), true);
        // phpcs:disable WordPress.Security.NonceVerification -- gated by Security::verify()
        if (! \is_array($data)) {
            $data = \wp_unslash($_POST);
        }
        // phpcs:enable WordPress.Security.NonceVerification

        $post_id      = isset($data['post_id'])      ? (int) $data['post_id']                     : 0;
        $target_id    = isset($data['target_id'])    ? (int) $data['target_id']                   : 0;
        $target_title = isset($data['target_title']) ? \sanitize_text_field($data['target_title']) : '';
        // source_html is live editor HTML fed to the API's block extractor — kept raw
        // (only unslashed) on purpose; sanitizing would corrupt the markup it parses.
        $source_html  = isset($data['source_html'])  ? (string) $data['source_html']              : '';

        // Prefer live editor HTML if the client sent it; else rebuild from the saved post.
        if ($source_html === '' && $post_id) {
            $post = \get_post($post_id);
            if ($post) {
                $source_html = self::buildSourceHtml($post->post_content);
            }
        }
        if ($target_title === '' && $target_id) {
            $target = \get_post($target_id);
            if ($target) {
                $target_title = $target->post_title;
            }
        }

        $api = new Client();
        \wp_send_json(['suggestions' => $api->suggest_anchors($source_html, $target_title)]);
    }

    // ── Screen wiring ────────────────────────────────────────────────────────

    public function hookTermEdit(string $hook_suffix): void
    {
        // Taxonomy editor [custom plugins, TinyMCE]
        if ('term.php' === $hook_suffix) {
            \add_action('admin_footer', [$this, 'renderPanel']);
            \add_action('media_buttons', [$this, 'mediaButton'], 15);
            $this->enqueuePanelCss();
            $this->enqueueTinymce();
        }
        // Post editor
        if ('post.php' === $hook_suffix || 'post-new.php' === $hook_suffix) {
            if (! $this->isGutenbergEnabled()) { // For TinyMCE
                \add_action('admin_footer', [$this, 'renderPanel']);
                $this->enqueuePanelCss();
                \add_action('media_buttons', [$this, 'mediaButton'], 15);
                $this->enqueuePanelTinymce();
            }
        }
    }

    public function enqueuePanelCss(): void
    {
        \wp_register_style('cherrylink-css-main', CHERRYLINK_DIR_URL . 'css/cherry-main.css', [], \LinkatePosts::asset_ver('css/cherry-main.css'));
        \wp_enqueue_style('cherrylink-css-main');
    }

    public function enqueuePanelTinymce(): void
    {
        $classic_js = 'cherry-front.js';
        \wp_register_script('cherrylink-js-main', CHERRYLINK_DIR_URL . 'js/' . $classic_js, ['jquery', 'wp-i18n'], \LinkatePosts::asset_ver('js/' . $classic_js));
        \wp_localize_script('cherrylink-js-main', 'ajax_obj', [
            'ajaxurl' => \admin_url('admin-ajax.php'),
            'nonce'   => Security::nonce(),
        ]);
        \wp_set_script_translations('cherrylink-js-main', 'cherrylink', CHERRYLINK_DIR . 'languages');
        \wp_enqueue_script('cherrylink-js-main');
    }

    /** @deprecated Alias kept for the term.php branch's original call name. */
    public function enqueueTinymce(): void
    {
        $this->enqueuePanelTinymce();
    }

    public function mediaButton(): void
    {
        echo '<a class="linkate-button button"><span class="wp-media-buttons-icon linkate-media-btn"></span>CherryLink Pro</a>';
    }

    public function sendOptionsFrontend(): void
    {
        $options = $this->options->main();
        global $post;
        $current_id = $post ? $post->ID : 0;

        $html = (new View())->render('editor/frontend-options', [
            'no_selection_action' => (string) ($options['no_selection_action'] ?? ''),
            'get_data_limit'      => (string) ($options['limit_ajax'] ?? ''),
            'post_id'             => (string) $current_id,
            // The classic editor gates its incoming/outgoing stat fetches on this flag.
            // Legacy semantics: it was `isset($options['linkate_scheme_exists'])` — true
            // whenever the key is present, which Options::main() always guarantees. The
            // stored VALUE is a vestigial default that nothing ever flips to true, so a
            // truthy value-check silently disabled all classic-editor stats. Mirror the
            // original presence-check: for a licensed/configured site, stats load.
            'scheme_flag'         => isset($options['linkate_scheme_exists']) ? '1' : '',
            'multilink'           => (string) ($options['multilink'] ?? ''),
            'show_cat_filter'     => (string) ($options['show_cat_filter'] ?? 'false'),
        ]);
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- View renders pre-escaped template output
        echo $html;
    }

    public function renderPanel(): void
    {
        $options = $this->options->main();
        $html    = (new View())->render('editor/classic-panel', [
            'options'            => $options,
            'cl_exists_class'    => ($options['multilink'] ?? '') === 'checked' ? 'link-exists-multi' : 'link-exists',
            'tab_links_url'      => CHERRYLINK_DIR_URL . 'assets/gutenberg/tab-links.png',
            'tab_taxonomies_url' => CHERRYLINK_DIR_URL . 'assets/gutenberg/tab-taxonomies.png',
            'tab_stats_url'      => CHERRYLINK_DIR_URL . 'assets/gutenberg/tab-stats.png',
        ]);
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- View renders pre-escaped template output
        echo $html;
    }

    public function isGutenbergEnabled(): bool
    {
        if (\function_exists('is_gutenberg_page') && \is_gutenberg_page()) {
            return true; // The Gutenberg plugin is on.
        }
        $current_screen = \get_current_screen();
        if ($current_screen && \method_exists($current_screen, 'is_block_editor') && $current_screen->is_block_editor()) {
            return true; // Gutenberg page on 5+.
        }
        return false;
    }

    // ── Helpers (kept reachable via back-compat global shims) ──────────────────

    /**
     * Wrap plain-text content in <p> so the API's block-based NP extractor can find
     * blocks. Mirrors the prep linkate_posts() used to do before search.
     */
    public static function buildSourceHtml(string $content): string
    {
        if ($content === '') {
            return '';
        }
        if (\strip_tags($content) === $content) {
            $paras   = \array_filter(\array_map('trim', \preg_split('/\n{2,}/', $content)));
            $content = '<p>' . \implode('</p><p>', $paras) . '</p>';
        }
        return $content;
    }

    public static function createQuickCatSelect(): string
    {
        $cats = TermsService::allCategories();

        $items = '<option value="0" class="quick_item_all" selected>' . \esc_html__('Все рубрики', 'cherrylink') . '</option>';
        foreach ($cats as $v) {
            $k      = \key($v);
            $margin = '';
            for ($i = 0; $i < $k; $i++) {
                $margin .= '-';
            }
            $items .= "<option value=\"$v[$k]\" class=\"quick_item_$k\">$margin $v[$k]</option>";
        }

        return "<div class='quick-cat-filter'><select id='quick_cat_filter'>$items</select></div>";
    }
}
