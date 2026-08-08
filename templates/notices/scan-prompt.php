<?php
/**
 * Admin notice prompting the user to (re)scan the site.
 *
 * @var string $line1    first paragraph (contains static <strong> markup)
 * @var string $line2    second paragraph (contains static <strong> markup)
 * @var string $scan_url settings → scan subpage URL
 *
 * $line1/$line2 are fixed developer strings with intentional inline markup, so
 * they are echoed raw; only the URL is escaped.
 */

defined('ABSPATH') || exit;
?>
        <div class="notice notice-warning is-dismissable">
            <p><?php echo $line1; ?></p>
            <p><?php echo $line2; ?></p>
            <p><?php
            /* translators: 1: link to the "Scan" settings tab, 2: bold "Start scan" button label */
            printf(
                esc_html__('Перейдите в настройки плагина на вкладку %1$s, и нажмите на кнопку "%2$s".', 'cherrylink'),
                '<a href="' . esc_url($scan_url) . '">' . esc_html__('Сканирование', 'cherrylink') . '</a>',
                '<strong>' . esc_html__('Начать сканирование', 'cherrylink') . '</strong>'
            );
            ?></p>
        </div>
<?php
