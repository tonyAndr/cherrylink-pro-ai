<?php
/**
 * Inline options bridge for the classic editor JS (cherry-front.js). Printed in
 * admin_head; defines the cherrylink_options the panel reads. Values are escaped
 * for the JS context; show_cat_filter is emitted unquoted as a JS boolean, exactly
 * as the legacy inline markup did.
 *
 * @var string $no_selection_action
 * @var string $get_data_limit
 * @var string $post_id
 * @var string $scheme_flag         '1' when the vector API is configured, else ''
 * @var string $multilink
 * @var string $show_cat_filter     'true' | 'false' (emitted raw as a JS boolean)
 */

defined('ABSPATH') || exit;
?>
    <script>
        var cherrylink_options = [];
        cherrylink_options['no_selection_action'] = "<?php echo esc_js($no_selection_action); ?>";
        cherrylink_options['get_data_limit'] = "<?php echo esc_js($get_data_limit); ?>";
        cherrylink_options['post_id'] = "<?php echo esc_js($post_id); ?>";
        cherrylink_options['linkate_scheme_exists'] = "<?php echo esc_js($scheme_flag); ?>";
        cherrylink_options['multilink'] = "<?php echo esc_js($multilink); ?>";
        cherrylink_options['show_cat_filter'] = <?php echo esc_js($show_cat_filter); ?>;
    </script>
<?php
