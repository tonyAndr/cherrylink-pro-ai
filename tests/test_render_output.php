<?php
/**
 * Differential test for the link-card render refactor (v1.0.6).
 *
 * The refactor replaced the dynamic {tag} dispatch (lp_output_tag_action +
 * link_cf_prepare_template + link_cf_expand_template + $ext) with a direct
 * linkate_link_fields() map fed to strtr(). This test proves the NEW path
 * produces byte-identical output to the OLD machinery for the REAL templates
 * pulled from cherrylink_main.php, in both editor modes.
 *
 * Run: docker run --rm -v "$PWD":/app -w /app wordpress:cli-2-php8.2 php tests/test_render_output.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH', '/tmp/');
define('CHERRYLINK_DIR_URL', 'http://site/wp-content/plugins/cherrylink-pro-ai/');
if (!defined('CHERRYLINK_TEXT_DOMAIN')) define('CHERRYLINK_TEXT_DOMAIN', 'cherrylink');

// ---- minimal WordPress stubs (values are dummy; both paths use them equally) ----
$GLOBALS['OPTS'] = array(
    'relative_links'      => 'full',
    'anons_len'           => 0,
    'template_image_size' => '',
    'divider'             => '',
    'none_text'           => 'Ничего не найдено...',
);
function get_option($k, $d = false) { return $k === 'date_format' ? 'Y-m-d' : $GLOBALS['OPTS']; }
function get_permalink($id)                 { return "http://site/?p=$id"; }
function get_the_author_meta($f, $a)        { return "Author$a"; }
function get_post_meta($id, $k, $s = false) { return ''; }
function get_the_category($id)              { return array(); }
function apply_filters($t, $v = null)       { return $v; }
function mysql2date($fmt, $date)            { return $date; }
function get_the_post_thumbnail_url($id, $s = '') { return false; }
function attachment_url_to_postid($u)       { return 0; }
function wp_get_attachment_image_url($id, $s = '') { return false; }
function __($s, $d = null)                  { return $s; }
function strip_shortcodes($c)               { return $c; }

$PLUGIN = dirname(__DIR__);
require $PLUGIN . '/cherrylink_cf.php';          // linkate_unparse_url deps
require $PLUGIN . '/cherrylink_output_tags.php'; // otf getters + linkate_link_fields + linkate_unparse_url

// ---- OLD machinery, reproduced verbatim as the oracle ----
function ref_tag_action($tag) { return 'linkate_otf_' . $tag; }
function ref_prepare($template) {
    preg_match_all('/{((?:[^{}]|{[^{}]*})*)}/', $template, $matches);
    $translations = array();
    if (is_array($matches)) {
        foreach ($matches[1] as $match) {
            if (strpos($match, ':') !== false) { list($tag, $ext) = explode(':', $match, 2); }
            else { $tag = $match; $ext = false; }
            $action = ref_tag_action($tag);
            if (function_exists($action)) {
                $translations['acts'][]     = $action;
                $translations['fulltags'][] = '{' . $match . '}';
                $translations['exts'][]     = $ext;
            }
        }
    }
    return $translations;
}
function ref_expand($result, $template, $translations, $option_key) {
    $replacements = array();
    if (array_key_exists('fulltags', $translations)) {
        $n = count($translations['fulltags']);
        for ($i = 0; $i < $n; $i++) {
            $replacements[$translations['fulltags'][$i]] =
                $translations['acts'][$i]($option_key, $result, $translations['exts'][$i]);
        }
    }
    return strtr($template, $replacements) . "\n";
}

// ---- pull the REAL templates out of cherrylink_main.php ----
$src = file_get_contents($PLUGIN . '/cherrylink_main.php');
preg_match("/\\\$output_template = '(.*?)';/s",            $src, $mg) or die("gutenberg tpl not found\n");
preg_match("/\\\$output_template_item_prefix = '(.*?)';/s", $src, $mp) or die("prefix not found\n");
preg_match("/\\\$output_template_item_suffix = '(.*?)';/s", $src, $ms) or die("suffix not found\n");
$GUT_TPL = $mg[1];
$PREFIX  = $mp[1];
$SUFFIX  = $ms[1];

$ok = 'linkate-posts';
function mk($id) {
    $r = new stdClass;
    $r->ID = $id; $r->post_title = "Title $id"; $r->post_content = '';
    $r->post_author = $id; $r->post_date = '2026-01-01 10:00:00'; $r->post_excerpt = '';
    $r->suggestions = '';
    return $r;
}

// ---- OLD vs NEW assemblies (mirror cherrylink_main.php exactly) ----
function old_gutenberg($ids, $tpl, $ok) {
    $tr = ref_prepare($tpl); $items = array();
    foreach ($ids as $id) { $r = mk($id); $r->suggestions = str_replace('"', '\\"', $r->suggestions);
        $items[] = "{" . ref_expand($r, $tpl, $tr, $ok) . "}"; }
    return "[" . implode(",", str_replace("\n", "", $items)) . "]";
}
function new_gutenberg($ids, $tpl, $ok) {
    $items = array();
    foreach ($ids as $id) { $r = mk($id); $r->suggestions = str_replace('"', '\\"', $r->suggestions);
        $items[] = "{" . strtr($tpl, linkate_link_fields($ok, $r)) . "}"; }
    return "[" . implode(",", $items) . "]";
}
function old_classic($ids, $out_final, $ok, $div) {
    $tr = ref_prepare($out_final); $items = array();
    foreach ($ids as $id) { $r = mk($id); $r->suggestions = htmlspecialchars($r->suggestions, ENT_COMPAT, 'UTF-8');
        $items[] = ref_expand($r, $out_final, $tr, $ok); }
    return trim(implode($div ? $div : "\n", $items));
}
function new_classic($ids, $out_final, $ok, $div) {
    $items = array();
    foreach ($ids as $id) { $r = mk($id); $r->suggestions = htmlspecialchars($r->suggestions, ENT_COMPAT, 'UTF-8');
        $items[] = strtr($out_final, linkate_link_fields($ok, $r)) . "\n"; }
    return trim(implode($div ? $div : "\n", $items));
}

$ids = array(11, 22);
$classic_h1  = $PREFIX . '{title}'     . $SUFFIX;

$cases = array(
    'gutenberg'   => array(old_gutenberg($ids, $GUT_TPL, $ok),        new_gutenberg($ids, $GUT_TPL, $ok)),
    'classic_h1'  => array(old_classic($ids, $classic_h1, $ok, ''),   new_classic($ids, $classic_h1, $ok, '')),
    'none_text'   => array(
        trim('<p>' . ref_expand(array(), $GLOBALS['OPTS']['none_text'], ref_prepare($GLOBALS['OPTS']['none_text']), $ok) . '</p>'),
        trim('<p>' . $GLOBALS['OPTS']['none_text'] . "\n</p>"),
    ),
);

// link_fields must cover every placeholder the templates use — assert no stray {tag} survives.
$leftover = strtr($classic_h1, linkate_link_fields($ok, mk(1)));
$has_placeholder = preg_match('/\{[a-z_]+\}/', $leftover);

$fail = 0;
foreach ($cases as $name => $pair) {
    if ($pair[0] === $pair[1]) {
        echo "PASS  $name  (" . strlen($pair[1]) . " bytes)\n";
    } else {
        $fail++;
        echo "FAIL  $name\n---- OLD ----\n{$pair[0]}\n---- NEW ----\n{$pair[1]}\n----\n";
    }
}
if ($has_placeholder) { $fail++; echo "FAIL  unresolved placeholder in classic template: $leftover\n"; }
else                  { echo "PASS  no unresolved {placeholder}\n"; }

echo $fail ? "\n$fail CHECK(S) FAILED\n" : "\nALL CHECKS PASSED\n";
exit($fail ? 1 : 0);
