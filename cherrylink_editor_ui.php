<?php
/*
 * CherryLink Plugin
 */

// Disable direct access
defined('ABSPATH') || exit;
// TODO CHECK LICENSE

$options_meta = get_option('linkate_posts_meta', []);
if (!isset($options_meta['key_valid']) || !$options_meta['key_valid']) {
    return false;
}

add_action('admin_head', 'linkate_send_options_frontend');
add_action('wp_ajax_get_linkate_links', 'get_linkate_links');
add_action('admin_enqueue_scripts', 'hook_term_edit', 10);
if (function_exists('register_cherrylink_gutenberg_scripts')) {
    add_action('enqueue_block_editor_assets', 'register_cherrylink_gutenberg_scripts');
}

// Using linkateposts to get relevant results
function get_linkate_links()
{
    \CherryLink\Ajax\Security::verify('edit_posts');

    $data = json_decode(file_get_contents('php://input'), true);

    if (isset($data)) {
        $post_id = $data['post_id'];
        $is_term = $data['is_term'];
        $offset = $data['offset'];
        $custom_text = isset($data['custom_text']) ? $data['custom_text'] : '';
        $mode = 'gutenberg';
    } else {

        $post_id = $_POST['post_id'];
        $is_term = $_POST['is_term'];
        $offset = $_POST['offset'];
        $custom_text = isset($_POST['custom_text']) ? $_POST['custom_text'] : '';
        $mode = 'classic';
    }
    // cherry_write_log($data);
    $data =  linkate_posts("manual_ID=" . $post_id . "&is_term=" . $is_term . "&offset=" . $offset . "&mode=" . $mode . "&custom_text=" . $custom_text . "&");
    wp_send_json($data);
}


/**
 * Wrap plain-text content in <p> so the API's block-based NP extractor can find blocks.
 * Mirrors the prep that linkate_posts() used to do before search.
 */
function linkate_build_source_html(string $content): string
{
    if ($content === '') {
        return '';
    }
    if (strip_tags($content) === $content) {
        $paras = array_filter(array_map('trim', preg_split('/\n{2,}/', $content)));
        $content = '<p>' . implode('</p><p>', $paras) . '</p>';
    }
    return $content;
}


/**
 * On-demand anchor suggestions for one target hit — called when the editor expands a
 * suggestion panel. Source is the post being edited; target is the hit the user clicked.
 */
add_action('wp_ajax_get_linkate_suggestions', 'get_linkate_suggestions');
function get_linkate_suggestions()
{
    \CherryLink\Ajax\Security::verify('edit_posts');

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $post_id      = isset($data['post_id'])      ? (int) $data['post_id']                       : 0;
    $target_id    = isset($data['target_id'])    ? (int) $data['target_id']                     : 0;
    $target_title = isset($data['target_title']) ? sanitize_text_field($data['target_title'])   : '';
    $source_html  = isset($data['source_html'])  ? (string) $data['source_html']                : '';

    // Prefer live editor HTML if the client sent it; else rebuild from the saved post.
    if ($source_html === '' && $post_id) {
        $post = get_post($post_id);
        if ($post) {
            $source_html = linkate_build_source_html($post->post_content);
        }
    }
    if ($target_title === '' && $target_id) {
        $target = get_post($target_id);
        if ($target) {
            $target_title = $target->post_title;
        }
    }

    $api = new CherryVectorAPI();
    wp_send_json(['suggestions' => $api->suggest_anchors($source_html, $target_title)]);
}


function hook_term_edit($hook_suffix)
{
    // Taxonomy editor [custom plugins, TinyMCE]
    if ('term.php' === $hook_suffix) {
        add_action('admin_footer', 'cherrylink_classiceditor_panel');
        add_action('media_buttons', 'add_linkate_button', 15);
        linkate_panel_css();
        linkate_panel_tinymce_js();
    }
    // Post editor
    if ('post.php' === $hook_suffix || 'post-new.php' === $hook_suffix) {

        if (!is_gutenberg_enabled()) // For TinyMCE
        {
            add_action('admin_footer', 'cherrylink_classiceditor_panel');
            linkate_panel_css();
            add_action('media_buttons', 'add_linkate_button', 15);
            linkate_panel_tinymce_js();
        }
    }
    return;
}

function linkate_panel_css()
{
    wp_register_style('cherrylink-css-main', plugins_url('/css/cherry-main.css', __FILE__), '', LinkatePosts::asset_ver('css/cherry-main.css'));
    wp_enqueue_style('cherrylink-css-main');
}

function linkate_panel_tinymce_js()
{
    $classic_js = 'cherry-front.js';
    wp_register_script('cherrylink-js-main', plugins_url('/js/' . $classic_js, __FILE__), array('jquery'), LinkatePosts::asset_ver('js/' . $classic_js));
    wp_localize_script('cherrylink-js-main', 'ajax_obj', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce'   => \CherryLink\Ajax\Security::nonce(),
    ]);
    wp_enqueue_script('cherrylink-js-main');
}

// The media button to dislay links box
function add_linkate_button()
{
    echo '<a class="linkate-button button"><span class="wp-media-buttons-icon linkate-media-btn"></span>CherryLink Pro</a>';
}

function linkate_send_options_frontend()
{
    $options = (array) get_option('linkate-posts', []);
    global $post;
    $current_id = 0;
    if ($post)
        $current_id = $post->ID;
    $scheme_exists = isset($options['linkate_scheme_exists']) ? true : false;
?>
    <script>
        var cherrylink_options = [];
        cherrylink_options['no_selection_action'] = <?php echo '"' . $options['no_selection_action'] . '"'; ?>;
        cherrylink_options['get_data_limit'] = <?php echo '"' . $options['limit_ajax'] . '"'; ?>;
        cherrylink_options['post_id'] = <?php echo '"' . $current_id . '"'; ?>;
        cherrylink_options['linkate_scheme_exists'] = <?php echo '"' . $scheme_exists . '"'; ?>;
        cherrylink_options['multilink'] = <?php echo '"' . $options['multilink'] . '"'; ?>;
        cherrylink_options['show_cat_filter'] = <?php echo $options['show_cat_filter']; ?>;
    </script>
<?php
}

function cherrylink_classiceditor_panel()
{
    $options = (array) get_option('linkate-posts', []);
    echo (new \CherryLink\Support\View())->render('editor/classic-panel', [
        'options'            => $options,
        'cl_exists_class'    => $options['multilink'] === 'checked' ? 'link-exists-multi' : 'link-exists',
        'tab_links_url'      => plugins_url('/assets/gutenberg/tab-links.png', __FILE__),
        'tab_taxonomies_url' => plugins_url('/assets/gutenberg/tab-taxonomies.png', __FILE__),
        'tab_stats_url'      => plugins_url('/assets/gutenberg/tab-stats.png', __FILE__),
    ]);
}

function create_quick_cat_select()
{
    $cats = linkate_get_all_categories();

    $items = "<option value=\"0\" class=\"quick_item_all\" selected>Все рубрики</option>";
    foreach ($cats as $v) {
        $k = key($v);
        $margin = "";
        for ($i = 0; $i < $k; $i++) {
            $margin .= "-";
        }
        $items .= "<option value=\"$v[$k]\" class=\"quick_item_$k\">$margin $v[$k]</option>";
    }

    return "<div class='quick-cat-filter'><select id='quick_cat_filter'>$items</select></div>";
}

function is_gutenberg_enabled()
{
    if (
        function_exists('is_gutenberg_page') &&
        is_gutenberg_page()
    ) {
        // The Gutenberg plugin is on.
        return true;
    }
    $current_screen = get_current_screen();
    if (
        method_exists($current_screen, 'is_block_editor') &&
        $current_screen->is_block_editor()
    ) {
        // Gutenberg page on 5+.
        return true;
    }
    return false;
}
