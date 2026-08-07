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
<div class="notice notice-warning"><p><strong>CherryLink Pro</strong>: лицензия неактивна — индексация и сканирование приостановлены, ссылки не обновляются.<?php if ($dirty) : ?> <strong>Индекс перелинковки не синхронизирован</strong> (изменения с <?php echo esc_html($since); ?> не проиндексированы). Активируйте ключ и запустите сканирование, чтобы восстановить синхронизацию.<?php else : ?> <a href="<?php echo esc_url($settings_url); ?>">Введите ключ лицензии</a>.<?php endif; ?></p></div>
<?php
