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
                <?php linkate_posts_index_status_display('main'); ?>

                <div class="<?= CL_TWC::$CARD ?>">
                    <h2 class="<?= CL_TWC::$H2 ?>">Справочные материалы</h2>
                    <ol>
                        <li><a target="_blank" href="https://seocherry.ru/plagin-cherrylink-pro-arenda/">🔗 Тарифы и получение ключа</a></li>
                        <li><a target="_blank" href="https://seocherry.ru/instruktsiya-po-nastroike-plagina-cherrylink/">🔗 Настройки плагина</a></li>
                        <li><a target="_blank" href="https://seocherry.ru/instruktsiya-po-rabote-s-plaginom-perelinkovki-v-redaktore-wordpress/">🔗 Работа в редакторе WP</a></li>
                        <li><a target="_blank" href="https://seocherry.ru/instruktsii-plaginov-wordpress-v-video-formate-shorts/">🔗 Видео-инструкции</a></li>
                        <li><a target="_blank" href="https://seocherry.ru/zapisi-o-rabote-v-internet-seti-seo-instrumenty-znacheniya-pravila/">🔗 Полезные материалы про перелинковку</a></li>
                    </ol>
                </div>
            </div>
            <div class="grid grid-col-1 gap-4">
                <?php linkate_posts_license_field(); ?>
                <?php linkate_posts_vector_api_field(); ?>

                <div class="<?= CL_TWC::$CARD ?> cherry-settings-export-container">

                    <div>
                        <h2 class="<?= CL_TWC::$H2 ?>">Экспорт и импорт настроек</h2>
                        <p>Для переноса настроек между сайтами, скачайте файл настроек <strong>export_options.txt</strong> и импортируйте его на другом сайте. </p>
                        <a class="inline-block <?= CL_TWC::$BTN_NORMAL ?> " href="<?php echo $export_url; ?>" download>Скачать файл настроек</a>
                    </div>

                    <div class="mt-6">
                        <p>Для импорта настроек плагина загрузите текстовый файл <strong>export_options.txt</strong>, полученный при экспорте настроек.</p>
                        <form method="post" action="" enctype="multipart/form-data">
                            <div class="p-0 my-3">
                                <!-- <p><strong>Поле для импорта:</strong></p> -->
                                <input type="file" name="upload_options" required class="block w-full text-sm text-stone-500 border p-0 file:rounded-l-lg file:mr-5 file:py-2 file:px-3 rounded-lg  file:text-xs    file:bg-slate-600 file:border-0 file:text-white   hover:file:cursor-pointer hover:file:bg-blue-50   hover:file:text-blue-700" style="padding:0">
                                <input type="submit" class="<?= CL_TWC::$BTN_NORMAL ?> " name="import_settings" value="<?php _e('Импортировать настройки', CHERRYLINK_TEXT_DOMAIN) ?>" />
                            </div>
                            <?php if (function_exists('wp_nonce_field')) wp_nonce_field('linkate-posts-update-options'); ?>
                        </form>
                    </div>


                </div>
            </div>
        </div>

    </div>
<?php
