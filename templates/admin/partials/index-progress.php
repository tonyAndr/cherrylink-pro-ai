<?php
/**
 * Scan progress bar + start/stop controls (scan subpage).
 */

defined('ABSPATH') || exit;
?>
    <div class="text-center">
        <progress id="reindex_progress" class="rounded-lg"></progress>
        <div id="reindex_progress_text" class="text-lg pb-6"></div>

        <input type="submit" class="<?= \CherryLink\Support\Tailwind::$BTN_SCAN ?> button-reindex" name="reindex_all" value="<?php esc_attr_e('Начать сканирование', 'cherrylink'); ?>" />
        <button type="button" class="<?= \CherryLink\Support\Tailwind::$BTN_ACTION ?> button-reindex-stop" style="display:none"><?php esc_html_e('Остановить', 'cherrylink'); ?></button>
        <?php if (function_exists('wp_nonce_field')) ?>
    </div>

<?php
