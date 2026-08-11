<?php
/**
 * "Готовность к работе" readiness card + (on the main subpage) the server-status card.
 *
 * @var array  $data                 cl_index_status_data() snapshot (pre-built HTML strings)
 * @var string $index_process_status  indexing_process meta value
 * @var string $page                  'main' | 'scan' — controls the action buttons
 *
 * $data[*] values are server-generated HTML fragments and are echoed raw by design.
 */

defined('ABSPATH') || exit;
?>
    <div class="p-6 rounded-lg shadow-xl bg-white" id="cl-readiness-card" data-cl-stale="<?php echo !empty($data['stale']) ? '1' : '0'; ?>">
        <h2 class="<?= \CherryLink\Support\Tailwind::$H2 ?>"><?php esc_html_e('Готовность к работе', 'cherrylink'); ?> <span id="cherry_process_badge"><?php echo $data['badge_html']; ?></span></h2>
        <ul class="list-disc list-inside">
            <li><span id="cherry_index_status"><?php echo $data['index_status_text']; ?></span></li>
            <li><span id="cherry_scheme_status"><?php echo $data['scheme_status_text']; ?></span></li>
            <li><span id="cherry_lastscan_status"><?php echo $data['last_scan_text']; ?></span></li>
        </ul>
        <?php
        if ($page === 'main') {
        ?>
            <a href="/wp-admin/options-general.php?page=cherrylink-pro&subpage=statistics"><button class="<?= \CherryLink\Support\Tailwind::$BTN_ACTION ?> mt-2"><?php esc_html_e('Проверьте сайт', 'cherrylink'); ?></button></a>
            <a href="/wp-admin/options-general.php?page=cherrylink-pro&subpage=scan"><button class="<?= \CherryLink\Support\Tailwind::$BTN_ACTION ?> mt-2"><?= $index_process_status === 'DONE' ? esc_html__('Сканировать сайт', 'cherrylink') : esc_html__('Пересканировать', 'cherrylink') ?></button></a>
        <?php
        }
        ?>
    </div>

    <?php if ($page === 'main') { ?>
    <div class="<?= \CherryLink\Support\Tailwind::$CARD ?>" id="cl-server-status-card">
        <h2 class="<?= \CherryLink\Support\Tailwind::$H2 ?>"><?php esc_html_e('Состояние сервера', 'cherrylink'); ?> <span id="cl-srv-badge"><code>[<?php esc_html_e('проверка…', 'cherrylink'); ?>]</code></span></h2>
        <ul class="list-disc list-inside" id="cl-srv-lines">
            <li><?php esc_html_e('Проверяем соединение с сервером CherryLink…', 'cherrylink'); ?></li>
        </ul>
        <button type="button" id="cl-srv-recheck" class="<?= \CherryLink\Support\Tailwind::$BTN_ACTION ?> mt-2"><?php esc_html_e('Проверить связь', 'cherrylink'); ?></button>
    </div>
    <?php } ?>
<?php
