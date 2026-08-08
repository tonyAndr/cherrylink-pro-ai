<?php
/**
 * "Статистика" (statistics) settings subpage.
 *
 * @var int    $scheme_rows   cached link-scheme row count
 * @var string $show_options  'block' | 'none' — CSV export card visibility
 */

defined('ABSPATH') || exit;
?>
    <div class=" linkateposts-tab-content pt-3">
        <div class="<?= CL_TWC::$CARD ?>">
            <h2 class="<?= CL_TWC::$H2 ?>"><?php esc_html_e('Поиск проблем с перелинковкой', 'cherrylink'); ?></h2>
            <p><?php esc_html_e('Нажмите на кнопку "Проверить перелинковку", чтобы найти записи, в которых:', 'cherrylink'); ?></p>
            <ol class="list-inside list-decimal">
                <li><?php esc_html_e('Есть повторяющиеся ссылки;', 'cherrylink'); ?></li>
                <li><?php esc_html_e('Нет входящих ссылок;', 'cherrylink'); ?></li>
                <li><?php esc_html_e('Нет исходящих ссылок.', 'cherrylink'); ?></li>
            </ol>
            <!-- <p>Подробную статистику по перелинковке вы можете скачать в формате CSV с помощью инструмента Экспорт перелинковки на вкладке "Индекс ссылок".</p> -->
            <?php //link_cf_prepare_tooltip('');
            ?>
            <form id="form_generate_stats" method="post" action="">
                <?php link_cf_display_scheme_statistics_options(); ?>
                <progress id="link_check_progress"></progress>
                <input id="generate_preview" type="submit" class="<?= CL_TWC::$BTN_NORMAL ?>" name="generate_preview" value="<?php _e('Проверить перелинковку', 'cherrylink') ?>" />
            </form>
            <br>
        </div>

        <div id="cherry_preview_stats_container" style="display:none">
            <div class="<?= CL_TWC::$CARD ?> mt-3">
                <h2 class="<?= CL_TWC::$H2 ?>">
                    <?php esc_html_e('Найдены проблемы с перелинковкой', 'cherrylink'); ?>
                </h2>
                <div class="mb-3" id="cherry_preview_stats_summary" data-linkscount="<?= $scheme_rows ?>"></div>
                <input type="checkbox" id="spoiler_no_incoming" />
                <label for="spoiler_no_incoming" id="label_spoiler_no_incoming"></label>
                <div class="spoiler_no_incoming">
                </div>
                <br>
                <input type="checkbox" id="spoiler_has_repeats" />
                <label for="spoiler_has_repeats" id="label_spoiler_has_repeats"></label>
                <div class="spoiler_has_repeats">
                </div>
                <br>
                <input type="checkbox" id="spoiler_no_outgoing" />
                <label for="spoiler_no_outgoing" id="label_spoiler_no_outgoing"></label>
                <div class="spoiler_no_outgoing">
                </div>
                <br>
                <input type="checkbox" id="spoiler_has_404" />
                <label for="spoiler_has_404" id="label_spoiler_has_404"></label>
                <div class="spoiler_has_404">
                </div>
                <br>
                <input type="checkbox" id="spoiler_has_recursion" />
                <label for="spoiler_has_recursion" id="label_spoiler_has_recursion"></label>
                <div class="spoiler_has_recursion">
                </div>
            </div>
        </div>
        <div class="<?= CL_TWC::$CARD ?> mt-6" style="display: <?php echo $show_options; ?>">

            <input type="checkbox" id="spoiler_scheme" />
            <label for="spoiler_scheme" class="hover:bg-gray-100 hover:text-blue-700 focus:z-10 focus:ring-4 focus:ring-gray-100 hover:cursor-pointer"><?php esc_html_e('Экспорт перелинковки в .CSV', 'cherrylink'); ?></label>

            <div class="spoiler_scheme">

                <form id="form_generate_csv" method="post" action="">
                    <?php link_cf_display_scheme_export_options(); ?>
                    <progress id="csv_progress"></progress>
                    <input id="generate_csv" type="submit" class="<?= CL_TWC::$BTN_NORMAL ?>" name="generate_csv" value="<?php _e('Скачать схему в .CSV', 'cherrylink') ?>" />

                </form>
            </div>
        </div>
    </div>
<?php
