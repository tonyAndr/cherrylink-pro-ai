<?php
/**
 * "Для экспертов" (expert) settings subpage.
 *
 * @var array $options  linkate-posts options
 *
 * The former link_cf_display_* form-row emitters are inlined here (each was
 * single-use); the "?" help tooltip is rendered via the admin/partials/tooltip
 * partial.
 */

defined('ABSPATH') || exit;

$tooltip = static function (string $anchor): void {
    echo (new \CherryLink\Support\View())->render('admin/partials/tooltip', ['anchor' => $anchor]);
};
?>
    <div class="linkateposts-tab-content pt-6">
        <form method="post" action="">
            <div class="grid grid-cols-1 gap-4">
                <div class="pl-6">
                    <div class="grid grid-rows-1 grid-cols-2">
                        <div class="justify-self-start grid gap-2 grid-rows-1 grid-cols-2">
                            <a href="#anchor-editor " class="underline text-base text-blue-400"># <?php esc_html_e('Опции редактора', 'cherrylink'); ?></a>
                            <a href="#anchor-template" class="underline text-base text-blue-400"># <?php esc_html_e('Разметка ссылок', 'cherrylink'); ?></a>
                        </div>
                        <div class="justify-self-end">

                            <input type="submit" class="<?= CL_TWC::$BTN_SAVE ?>" name="update_options_filter" value="<?php _e('Сохранить настройки', 'cherrylink') ?>" />
                            <?php if (function_exists('wp_nonce_field')) wp_nonce_field('linkate-posts-update-options'); ?>
                        </div>
                    </div>
                </div>
                <div class="<?= CL_TWC::$CARD ?>">
                    <h2 id="anchor-editor" class="<?= CL_TWC::$H2 ?>"><?php esc_html_e('Настройки работы в редакторе WP', 'cherrylink'); ?></h2>
                    <table class="optiontable form-table">
                        <?php // ── Количество ссылок (limit_ajax) ── ?>
                        <tr valign="top">
                            <th scope="row"><label for="limit_ajax"><?php _e('Количество ссылок :', 'cherrylink') ?></label></th>
                            <td><input name="limit_ajax" type="number" id="limit_ajax" style="width: 60px;" value="<?php echo esc_attr($options['limit_ajax']); ?>" size="2" /></td>
                            <td><?php $tooltip('expert_panel_limit_ajax'); ?></td>
                        </tr>

                        <?php // ── Тип контента (show_pages) ──
                        // show_pages values: 'false' = posts only, 'but' = pages only, 'true' = both
                        $show_pages = $options['show_pages'];
                        $posts_on = in_array($show_pages, ['false', 'true']);
                        $pages_on = in_array($show_pages, ['but',   'true']);
                        ?>
                        <tr valign="top">
                            <th scope="row"><?php _e('Тип контента:', 'cherrylink') ?></th>
                            <td>
                                <input type="hidden" name="show_pages" id="show_pages_val"
                                       value="<?= esc_attr($show_pages) ?>">
                                <div class="flex flex-wrap gap-2 mt-1" id="show_pages_chips">
                                    <?php foreach (['posts' => esc_html__('Записи', 'cherrylink'), 'pages' => esc_html__('Страницы', 'cherrylink')] as $key => $label) :
                                        $checked = $key === 'posts' ? $posts_on : $pages_on;
                                    ?>
                                    <label class="inline-flex items-center gap-2 cursor-pointer select-none
                                                  px-3 py-1 rounded-full border text-sm font-medium transition-colors
                                                  <?= $checked ? 'bg-slate-700 text-white border-slate-700' : 'bg-white text-gray-600 border-gray-300 hover:border-slate-400' ?>"
                                           data-key="<?= $key ?>">
                                        <input type="checkbox" class="hidden" <?= $checked ? 'checked' : '' ?>
                                               onchange="(function(){
                                                   var chips = document.getElementById('show_pages_chips');
                                                   var p = chips.querySelector('[data-key=posts] input').checked;
                                                   var pg = chips.querySelector('[data-key=pages] input').checked;
                                                   document.getElementById('show_pages_val').value = (p && pg) ? 'true' : (pg ? 'but' : 'false');
                                                   chips.querySelectorAll('label').forEach(function(l){
                                                       var on = l.querySelector('input').checked;
                                                       l.classList.toggle('bg-slate-700', on);
                                                       l.classList.toggle('text-white', on);
                                                       l.classList.toggle('border-slate-700', on);
                                                       l.classList.toggle('bg-white', !on);
                                                       l.classList.toggle('text-gray-600', !on);
                                                       l.classList.toggle('border-gray-300', !on);
                                                   });
                                               })()">
                                        <?= esc_html($label) ?>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                        </tr>

                        <?php // ── Только ссылки из той же рубрики (match_cat) ──
                        $match_cat = $options['match_cat'];
                        ?>
                        <tr valign="top">
                            <th scope="row"><label for="match_cat"><?php _e('Только ссылки из той же рубрики?', 'cherrylink') ?></label></th>
                            <td>
                                <select name="match_cat" id="match_cat">
                                    <option <?php if ($match_cat == 'false') echo 'selected="selected"'; ?> value="false"><?php esc_html_e('Нет', 'cherrylink'); ?></option>
                                    <option <?php if ($match_cat == 'true') echo 'selected="selected"'; ?> value="true"><?php esc_html_e('Да', 'cherrylink'); ?></option>
                                </select>
                            </td>
                        </tr>

                        <?php // ── Статус записей (status) ──
                        $status = $options['status'];
                        $statuses = [
                            'publish' => __('Опубликованы', 'cherrylink'),
                            'private' => __('Личные', 'cherrylink'),
                            'draft'   => __('Черновик', 'cherrylink'),
                            'future'  => __('Запланированные', 'cherrylink'),
                        ];
                        ?>
                        <tr valign="top">
                            <th scope="row"><?php _e('Статус записей:', 'cherrylink') ?></th>
                            <td>
                                <div class="flex flex-wrap gap-2 mt-1">
                                    <?php foreach ($statuses as $key => $label) :
                                        $checked = ($status[$key] ?? 'false') === 'true';
                                    ?>
                                    <label class="inline-flex items-center gap-2 cursor-pointer select-none
                                                  px-3 py-1 rounded-full border text-sm font-medium transition-colors
                                                  <?= $checked ? 'bg-slate-700 text-white border-slate-700' : 'bg-white text-gray-600 border-gray-300 hover:border-slate-400' ?>">
                                        <input type="hidden"  name="status-<?= $key ?>" value="false">
                                        <input type="checkbox" name="status-<?= $key ?>" value="true"
                                               class="hidden" <?= $checked ? 'checked' : '' ?>
                                               onchange="this.previousElementSibling.value = this.checked ? 'true' : 'false';
                                                         this.closest('label').classList.toggle('bg-slate-700', this.checked);
                                                         this.closest('label').classList.toggle('text-white', this.checked);
                                                         this.closest('label').classList.toggle('border-slate-700', this.checked);
                                                         this.closest('label').classList.toggle('bg-white', !this.checked);
                                                         this.closest('label').classList.toggle('text-gray-600', !this.checked);
                                                         this.closest('label').classList.toggle('border-gray-300', !this.checked);">
                                        <?= esc_html($label) ?>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                        </tr>
                    </table>
                    <input type="checkbox" id="spoiler_filter" />
                    <label for="spoiler_filter"><?php esc_html_e('Показать еще...', 'cherrylink'); ?></label>


                    <div class="spoiler_filter">
                        <table class="optiontable form-table">
                            <?php // ── Фильтр по рубрике на панели (show_cat_filter) ──
                            $show_cat_filter = $options['show_cat_filter'];
                            ?>
                            <tr valign="top">
                                <th scope="row"><label for="show_cat_filter"><?php _e('Показать на панели фильтр ссылок по рубрике', 'cherrylink') ?></label></th>
                                <td>
                                    <select name="show_cat_filter" id="show_cat_filter">
                                        <option <?php if ($show_cat_filter == 'false') echo 'selected="selected"'; ?> value="false"><?php esc_html_e('Нет', 'cherrylink'); ?></option>
                                        <option <?php if ($show_cat_filter == 'true') echo 'selected="selected"'; ?> value="true"><?php esc_html_e('Да', 'cherrylink'); ?></option>
                                    </select>
                                </td>
                            </tr>

                            <?php // ── Произвольные типы записей (show_customs) ──
                            $show_customs = $options['show_customs'];
                            $types = get_post_types(['public' => true, '_builtin' => false], 'objects');
                            $output = '';
                            if ($types) {
                                $turned_on = explode(',', $show_customs);
                                foreach ($types as $type) {
                                    $ischecked = in_array($type->name, $turned_on) ? 'checked' : '';
                                    $output .= "\n\t<tr valign=\"top\"><td>$type->label</td><td><input type=\"checkbox\" name=\"show_customs[]\" value=\"$type->name\" $ischecked /></td></tr>";
                                }
                            }
                            if (!empty($output)) :
                            ?>
                            <tr valign="top">
                                <th scope="row"><?php _e('Включить в список произвольные типы записей?', 'cherrylink') ?></th>
                                <td>
                                    <table class="linkateposts-inner-table">
                                        <?php
                                        echo "\n\t<tr valign=\"top\"><td><strong>" . esc_html__('Тип записи', 'cherrylink') . "</strong></td><td><strong>" . esc_html__('Показать', 'cherrylink') . "</strong></td></tr>";
                                        echo $output;
                                        ?>
                                    </table>
                                </td>
                            </tr>
                            <?php endif; ?>

                            <?php // ── Макс. число входящих ссылок (max_incoming_links) ──
                            $consider_max_incoming_links = $options['consider_max_incoming_links'];
                            $max_incoming_links = $options['max_incoming_links'];
                            ?>
                            <tr valign="top">
                                <th scope="row"><label for="max_incoming_links"><?php _e('Максимальное число входящих ссылок', 'cherrylink') ?></label></th>
                                <td>
                                    <?php esc_html_e('Скрыть?', 'cherrylink'); ?>
                                    <select name="consider_max_incoming_links" id="consider_max_incoming_links">
                                        <option <?php if ($consider_max_incoming_links == 'false') echo 'selected="selected"'; ?> value="false"><?php esc_html_e('Нет', 'cherrylink'); ?></option>
                                        <option <?php if ($consider_max_incoming_links == 'true') echo 'selected="selected"'; ?> value="true"><?php esc_html_e('Да', 'cherrylink'); ?></option>
                                    </select>
                                    <?php esc_html_e('Количество ссылок', 'cherrylink'); ?>
                                    <input name="max_incoming_links" type="number" min="1" id="max_incoming_links" value="<?php echo htmlspecialchars(stripslashes($max_incoming_links)); ?>" />
                                </td>
                                <td><?php $tooltip('expert_panel_max_incoming_links'); ?></td>
                            </tr>

                            <?php // ── Скрыть по автору (excluded_authors) ──
                            global $wpdb;
                            $excluded_authors = $options['excluded_authors'];
                            ?>
                            <tr valign="top">
                                <th scope="row"><?php _e('Пояснение к фильтрам авторов и рубрик:', 'cherrylink') ?></th>
                                <td><?php esc_html_e('Ниже вы можете скрыть записи определенных авторов и рубрик. Чтобы видеть все записи, снимите все галочки.', 'cherrylink'); ?></td>
                            </tr>
                            <tr valign="top">
                                <th scope="row"><?php _e('Скрыть записи по автору:', 'cherrylink') ?></th>
                                <td>
                                    <table class="linkateposts-inner-table">
                                        <?php
                                        $users = $wpdb->get_results("SELECT ID, user_login FROM $wpdb->users ORDER BY user_login");
                                        if ($users) {
                                            $excluded = explode(',', $excluded_authors);
                                            echo "\n\t<tr valign=\"top\"><td><strong>" . esc_html__('Имя юзера', 'cherrylink') . "</strong></td><td><strong>" . esc_html__('Скрыть', 'cherrylink') . "</strong></td></tr>";
                                            foreach ($users as $user) {
                                                $ex_ischecked = in_array($user->ID, $excluded) ? 'checked' : '';
                                                echo "\n\t<tr valign=\"top\"><td>$user->user_login</td><td><input type=\"checkbox\" name=\"excluded_authors[]\" value=\"$user->ID\" $ex_ischecked /></td></tr>";
                                            }
                                        }
                                        ?>
                                    </table>
                                </td>
                            </tr>

                            <?php // ── Скрыть по рубрике (excluded_cats) ──
                            $excluded_cats = $options['excluded_cats'];
                            ?>
                            <tr valign="top">
                                <th scope="row"><?php _e('Скрыть записи из рубрик:', 'cherrylink') ?></th>
                                <td>
                                    <table class="linkateposts-inner-table">
                                        <?php
                                        $categories = function_exists('get_categories') ? get_categories() : $wpdb->get_results("SELECT * FROM $wpdb->categories ORDER BY cat_name");
                                        if ($categories) {
                                            echo "\n\t<tr valign=\"top\"><td><strong>" . esc_html__('Рубрика', 'cherrylink') . "</strong></td><td><strong>" . esc_html__('Скрыть', 'cherrylink') . "</strong></td></tr>";
                                            $excluded = explode(',', $excluded_cats);
                                            $level = 0;
                                            $cats_added = array();
                                            $last_parent = 0;
                                            $cat_parent = 0;
                                            foreach ($categories as $category) {
                                                $category->cat_name = esc_html($category->cat_name);
                                                $ex_ischecked = in_array($category->cat_ID, $excluded) ? 'checked' : '';
                                                $last_parent = $cat_parent;
                                                $cat_parent = $category->category_parent;
                                                if ($cat_parent == 0) {
                                                    $level = 0;
                                                } elseif ($last_parent != $cat_parent) {
                                                    $level = in_array($cat_parent, $cats_added) ? $level - 1 : $level + 1;
                                                    $cats_added[] = $cat_parent;
                                                }
                                                if ($level < 0) {
                                                    $level = 0;
                                                }
                                                $pad = str_repeat('&nbsp;', 3 * $level);
                                                echo "\n\t<tr valign=\"top\"><td>$pad$category->cat_name</td><td><input type=\"checkbox\" name=\"excluded_cats[]\" value=\"$category->cat_ID\" $ex_ischecked /></td></tr>";
                                            }
                                        }
                                        ?>
                                    </table>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <input type="submit" class="<?= CL_TWC::$BTN_SAVE ?> mt-6" name="update_options_filter" value="<?php _e('Сохранить настройки', 'cherrylink') ?>" />
                    <?php if (function_exists('wp_nonce_field')) wp_nonce_field('linkate-posts-update-options'); ?>
                </div>
                <div class="<?= CL_TWC::$CARD ?>">
                    <h2 id="anchor-template" class="<?= CL_TWC::$H2 ?>"><?php esc_html_e('Настройки ссылок', 'cherrylink'); ?></h2>
                    <table class="optiontable form-table">
                        <?php // ── Относительные ссылки (relative_links) ──
                        $relative_links = $options['relative_links'];
                        ?>
                        <tr valign="top">
                            <th scope="row"><label for="relative_links"><?php _e('Относительные ссылки', 'cherrylink') ?></label></th>
                            <td>
                                <select name="relative_links" id="relative_links">
                                    <option <?php if ($relative_links == 'full') echo 'selected="selected"'; ?> value="full"><?php esc_html_e('Полный путь (http://domain.ru/page.html)', 'cherrylink'); ?></option>
                                    <option <?php if ($relative_links == 'no_proto') echo 'selected="selected"'; ?> value="no_proto"><?php esc_html_e('Без протокола (//domain.ru/page.html)', 'cherrylink'); ?></option>
                                    <option <?php if ($relative_links == 'no_domain') echo 'selected="selected"'; ?> value="no_domain"><?php esc_html_e('Без домена (/page.html)', 'cherrylink'); ?></option>
                                </select>
                            </td>
                            <td><?php $tooltip('expert_template_relative_links'); ?></td>
                        </tr>
                    </table>
                    <input type="submit" class="<?= CL_TWC::$BTN_SAVE ?> mt-6" name="update_options_output" value="<?php _e('Сохранить настройки', 'cherrylink') ?>" />
                    <?php if (function_exists('wp_nonce_field')) wp_nonce_field('linkate-posts-update-options'); ?>
                </div>
<div class="<?= CL_TWC::$CARD ?>">
                    <h2 class="<?= CL_TWC::$H2 ?>"><?php esc_html_e('Соединение с сервером', 'cherrylink'); ?></h2>
                    <p><?php esc_html_e('Если основной сервер недоступен (например, из-за блокировки провайдером), включите альтернативный маршрут подключения и сохраните настройки.', 'cherrylink'); ?></p>
                    <div style="margin-top:1em">
                        <label class="font-bold" for="use_alt_server"><?php esc_html_e('Альтернативный сервер:', 'cherrylink'); ?>&nbsp;</label>
                        <select id="use_alt_server" name="use_alt_server" class="<?= CL_TWC::$INP_BASE ?>" style="display:inline-block;width:auto">
                            <option value="false" <?php selected(($options['use_alt_server'] ?? 'false'), 'false'); ?>><?php esc_html_e('Выключен', 'cherrylink'); ?></option>
                            <option value="true" <?php selected(($options['use_alt_server'] ?? 'false'), 'true'); ?>><?php esc_html_e('Включён', 'cherrylink'); ?></option>
                        </select>
                        <input type="submit" class="<?= CL_TWC::$BTN_SAVE ?> ml-2" name="update_options_output" value="<?php _e('Сохранить', 'cherrylink') ?>" />
                    </div>
                </div>
                <div class="<?= CL_TWC::$CARD ?>">
                    <input type="checkbox" id="spoiler_debug" />
                    <label for="spoiler_debug" id="label_spoiler_debug" class="hover:bg-gray-100 hover:text-blue-700 focus:z-10 focus:ring-4 focus:ring-gray-100 hover:cursor-pointer">⚙ <?php esc_html_e('Отладка', 'cherrylink'); ?></label>

                    <div class="spoiler_debug">
                        <p><?php esc_html_e('Служебный блок для отладки ошибок.', 'cherrylink'); ?></p>
                        <hr>
                        <div style="display:flex;flex-flow: column; width: 100%;">
                            <div style="display:flex;flex-flow: row; width: 100%; gap: 1em; margin-top:2em; margin-bottom:2em">
                                <input type="text" id="admin_debug_post_id" placeholder="<?php esc_attr_e('ID записи', 'cherrylink'); ?>" />
                                <button id="admin_debug_btn" class="<?= CL_TWC::$BTN_NORMAL ?>"><?php esc_html_e('Проверить', 'cherrylink'); ?></button>
                            </div>

                            <h3><?php esc_html_e('Информация для разработчиков', 'cherrylink'); ?></h3>
                            <textarea id="admin_debug_output_field" style="width: 100%" rows="10"></textarea>
                        </div>
                    </div>
                </div>

            </div>
        </form>
        <div class="mt-4 <?= CL_TWC::$CARD_SPECIAL ?>">
            <h2 id="recovery" class="<?= CL_TWC::$H2 ?>"><?php esc_html_e('Прочие настройки', 'cherrylink'); ?></h2>
            <form method="post" action="">
                <input class="<?= CL_TWC::$BTN_DANGER ?> mt-2" name="truncate_all" type="submit" value="<?php esc_attr_e('Очистить данные сайта в API (потребуется переиндексация)', 'cherrylink'); ?>" onclick="return confirm('<?php echo esc_js(__('Удалить все векторы и граф ссылок для этого сайта из API?', 'cherrylink')); ?>')" />
                <input type="submit" class="<?= CL_TWC::$BTN_DANGER ?> mt-2" name="reset_options" value="<?php _e('Вернуть настройки по умолчанию', 'cherrylink') ?>" />
                <?php if (function_exists('wp_nonce_field')) wp_nonce_field('linkate-posts-update-options'); ?>
            </form>
        </div>
    </div>
<?php
