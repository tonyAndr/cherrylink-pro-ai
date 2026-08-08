<?php
/**
 * Admin notice shown on the settings page when the license is inactive.
 *
 * @var bool   $dirty        whether the link index is out of sync
 * @var string $since        human date the index went dirty (raw, escaped here)
 * @var string $settings_url settings → main subpage URL
 */

defined('ABSPATH') || exit;
?>
<div class="notice notice-warning"><p><strong>CherryLink Pro</strong>: <?php esc_html_e('лицензия неактивна — индексация и сканирование приостановлены, ссылки не обновляются.', 'cherrylink'); ?><?php if ($dirty) : ?> <strong><?php esc_html_e('Индекс перелинковки не синхронизирован', 'cherrylink'); ?></strong> <?php
    /* translators: %s: human-readable date since which changes have not been indexed */
    printf(esc_html__('(изменения с %s не проиндексированы). Активируйте ключ и запустите сканирование, чтобы восстановить синхронизацию.', 'cherrylink'), esc_html($since));
?><?php else : ?> <a href="<?php echo esc_url($settings_url); ?>"><?php esc_html_e('Введите ключ лицензии', 'cherrylink'); ?></a>.<?php endif; ?></p></div>
<?php
