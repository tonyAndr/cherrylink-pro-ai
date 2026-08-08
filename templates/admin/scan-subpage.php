<?php
/**
 * "Сканирование сайта" (scan) settings subpage.
 *
 * @var array $options  linkate-posts options
 */

defined('ABSPATH') || exit;
?>
    <div class=" linkateposts-tab-content pt-6 ">
        <div class="grid grid-rows-1 grid-cols-2 grid-flow-col gap-4 justify-items-stretch justify-stretch items-center">
            <div class="">
                <?php linkate_posts_index_status_display('scan'); ?>
            </div>
            <div class="">
                <?= linkate_posts_index_progress() ?>
            </div>
        </div>

        <div class="<?= CL_TWC::$CARD ?> mt-6">

            <!-- <h2 class="<?= CL_TWC::$H2 ?>">Настройка сканирования</h2> -->

            <input type="checkbox" id="spoiler_scan" />
            <label for="spoiler_scan" id="label_spoiler_scan" class="hover:bg-gray-100 hover:text-blue-700 focus:z-10 focus:ring-4 focus:ring-gray-100 hover:cursor-pointer"><?php esc_html_e('Настройка сканирования', 'cherrylink'); ?></label>

            <div class="spoiler_scan">

                <hr class="my-4">

                <form method="post" action="">
                    <label class="font-bold" for="vector_custom_fields"><?php esc_html_e('Дополнительные поля для индексирования (по одному на строку):', 'cherrylink'); ?></label>
                    <p class="text-sm text-gray-500 mb-2"><?php esc_html_e('Ключи пользовательских полей (ACF, meta и др.), содержимое которых будет добавлено к тексту статьи при индексировании.', 'cherrylink'); ?></p>
                    <textarea name="vector_custom_fields" id="vector_custom_fields" rows="4"
                        class="mt-1 block w-full rounded border-gray-300 shadow-sm text-sm font-mono"
                        placeholder="my_acf_field&#10;another_field"><?= esc_textarea($options['vector_custom_fields'] ?? '') ?></textarea>
                    <br>
                    <input type="submit" class="<?= CL_TWC::$BTN_SAVE ?> mt-2" name="update_custom_fields" value="<?php esc_attr_e('Сохранить поля', 'cherrylink'); ?>">
                    <?php if (function_exists('wp_nonce_field')) wp_nonce_field('linkate-posts-update-options'); ?>
                </form>
            </div>

        </div>

    </div>
<?php
