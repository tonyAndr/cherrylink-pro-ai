<?php
/**
 * "Для экспертов" (expert) settings subpage.
 *
 * @var array $options  linkate-posts options
 *
 * The link_cf_display_* helpers echo their own form rows.
 */

defined('ABSPATH') || exit;
?>
    <div class="linkateposts-tab-content pt-6">
        <form method="post" action="">
            <div class="grid grid-cols-1 gap-4">
                <div class="pl-6">
                    <div class="grid grid-rows-1 grid-cols-2">
                        <div class="justify-self-start grid gap-2 grid-rows-1 grid-cols-2">
                            <a href="#anchor-editor " class="underline text-base text-blue-400"># Опции редактора</a>
                            <a href="#anchor-template" class="underline text-base text-blue-400"># Разметка ссылок</a>
                        </div>
                        <div class="justify-self-end">

                            <input type="submit" class="<?= CL_TWC::$BTN_SAVE ?>" name="update_options_filter" value="<?php _e('Сохранить настройки', CHERRYLINK_TEXT_DOMAIN) ?>" />
                            <?php if (function_exists('wp_nonce_field')) wp_nonce_field('linkate-posts-update-options'); ?>
                        </div>
                    </div>
                </div>
                <div class="<?= CL_TWC::$CARD ?>">
                    <h2 id="anchor-editor" class="<?= CL_TWC::$H2 ?>">Настройки работы в редакторе WP</h2>
                    <table class="optiontable form-table">
                        <?php
                        link_cf_display_limit_ajax($options['limit_ajax']);
                        link_cf_display_show_pages($options['show_pages']);
                        link_cf_display_match_cat($options['match_cat']);
                        link_cf_display_status($options['status']);
                        // link_cf_display_show_private($options['show_private']);
                        ?>
                    </table>
                    <input type="checkbox" id="spoiler_filter" />
                    <label for="spoiler_filter">Показать еще...</label>


                    <div class="spoiler_filter">
                        <table class="optiontable form-table">
                            <?php
                            link_cf_display_show_catergory_filter($options['show_cat_filter']);
                            link_cf_display_show_custom_posts($options['show_customs']);
                            link_cf_display_max_incoming_links($options['consider_max_incoming_links'], $options['max_incoming_links']);
                            link_cf_display_authors($options['excluded_authors']);
                            link_cf_display_cats($options['excluded_cats']);
                            ?>
                        </table>
                    </div>
                    <input type="submit" class="<?= CL_TWC::$BTN_SAVE ?> mt-6" name="update_options_filter" value="<?php _e('Сохранить настройки', CHERRYLINK_TEXT_DOMAIN) ?>" />
                    <?php if (function_exists('wp_nonce_field')) wp_nonce_field('linkate-posts-update-options'); ?>
                </div>
                <div class="<?= CL_TWC::$CARD ?>">
                    <h2 id="anchor-template" class="<?= CL_TWC::$H2 ?>">Настройки ссылок</h2>
                    <table class="optiontable form-table">
                        <?php link_cf_display_relative_links($options['relative_links']); ?>
                    </table>
                    <input type="submit" class="<?= CL_TWC::$BTN_SAVE ?> mt-6" name="update_options_output" value="<?php _e('Сохранить настройки', CHERRYLINK_TEXT_DOMAIN) ?>" />
                    <?php if (function_exists('wp_nonce_field')) wp_nonce_field('linkate-posts-update-options'); ?>
                </div>
<div class="<?= CL_TWC::$CARD ?>">
                    <h2 class="<?= CL_TWC::$H2 ?>">Соединение с сервером</h2>
                    <p>Если основной сервер недоступен (например, из-за блокировки провайдером), включите альтернативный маршрут подключения и сохраните настройки.</p>
                    <div style="margin-top:1em">
                        <label class="font-bold" for="use_alt_server">Альтернативный сервер:&nbsp;</label>
                        <select id="use_alt_server" name="use_alt_server" class="<?= CL_TWC::$INP_BASE ?>" style="display:inline-block;width:auto">
                            <option value="false" <?php selected(($options['use_alt_server'] ?? 'false'), 'false'); ?>>Выключен</option>
                            <option value="true" <?php selected(($options['use_alt_server'] ?? 'false'), 'true'); ?>>Включён</option>
                        </select>
                        <input type="submit" class="<?= CL_TWC::$BTN_SAVE ?> ml-2" name="update_options_output" value="<?php _e('Сохранить', CHERRYLINK_TEXT_DOMAIN) ?>" />
                    </div>
                </div>
                <div class="<?= CL_TWC::$CARD ?>">
                    <input type="checkbox" id="spoiler_debug" />
                    <label for="spoiler_debug" id="label_spoiler_debug" class="hover:bg-gray-100 hover:text-blue-700 focus:z-10 focus:ring-4 focus:ring-gray-100 hover:cursor-pointer">⚙ Отладка</label>

                    <div class="spoiler_debug">
                        <p>Служебный блок для отладки ошибок.</p>
                        <hr>
                        <div style="display:flex;flex-flow: column; width: 100%;">
                            <div style="display:flex;flex-flow: row; width: 100%; gap: 1em; margin-top:2em; margin-bottom:2em">
                                <input type="text" id="admin_debug_post_id" placeholder="ID записи" />
                                <button id="admin_debug_btn" class="<?= CL_TWC::$BTN_NORMAL ?>">Проверить</button>
                            </div>

                            <h3>Информация для разработчиков</h3>
                            <textarea id="admin_debug_output_field" style="width: 100%" rows="10"></textarea>
                        </div>
                    </div>
                </div>

            </div>
        </form>
        <div class="mt-4 <?= CL_TWC::$CARD_SPECIAL ?>">
            <h2 id="recovery" class="<?= CL_TWC::$H2 ?>">Прочие настройки</h2>
            <form method="post" action="">
                <input class="<?= CL_TWC::$BTN_DANGER ?> mt-2" name="truncate_all" type="submit" value="Очистить данные сайта в API (потребуется переиндексация)" onclick="return confirm('Удалить все векторы и граф ссылок для этого сайта из API?')" />
                <input type="submit" class="<?= CL_TWC::$BTN_DANGER ?> mt-2" name="reset_options" value="<?php _e('Вернуть настройки по умолчанию', CHERRYLINK_TEXT_DOMAIN) ?>" />
                <?php if (function_exists('wp_nonce_field')) wp_nonce_field('linkate-posts-update-options'); ?>
            </form>
        </div>
    </div>
<?php
