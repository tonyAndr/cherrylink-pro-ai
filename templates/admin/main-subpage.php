<?php
/**
 * "Основные" (main) settings subpage.
 *
 * @var string $export_url  download URL for the exported options file
 *
 * Calls the readiness/license/api partial functions (which render their own
 * templates) for composition.
 */

defined('ABSPATH') || exit;
?>
    <div class="linkateposts-tab-content pt-3">
        <div class="grid grid-cols-2 gap-4 content-start items-start">
            <div class="grid grid-col-1 gap-4">
                <?php \CherryLink\Admin\SettingsPage::indexStatusDisplay('main'); ?>

                <div class="<?= \CherryLink\Support\Tailwind::$CARD ?>">
                    <h2 class="<?= \CherryLink\Support\Tailwind::$H2 ?>"><?php esc_html_e('Справочные материалы', 'cherrylink'); ?></h2>
                    <ol>
                        <li><a target="_blank" href="https://seocherry.ru/plagin-cherrylink-pro-arenda/">🔗 <?php esc_html_e('Тарифы и получение ключа', 'cherrylink'); ?></a></li>
                        <li><a target="_blank" href="https://seocherry.ru/instruktsiya-po-nastroike-plagina-cherrylink/">🔗 <?php esc_html_e('Настройки плагина', 'cherrylink'); ?></a></li>
                        <li><a target="_blank" href="https://seocherry.ru/instruktsiya-po-rabote-s-plaginom-perelinkovki-v-redaktore-wordpress/">🔗 <?php esc_html_e('Работа в редакторе WP', 'cherrylink'); ?></a></li>
                        <li><a target="_blank" href="https://seocherry.ru/instruktsii-plaginov-wordpress-v-video-formate-shorts/">🔗 <?php esc_html_e('Видео-инструкции', 'cherrylink'); ?></a></li>
                        <li><a target="_blank" href="https://seocherry.ru/zapisi-o-rabote-v-internet-seti-seo-instrumenty-znacheniya-pravila/">🔗 <?php esc_html_e('Полезные материалы про перелинковку', 'cherrylink'); ?></a></li>
                    </ol>
                </div>
            </div>
            <div class="grid grid-col-1 gap-4">
                <?php \CherryLink\Admin\SettingsPage::licenseField(); ?>
                <?php \CherryLink\Admin\SettingsPage::vectorApiField(); ?>

                <div class="<?= \CherryLink\Support\Tailwind::$CARD ?> cherry-settings-export-container">

                    <div>
                        <h2 class="<?= \CherryLink\Support\Tailwind::$H2 ?>"><?php esc_html_e('Экспорт и импорт настроек', 'cherrylink'); ?></h2>
                        <p><?php esc_html_e('Для переноса настроек между сайтами, скачайте файл настроек', 'cherrylink'); ?> <strong>export_options.txt</strong> <?php esc_html_e('и импортируйте его на другом сайте.', 'cherrylink'); ?> </p>
                        <a class="inline-block <?= \CherryLink\Support\Tailwind::$BTN_NORMAL ?> " href="<?php echo $export_url; ?>" download><?php esc_html_e('Скачать файл настроек', 'cherrylink'); ?></a>
                    </div>

                    <div class="mt-6">
                        <p><?php esc_html_e('Для импорта настроек плагина загрузите текстовый файл', 'cherrylink'); ?> <strong>export_options.txt</strong><?php esc_html_e(', полученный при экспорте настроек.', 'cherrylink'); ?></p>
                        <form method="post" action="" enctype="multipart/form-data">
                            <div class="p-0 my-3">
                                <!-- <p><strong>Поле для импорта:</strong></p> -->
                                <input type="file" name="upload_options" required class="block w-full text-sm text-stone-500 border p-0 file:rounded-l-lg file:mr-5 file:py-2 file:px-3 rounded-lg  file:text-xs    file:bg-slate-600 file:border-0 file:text-white   hover:file:cursor-pointer hover:file:bg-blue-50   hover:file:text-blue-700" style="padding:0">
                                <input type="submit" class="<?= \CherryLink\Support\Tailwind::$BTN_NORMAL ?> " name="import_settings" value="<?php _e('Импортировать настройки', 'cherrylink') ?>" />
                            </div>
                            <?php if (function_exists('wp_nonce_field')) wp_nonce_field('linkate-posts-update-options'); ?>
                        </form>
                    </div>


                </div>
            </div>
        </div>

    </div>
<?php
