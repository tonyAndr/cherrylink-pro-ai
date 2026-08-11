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
        <div class="<?= \CherryLink\Support\Tailwind::$CARD ?>">
            <h2 class="<?= \CherryLink\Support\Tailwind::$H2 ?>"><?php esc_html_e('Поиск проблем с перелинковкой', 'cherrylink'); ?></h2>
            <p><?php esc_html_e('Нажмите на кнопку "Проверить перелинковку", чтобы найти записи, в которых:', 'cherrylink'); ?></p>
            <ol class="list-inside list-decimal">
                <li><?php esc_html_e('Есть повторяющиеся ссылки;', 'cherrylink'); ?></li>
                <li><?php esc_html_e('Нет входящих ссылок;', 'cherrylink'); ?></li>
                <li><?php esc_html_e('Нет исходящих ссылок.', 'cherrylink'); ?></li>
            </ol>
            <!-- <p>Подробную статистику по перелинковке вы можете скачать в формате CSV с помощью инструмента Экспорт перелинковки на вкладке "Индекс ссылок".</p> -->
            <form id="form_generate_stats" method="post" action="">
                <?php
                // ── link scheme statistics options (post-type + status pickers) ──
                $hide_types = array('attachment', 'wp_block', 'revision', 'nav_menu_item', 'custom_css', 'oembed_cache', 'user_request', 'customize_changeset', 'post_format', 'nav_menu', 'link_category');
                ?>
                <h2><?php esc_html_e('Опции поиска', 'cherrylink'); ?></h2>
                <div style="display: flex">
                    <div style="display: inline-flex;">
                        <?php
                        $types = get_post_types(['public' => true], 'objects');
                        if ($types) {
                            echo "\n\t<table class=\"linkateposts-inner-table\"><tr valign=\"top\"><td colspan=\"2\"><strong>" . esc_html__('Типы публикаций', 'cherrylink') . "</strong></td></tr>";
                            foreach ($types as $type) {
                                if (!in_array($type->name, $hide_types)) {
                                    echo "\n\t<tr valign=\"top\"><td>$type->label</td><td><input type=\"checkbox\" name=\"export_types[]\" value=\"$type->name\" checked /></td></tr>";
                                }
                            }
                            echo '</table>';
                        }
                        $statuses = get_post_statuses();
                        if ($statuses) {
                            echo "\n\t<table class=\"linkateposts-inner-table\"><tr valign=\"top\"><td colspan=\"2\"><strong>" . esc_html__('Статус публикаций', 'cherrylink') . "</strong></td></tr>";
                            foreach ($statuses as $k => $status) {
                                $checked = $k === 'publish' ? 'checked' : '';
                                echo "\n\t<tr valign=\"top\"><td>$status</td><td><input type=\"checkbox\" name=\"export_status[]\" value=\"$k\" " . $checked . " /></td></tr>";
                            }
                            echo '</table>';
                        }
                        ?>
                    </div>
                    <div style="display:none">
                        <p><strong><?php esc_html_e('Поля данных', 'cherrylink'); ?></strong></p>
                        <input name="source_id" type="checkbox" value="cb_source_id" checked><?php esc_html_e('ID источника', 'cherrylink'); ?></input><br>
                        <input name="source_type" type="checkbox" value="cb_source_type" checked><?php esc_html_e('Тип источника', 'cherrylink'); ?></input><br>
                        <input name="source_cats" type="checkbox" value="cb_source_cats" checked><?php esc_html_e('Рубрики', 'cherrylink'); ?></input><br>
                        <input name="source_url" type="checkbox" value="cb_source_url" checked><?php esc_html_e('URL источника', 'cherrylink'); ?></input><br>
                        <input name="target_url" type="checkbox" value="cb_target_url" checked><?php esc_html_e('URL цели', 'cherrylink'); ?></input><br>
                        <input name="ankor" type="checkbox" value="cb_ankor" checked><?php esc_html_e('Анкор', 'cherrylink'); ?></input><br>
                        <input name="count_out" type="checkbox" value="cb_count_out" checked><?php esc_html_e('Кол-во исходящих ссылок', 'cherrylink'); ?></input><br>
                        <input name="count_in" type="checkbox" value="cb_count_in" checked><?php esc_html_e('Кол-во входящих ссылок', 'cherrylink'); ?></input><br>
                        <input name="duplicate_fields" type="checkbox" value="cb_duplicate_fields" checked><?php esc_html_e('Дублировать поля (id, тип, ...)', 'cherrylink'); ?></input><br>
                    </div>
                </div>
                <progress id="link_check_progress"></progress>
                <input id="generate_preview" type="submit" class="<?= \CherryLink\Support\Tailwind::$BTN_NORMAL ?>" name="generate_preview" value="<?php _e('Проверить перелинковку', 'cherrylink') ?>" />
            </form>
            <br>
        </div>

        <div id="cherry_preview_stats_container" style="display:none">
            <div class="<?= \CherryLink\Support\Tailwind::$CARD ?> mt-3">
                <h2 class="<?= \CherryLink\Support\Tailwind::$H2 ?>">
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
        <div class="<?= \CherryLink\Support\Tailwind::$CARD ?> mt-6" style="display: <?php echo $show_options; ?>">

            <input type="checkbox" id="spoiler_scheme" />
            <label for="spoiler_scheme" class="hover:bg-gray-100 hover:text-blue-700 focus:z-10 focus:ring-4 focus:ring-gray-100 hover:cursor-pointer"><?php esc_html_e('Экспорт перелинковки в .CSV', 'cherrylink'); ?></label>

            <div class="spoiler_scheme">

                <form id="form_generate_csv" method="post" action="">
                    <?php
                    // ── link scheme CSV export options (post types + direction/field pickers) ──
                    $hide_types = array('attachment', 'wp_block', 'revision', 'nav_menu_item', 'custom_css', 'oembed_cache', 'user_request', 'customize_changeset', 'post_format', 'nav_menu', 'link_category');
                    ?>
                    <div style="display: flex">
                        <div>
                            <p><strong><?php esc_html_e('Типы записей', 'cherrylink'); ?></strong></p>
                            <table class="linkateposts-inner-table">
                                <?php
                                $types = get_post_types(['public' => true], 'objects');
                                if ($types) {
                                    echo "\n\t<tr valign=\"top\"><td colspan=\"2\"><strong>" . esc_html__('Типы публикаций', 'cherrylink') . "</strong></td></tr>";
                                    foreach ($types as $type) {
                                        if (!in_array($type->name, $hide_types)) {
                                            echo "\n\t<tr valign=\"top\"><td>$type->label</td><td><input type=\"checkbox\" name=\"export_types[]\" value=\"$type->name\" checked /></td></tr>";
                                        }
                                    }
                                }
                                ?>
                            </table>
                        </div>
                        <div style="margin-left: 50px">
                            <p><strong><?php esc_html_e('Поля данных', 'cherrylink'); ?></strong></p>
                            <?php esc_html_e('Ориентация ссылок', 'cherrylink'); ?>
                            <select name="links_direction" id="links_direction">
                                <option value="outgoing" selected><?php esc_html_e('Исходящие', 'cherrylink'); ?></option>
                                <option value="incoming"><?php esc_html_e('Входящие', 'cherrylink'); ?></option>
                            </select>
                            <div id="links_direction_outgoing">
                                <input name="source_id" type="checkbox" value="cb_source_id" checked><?php esc_html_e('ID источника', 'cherrylink'); ?></input><br>
                                <input name="source_type" type="checkbox" value="cb_source_type" checked><?php esc_html_e('Тип источника', 'cherrylink'); ?></input><br>
                                <input name="source_cats" type="checkbox" value="cb_source_cats" checked><?php esc_html_e('Рубрики', 'cherrylink'); ?></input><br>
                                <input name="source_url" type="checkbox" value="cb_source_url" checked><?php esc_html_e('URL источника', 'cherrylink'); ?></input><br>
                                <input name="target_url" type="checkbox" value="cb_target_url" checked><?php esc_html_e('URL цели', 'cherrylink'); ?></input><br>
                            </div>
                            <div id="links_direction_incoming" style="display:none">
                                <input name="target_id" type="checkbox" value="cb_target_id" checked><?php esc_html_e('ID цели', 'cherrylink'); ?></input><br>
                                <input name="target_type" type="checkbox" value="cb_target_type" checked><?php esc_html_e('Тип цели', 'cherrylink'); ?></input><br>
                                <input name="target_cats" type="checkbox" value="cb_target_cats" checked><?php esc_html_e('Рубрики цели', 'cherrylink'); ?></input><br>
                                <input name="target_url" type="checkbox" value="cb_target_url" checked><?php esc_html_e('URL цели', 'cherrylink'); ?></input><br>
                                <input name="source_url" type="checkbox" value="cb_source_url" checked><?php esc_html_e('URL источника', 'cherrylink'); ?></input><br>
                            </div>
                            <input name="ankor" type="checkbox" value="cb_ankor" checked><?php esc_html_e('Анкор', 'cherrylink'); ?></input><br>
                            <input name="count_out" type="checkbox" value="cb_count_out" checked><?php esc_html_e('Кол-во исходящих ссылок', 'cherrylink'); ?></input><br>
                            <input name="count_in" type="checkbox" value="cb_count_in" checked><?php esc_html_e('Кол-во входящих ссылок', 'cherrylink'); ?></input><br>
                            <input name="duplicate_fields" type="checkbox" value="cb_duplicate_fields" checked><?php esc_html_e('Дублировать поля (id, тип, ...)', 'cherrylink'); ?></input><br>
                        </div>
                    </div>
                    <progress id="csv_progress"></progress>
                    <input id="generate_csv" type="submit" class="<?= \CherryLink\Support\Tailwind::$BTN_NORMAL ?>" name="generate_csv" value="<?php _e('Скачать схему в .CSV', 'cherrylink') ?>" />

                </form>
            </div>
        </div>
    </div>
<?php
