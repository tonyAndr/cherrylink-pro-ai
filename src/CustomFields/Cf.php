<?php

declare(strict_types=1);

namespace CherryLink\CustomFields;

/**
 * Query-building helpers for the suggestions pipeline: request-arg parsing, option
 * merging, current-post resolution, and the WHERE-clause fragments that filter the
 * vector-API candidate IDs down through WordPress (status / type / category / author).
 *
 * Ported verbatim from the legacy cherrylink_cf.php (now a shim that keeps the
 * link_cf_* / linkate_get_image_sizes globals as delegators). The only caller of the
 * query helpers is \CherryLink\Editor\SuggestionsResults; linkate_get_image_sizes is
 * still used by the ACF settings form.
 */
final class Cf
{
    /**
     * Parse a 'key1=val1&key2=val2' arg string (coping with '=' inside values and a
     * custom separator introduced by a leading '&').
     *
     * @return array<string,string>
     */
    public static function parseArgs(string $args): array
    {
        $result = [];
        if ($args) {
            if (\substr($args, 0, 1) === '&') {
                $s    = \substr($args, 1, 1);
                $args = \substr($args, 2);
            } else {
                $s = '&';
            }
            $arguments = \explode($s, $args);
            foreach ($arguments as $arg) {
                if ($arg) {
                    $i = \strpos($arg, '=');
                    if ($i) {
                        $key          = \substr($arg, 0, $i);
                        $val          = \substr($arg, $i + 1);
                        $result[$key] = $val;
                    }
                }
            }
        }
        return $result;
    }

    /**
     * Fill in from stored options anything not passed as a request argument.
     *
     * @param array<string,mixed> $arg
     * @return array<string,mixed>
     */
    public static function setOptions(string $option_key, array $arg): array
    {
        $options = \get_option($option_key);
        if (! isset($arg['limit_ajax'])) {
            $arg['limit_ajax'] = self::slashes(@$options['limit_ajax']);
        }
        if (! isset($arg['divider'])) {
            $arg['divider'] = self::slashes(@$options['divider']);
        }
        if (! isset($arg['show_private'])) {
            $arg['show_private'] = @$options['show_private'];
        }
        if (! isset($arg['show_pages'])) {
            $arg['show_pages'] = @$options['show_pages'];
        }
        if (! isset($arg['none_text'])) {
            $arg['none_text'] = self::slashes(@$options['none_text']);
        }
        if (! isset($arg['excluded_cats'])) {
            $arg['excluded_cats'] = self::slashes(@$options['excluded_cats']);
        }
        if (! isset($arg['excluded_authors'])) {
            $arg['excluded_authors'] = self::slashes(@$options['excluded_authors']);
        }
        if (! isset($arg['show_customs'])) {
            $arg['show_customs'] = self::slashes(@$options['show_customs']);
        }
        if (! isset($arg['match_cat'])) {
            $arg['match_cat'] = @$options['match_cat'];
        }
        if (! isset($arg['status'])) {
            $arg['status'] = @$options['status'];
        }
        if (! isset($arg['consider_max_incoming_links'])) {
            $arg['consider_max_incoming_links'] = @$options['consider_max_incoming_links'];
        }
        if (! isset($arg['max_incoming_links'])) {
            $arg['max_incoming_links'] = @$options['max_incoming_links'];
        }
        return $arg;
    }

    /**
     * Un-slash a stored value without breaking on non-strings.
     *
     * These options are not all strings: `Options::fill()` stores `limit_ajax` as an
     * int, and with `declare(strict_types=1)` a plain `stripslashes()` on an int
     * throws a TypeError on PHP 8. That fataled the editor's link-list AJAX request
     * on every PHP 8 site.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function slashes($value)
    {
        return \is_string($value) ? \stripslashes($value) : $value;
    }

    /**
     * Resolve the current post ID, honoring a manual override. -666 = admin preview
     * (no id → false); the $post global can be overwritten by $wp_query, so we go
     * back to $wp_the_query.
     *
     * @param int $manual_current_ID
     * @return int|false
     */
    public static function currentPostId($manual_current_ID = -1)
    {
        if ($manual_current_ID === -666) {
            return false;
        }

        if ($manual_current_ID > 0) {
            return (int) $manual_current_ID;
        }

        // AJAX requests have no post loop, so `wp_the_query->post` is null there.
        // Dereferencing it directly logged "Attempt to read property ID on null" for
        // every classic-editor call that arrived without a post id.
        $query = $GLOBALS['wp_the_query'] ?? null;
        if (\is_object($query) && ! empty($query->post->ID)) {
            return (int) $query->post->ID;
        }
        if (\is_object($query) && ! empty($query->posts[0]->ID)) {
            return (int) $query->posts[0]->ID;
        }

        $post = $GLOBALS['post'] ?? null;
        if (\is_object($post) && ! empty($post->ID)) {
            return (int) $post->ID;
        }

        return false;
    }

    // ── WHERE-clause fragments ──────────────────────────────────────────────────

    public static function whereShowPages(string $show_pages, string $show_customs): string
    {
        // get_post_type() has existed since forever (>= our WP 5.6 floor), so the
        // legacy no-post-type fallback branch is unreachable and has been dropped.
        $typelist = [];
        if ($show_pages === 'true') {
            $typelist[] = "'page'";
            $typelist[] = "'post'";
        } elseif ($show_pages === 'false') {
            $typelist[] = "'post'";
        } elseif ($show_pages === 'but') {
            $typelist[] = "'page'";
        }
        if (! empty($show_customs)) {
            $customs = \explode(',', $show_customs);
            foreach ($customs as $value) {
                $typelist[] = "'" . $value . "'";
            }
        }
        if (\count($typelist) === 1) {
            return "post_type=$typelist[0]";
        }
        return 'post_type IN (' . \implode(',', $typelist) . ')';
    }

    /**
     * @param array<string,string>|string $status
     */
    public static function whereShowStatus($status): string
    {
        $set    = [];
        $status = (array) $status;
        foreach ($status as $name => $state) {
            if ($state === 'true') {
                $set[] = "'$name'";
            }
        }
        if ($set) {
            $result = \implode(',', $set);
            return "post_status IN ($result)";
        }
        return '1 = 2';
    }

    /** @param int $post_id */
    public static function whereMatchCategory($post_id): string
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $cat_ids = '';
        foreach (\get_the_category($post_id) as $cat) {
            // WP_Term::$cat_ID is a back-compat magic alias for term_id.
            if ($cat->term_id) {
                $cat_ids .= $cat->term_id . ',';
            }
        }
        $cat_ids  = \rtrim($cat_ids, ',');
        $catarray = \explode(',', $cat_ids);
        foreach ($catarray as $cat) {
            $catarray = \array_merge($catarray, \get_term_children((int) $cat, 'category'));
        }
        $catarray = \array_unique($catarray);
        $ids      = \get_objects_in_term($catarray, 'category');
        $ids      = \array_unique($ids);
        if (\is_array($ids) && \count($ids) > 0) {
            $out_posts = "'" . \implode("', '", $ids) . "'";
            return "$wpdb->posts.ID IN ($out_posts)";
        }
        return '1 = 2';
    }

    public static function whereExcludedCats(string $excluded_cats): string
    {
        global $wpdb;
        /** @var \wpdb $wpdb */
        $catarray = \explode(',', $excluded_cats);
        foreach ($catarray as $cat) {
            $catarray = \array_merge($catarray, \get_term_children((int) $cat, 'category'));
        }
        $catarray = \array_unique($catarray);
        $ids      = \get_objects_in_term($catarray, 'category');
        if (\is_array($ids) && \count($ids) > 0) {
            $out_posts = "'" . \implode("', '", $ids) . "'";
            return "$wpdb->posts.ID NOT IN ($out_posts)";
        }
        return '1 = 1';
    }

    public static function whereExcludedAuthors(string $excluded_authors): string
    {
        return "post_author NOT IN ( $excluded_authors )";
    }

    public static function whereHidePass(): string
    {
        return "post_password =''";
    }

    /**
     * Registered image sizes (used by the ACF settings form's {imagesrc} size picker).
     *
     * @return array<string,array<string,mixed>>
     */
    public static function imageSizes(): array
    {
        global $_wp_additional_image_sizes;
        /** @var array<string,array<string,mixed>> $_wp_additional_image_sizes */

        $sizes = [];

        foreach (\get_intermediate_image_sizes() as $_size) {
            if (\in_array($_size, ['thumbnail', 'medium', 'medium_large', 'large'], true)) {
                $sizes[$_size]['width']  = \get_option("{$_size}_size_w");
                $sizes[$_size]['height'] = \get_option("{$_size}_size_h");
                $sizes[$_size]['crop']   = (bool) \get_option("{$_size}_crop");
            } elseif (isset($_wp_additional_image_sizes[$_size])) {
                $sizes[$_size] = [
                    'width'  => $_wp_additional_image_sizes[$_size]['width'],
                    'height' => $_wp_additional_image_sizes[$_size]['height'],
                    'crop'   => $_wp_additional_image_sizes[$_size]['crop'],
                ];
            }
        }

        return $sizes;
    }
}
