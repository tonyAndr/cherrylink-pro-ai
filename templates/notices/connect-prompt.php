<?php
/**
 * Plugins-screen notice shown until CherryLink is connected.
 *
 * @var string $connect_url settings page (setup wizard)
 * @var string $dismiss_url nonce-protected dismiss link
 */

defined('ABSPATH') || exit;
?>
<div class="notice notice-info">
    <p><strong>CherryLink</strong>: <?php esc_html_e('подключите CherryLink, чтобы начать.', 'cherrylink'); ?>
        <a class="button button-primary" href="<?php echo esc_url($connect_url); ?>"><?php esc_html_e('Подключить', 'cherrylink'); ?></a>
        <a href="<?php echo esc_url($dismiss_url); ?>"><?php esc_html_e('Скрыть', 'cherrylink'); ?></a>
    </p>
</div>
