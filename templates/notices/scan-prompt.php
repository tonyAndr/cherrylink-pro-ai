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
            <p>Перейдите в настройки плагина на вкладку <a href="<?php echo esc_url($scan_url); ?>">Сканирование</a>, и нажмите на кнопку "<strong>Начать сканирование</strong>".</p>
        </div>
<?php
