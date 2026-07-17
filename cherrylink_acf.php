<?php
/*
 * CherryLink Plugin
 */

// Disable direct access
defined('ABSPATH') || exit;
// Define lib name
define('LINKATE_ACF_LIBRARY', true);

function link_cf_is_base64_encoded($data)
{
    if (preg_match('%^[a-zA-Z0-9/+]*={0,2}$%', $data)) {
        return TRUE;
    } else {
        return FALSE;
    }
};

function link_cf_options_from_post($options, $args)
{
    foreach ($args as $arg) {
        switch ($arg) {
            case 'excluded_cats':
                if (isset($_POST[$arg]) && !empty($_POST[$arg])) {
                    // get the subcategories too
                    if (function_exists('get_term_children')) {
                        $catarray = $_POST[$arg];
                        $catarray = is_array($catarray) ? $catarray : explode(",", $catarray);
                        foreach ($catarray as $cat) {
                            $catarray = array_merge($catarray, get_term_children($cat, 'category'));
                        }
                        $_POST[$arg] = array_unique($catarray);
                    }
                    $options[$arg] = implode(',', $_POST[$arg]);
                } else {
                    $options[$arg] = '';
                }
                break;
            case 'excluded_authors':
            case 'show_customs':
                if (isset($_POST[$arg]) && !empty($_POST[$arg])) {

                    $options[$arg] = is_array($_POST[$arg]) ? implode(',', $_POST[$arg]) : $_POST[$arg];
                } else {
                    $options[$arg] = '';
                }
                break;
            case 'status':
                unset($options['status']);
                if (isset($_POST['status']) && is_array($_POST['status'])) {
                    $options['status']['publish'] = $_POST['status']['publish'];
                    $options['status']['private'] = $_POST['status']['private'];
                    $options['status']['draft'] = $_POST['status']['draft'];
                    $options['status']['future'] = $_POST['status']['future'];
                } else {
                    $options['status']['publish'] = $_POST['status-publish'];
                    $options['status']['private'] = $_POST['status-private'];
                    $options['status']['draft'] = $_POST['status-draft'];
                    $options['status']['future'] = $_POST['status-future'];
                }
                break;
            case 'multilink':
                if (isset($_POST[$arg])) {
                    $options[$arg] = 'checked';
                } else {
                    $options[$arg] = '';
                }
                break;
            case 'link_before':
            case 'link_after':
            case 'term_before':
            case 'term_after':
            case 'link_temp_alt':
            case 'term_temp_alt':
                $options[$arg] = link_cf_is_base64_encoded($_POST[$arg]) ? $_POST[$arg] : base64_encode(urlencode(str_replace("'", "\"", $_POST[$arg])));
                break;
            case 'export':
                //        	parse_str(base64_decode($_POST['export']),$options);
                $options = $_POST['export'];
                parse_str($_POST['export'], $options);
                break;
            default:
                $options[$arg] = trim($_POST[$arg]);
        }
    }
    return $options;
}

function encodeURIComponent($str)
{
    $revert = array('%21' => '!', '%2A' => '*', '%27' => "'", '%28' => '(', '%29' => ')');
    return strtr(rawurlencode($str), $revert);
}


// ========================================================================================= //
// ============================== Output/Template   ============================== //
// ========================================================================================= //


function link_cf_display_relative_links($relative_links = "full")
{
?>
    <tr valign="top">
        <th scope="row"><label for="relative_links"><?php _e('Относительные ссылки', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td>
            <select name="relative_links" id="relative_links">
                <option <?php if ($relative_links == 'full') {
                            echo 'selected="selected"';
                        } ?> value="full">Полный путь (http://domain.ru/page.html)</option>
                <option <?php if ($relative_links == 'no_proto') {
                            echo 'selected="selected"';
                        } ?> value="no_proto">Без протокола (//domain.ru/page.html)</option>
                <option <?php if ($relative_links == 'no_domain') {
                            echo 'selected="selected"';
                        } ?> value="no_domain">Без домена (/page.html)</option>
            </select>
        </td>
        <td><?php link_cf_prepare_tooltip("expert_template_relative_links"); ?></td>
    </tr>
<?php
}

function link_cf_display_limit_ajax($limit_ajax)
{
?>
    <tr valign="top">
        <th scope="row"><label for="limit_ajax"><?php _e('Количество ссылок :', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td><input name="limit_ajax" type="number" id="limit_ajax" style="width: 60px;" value="<?php echo $limit_ajax; ?>" size="2" /></td>
        <td><?php link_cf_prepare_tooltip("expert_panel_limit_ajax"); ?></td>
    </tr>
<?php
}


function link_cf_display_show_private($show_private)
{
?>
    <tr valign="top">
        <th scope="row"><label for="show_private"><?php _e('Показывать защищенные паролем?', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td>
            <select name="show_private" id="show_private">
                <option <?php if ($show_private == 'false') {
                            echo 'selected="selected"';
                        } ?> value="false">Нет</option>
                <option <?php if ($show_private == 'true') {
                            echo 'selected="selected"';
                        } ?> value="true">Да</option>
            </select>
        </td>
    </tr>
<?php
}



function link_cf_display_show_pages($show_pages)
{
    // show_pages values: 'false' = posts only, 'but' = pages only, 'true' = both
    $posts_on = in_array($show_pages, ['false', 'true']);
    $pages_on = in_array($show_pages, ['but',   'true']);
?>
    <tr valign="top">
        <th scope="row"><?php _e('Тип контента:', CHERRYLINK_TEXT_DOMAIN) ?></th>
        <td>
            <input type="hidden" name="show_pages" id="show_pages_val"
                   value="<?= esc_attr($show_pages) ?>">
            <div class="flex flex-wrap gap-2 mt-1" id="show_pages_chips">
                <?php foreach (['posts' => 'Записи', 'pages' => 'Страницы'] as $key => $label) :
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
<?php
}

function link_cf_display_show_custom_posts($show_customs)
{
    $args = array(
        'public'   => true,
        '_builtin' => false
    );
    $types = get_post_types($args, 'objects');
    $output = '';
    if ($types) {
        $turned_on = explode(',', $show_customs);
        foreach ($types as $type) {
            // if (false === in_array($type->name, $hide_types)) {
            if (false === in_array($type->name, $turned_on)) {
                $ischecked = '';
            } else {
                $ischecked = 'checked';
            }
            $output .= "\n\t<tr valign=\"top\"><td>$type->label</td><td><input type=\"checkbox\" name=\"show_customs[]\" value=\"$type->name\" $ischecked /></td></tr>";
            // }
        }
    }
    if (!empty($output)) :
    ?>
        <tr valign="top">
            <th scope="row"><?php _e('Включить в список произвольные типы записей?', CHERRYLINK_TEXT_DOMAIN) ?></th>
            <td>
                <table class="linkateposts-inner-table">
                    <?php
                    echo "\n\t<tr valign=\"top\"><td><strong>Тип записи</strong></td><td><strong>Показать</strong></td></tr>";
                    echo $output;
                    ?>
                </table>
            </td>
        </tr>
    <?php
    endif;
}

function link_cf_display_show_catergory_filter($show_cat_filter = 'false')
{
    ?>
    <tr valign="top">
        <th scope="row"><label for="show_cat_filter"><?php _e('Показать на панели фильтр ссылок по рубрике', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td>
            <select name="show_cat_filter" id="show_cat_filter">
                <option <?php if ($show_cat_filter == 'false') {
                            echo 'selected="selected"';
                        } ?> value="false">Нет</option>
                <option <?php if ($show_cat_filter == 'true') {
                            echo 'selected="selected"';
                        } ?> value="true">Да</option>
            </select>
        </td>
    </tr>
<?php
}
function link_cf_display_max_incoming_links($consider_max_incoming_links, $max_incoming_links)
{
?>
    <tr valign="top">
        <th scope="row"><label for="max_incoming_links"><?php _e('Максимальное число входящих ссылок', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td>
            Скрыть?
            <select name="consider_max_incoming_links" id="consider_max_incoming_links">
                <option <?php if ($consider_max_incoming_links == 'false') {
                            echo 'selected="selected"';
                        } ?> value="false">Нет</option>
                <option <?php if ($consider_max_incoming_links == 'true') {
                            echo 'selected="selected"';
                        } ?> value="true">Да</option>
            </select>
            Количество ссылок
            <input name="max_incoming_links" type="number" min="1" id="max_incoming_links" value="<?php echo htmlspecialchars(stripslashes($max_incoming_links)); ?>" />
        </td>
        </td>
        <td><?php link_cf_prepare_tooltip("expert_panel_max_incoming_links"); ?></td>
    </tr>
<?php
}


function link_cf_display_match_cat($match_cat)
{
?>
    <tr valign="top">
        <th scope="row"><label for="match_cat"><?php _e('Только ссылки из той же рубрики?', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td>
            <select name="match_cat" id="match_cat">
                <option <?php if ($match_cat == 'false') {
                            echo 'selected="selected"';
                        } ?> value="false">Нет</option>
                <option <?php if ($match_cat == 'true') {
                            echo 'selected="selected"';
                        } ?> value="true">Да</option>
            </select>
        </td>
    </tr>
<?php
}



function link_cf_template_image_size($template_image_size)
{
    $sizes = linkate_get_image_sizes();
?>
    <tr valign="top">
        <th scope="row"><label for="template_image_size">Размер изображения для тега {imagesrc} для шаблонов</label></th>
        <td>
            <select name="template_image_size" id="template_image_size">
                <option <?php if (empty($template_image_size)) {
                            echo 'selected="selected"';
                        } ?> value="">Оригинальный размер</option>
                <?php
                foreach ($sizes as $k => $arr) {
                ?>
                    <option <?php if ($template_image_size == $k) echo "selected='selected'"; ?> value='<?php echo $k; ?>'><?php echo $k . " (" . $arr['width'] . "x" . $arr['height'] . ")"; ?></option>
                <?php
                }
                ?>
            </select>
        </td>
        <td><?php link_cf_prepare_tooltip("expert_template_image_size"); ?></td>
    </tr>
<?php
}




function link_cf_display_scheme_export_options()
{

    $hide_types = array('attachment', 'wp_block', 'revision', 'nav_menu_item', 'custom_css', 'oembed_cache', 'user_request', 'customize_changeset', 'post_format', 'nav_menu', 'link_category');
    $args = array(
        'public'   => true,
        // '_builtin' => true
    );
?>
    <div style="display: flex">
        <div>
            <p><strong>Типы записей</strong></p>
            <table class="linkateposts-inner-table">
                <?php
                $types = get_post_types($args, 'objects');
                if ($types) {
                    echo "\n\t<tr valign=\"top\"><td colspan=\"2\"><strong>Типы публикаций</strong></td></tr>";
                    foreach ($types as $type) {
                        if (!in_array($type->name, $hide_types)) {
                            echo "\n\t<tr valign=\"top\"><td>$type->label</td><td><input type=\"checkbox\" name=\"export_types[]\" value=\"$type->name\" checked /></td></tr>";
                        }
                    }
                }
                // $taxonomies = get_taxonomies(array(), 'names');
                // if ($taxonomies) {
                //     echo "\n\t<tr valign=\"top\"><td colspan=\"2\"><strong>Таксономии</strong></td></tr>";
                //     foreach ($taxonomies as $tax) {
                //         if (false === in_array($tax, $hide_types)) {
                //             echo "\n\t<tr valign=\"top\"><td>$tax</td><td><input type=\"checkbox\" name=\"export_types[]\" value=\"$tax\" checked /></td></tr>";
                //         }
                //     }
                // }

                ?>
            </table>
        </div>
        <div style="margin-left: 50px">
            <p><strong>Поля данных</strong></p>
            Ориентация ссылок
            <select name="links_direction" id="links_direction">
                <option value="outgoing" selected>Исходящие</option>
                <option value="incoming">Входящие</option>
            </select>
            <div id="links_direction_outgoing">
                <input name="source_id" type="checkbox" value="cb_source_id" checked>ID источника</input><br>
                <input name="source_type" type="checkbox" value="cb_source_type" checked>Тип источника</input><br>
                <input name="source_cats" type="checkbox" value="cb_source_cats" checked>Рубрики</input><br>
                <input name="source_url" type="checkbox" value="cb_source_url" checked>URL источника</input><br>
                <input name="target_url" type="checkbox" value="cb_target_url" checked>URL цели</input><br>
            </div>
            <div id="links_direction_incoming" style="display:none">
                <input name="target_id" type="checkbox" value="cb_target_id" checked>ID цели</input><br>
                <input name="target_type" type="checkbox" value="cb_target_type" checked>Тип цели</input><br>
                <input name="target_cats" type="checkbox" value="cb_target_cats" checked>Рубрики цели</input><br>
                <input name="target_url" type="checkbox" value="cb_target_url" checked>URL цели</input><br>
                <input name="source_url" type="checkbox" value="cb_source_url" checked>URL источника</input><br>
            </div>
            <input name="ankor" type="checkbox" value="cb_ankor" checked>Анкор</input><br>
            <input name="count_out" type="checkbox" value="cb_count_out" checked>Кол-во исходящих ссылок</input><br>
            <input name="count_in" type="checkbox" value="cb_count_in" checked>Кол-во входящих ссылок</input><br>
            <input name="duplicate_fields" type="checkbox" value="cb_duplicate_fields" checked>Дублировать поля (id, тип, ...)</input><br>

        </div>
    </div>
<?php
}
function link_cf_display_scheme_statistics_options()
{
    $hide_types = array('attachment', 'wp_block', 'revision', 'nav_menu_item', 'custom_css', 'oembed_cache', 'user_request', 'customize_changeset', 'post_format', 'nav_menu', 'link_category');
    $args = array(
        'public'   => true,
        // '_builtin' => false
    );
?>
    <h2>Опции поиска</h2>
    <div style="display: flex">
        <div style="display: inline-flex;">
            <?php
            $types = get_post_types($args, 'objects');
            if ($types) {
                echo "\n\t<table class=\"linkateposts-inner-table\"><tr valign=\"top\"><td colspan=\"2\"><strong>Типы публикаций</strong></td></tr>";
                foreach ($types as $type) {
                    if (!in_array($type->name, $hide_types)) {
                        echo "\n\t<tr valign=\"top\"><td>$type->label</td><td><input type=\"checkbox\" name=\"export_types[]\" value=\"$type->name\" checked /></td></tr>";
                    }
                }
                echo '</table>';
            }
            $statuses = get_post_statuses();
            if ($statuses) {
                echo "\n\t<table class=\"linkateposts-inner-table\"><tr valign=\"top\"><td colspan=\"2\"><strong>Статус публикаций</strong></td></tr>";
                foreach ($statuses as $k => $status) {
                    $checked = $k === 'publish' ? "checked" : "";
                    echo "\n\t<tr valign=\"top\"><td>$status</td><td><input type=\"checkbox\" name=\"export_status[]\" value=\"$k\" " . $checked . " /></td></tr>";
                }
                echo '</table>';
            }
            ?>
        </div>
        <div style="display:none">
            <p><strong>Поля данных</strong></p>
            <input name="source_id" type="checkbox" value="cb_source_id" checked>ID источника</input><br>
            <input name="source_type" type="checkbox" value="cb_source_type" checked>Тип источника</input><br>
            <input name="source_cats" type="checkbox" value="cb_source_cats" checked>Рубрики</input><br>
            <input name="source_url" type="checkbox" value="cb_source_url" checked>URL источника</input><br>
            <input name="target_url" type="checkbox" value="cb_target_url" checked>URL цели</input><br>
            <input name="ankor" type="checkbox" value="cb_ankor" checked>Анкор</input><br>
            <input name="count_out" type="checkbox" value="cb_count_out" checked>Кол-во исходящих ссылок</input><br>
            <input name="count_in" type="checkbox" value="cb_count_in" checked>Кол-во входящих ссылок</input><br>
            <input name="duplicate_fields" type="checkbox" value="cb_duplicate_fields" checked>Дублировать поля (id, тип, ...)</input><br>

        </div>
    </div>
<?php
}


function link_cf_display_authors($excluded_authors)
{
    global $wpdb;
?>
    <tr valign="top">
        <th scope="row"><?php _e('Пояснение к фильтрам авторов и рубрик:', CHERRYLINK_TEXT_DOMAIN) ?></th>
        <td>Ниже вы можете скрыть записи определенных авторов и рубрик. Чтобы видеть все записи, снимите все галочки.</td>
    </tr>
    <tr valign="top">
        <th scope="row"><?php _e('Скрыть записи по автору:', CHERRYLINK_TEXT_DOMAIN) ?></th>
        <td>
            <table class="linkateposts-inner-table">
                <?php
                $users = $wpdb->get_results("SELECT ID, user_login FROM $wpdb->users ORDER BY user_login");
                if ($users) {
                    $excluded = explode(',', $excluded_authors);
                    echo "\n\t<tr valign=\"top\"><td><strong>Имя юзера</strong></td><td><strong>Скрыть</strong></td></tr>";
                    foreach ($users as $user) {
                        if (false === in_array($user->ID, $excluded)) {
                            $ex_ischecked = '';
                        } else {
                            $ex_ischecked = 'checked';
                        }
                        echo "\n\t<tr valign=\"top\"><td>$user->user_login</td><td><input type=\"checkbox\" name=\"excluded_authors[]\" value=\"$user->ID\" $ex_ischecked /></td></tr>";
                    }
                }
                ?>
            </table>
        </td>
    </tr>
<?php
}

function link_cf_display_cats($excluded_cats)
{
    global $wpdb;
?>
    <tr valign="top">
        <th scope="row"><?php _e('Скрыть записи из рубрик:', CHERRYLINK_TEXT_DOMAIN) ?></th>
        <td>
            <table class="linkateposts-inner-table">
                <?php
                if (function_exists("get_categories")) {
                    $categories = get_categories(); //('&hide_empty=1');
                } else {
                    //$categories = $wpdb->get_results("SELECT * FROM $wpdb->categories WHERE category_count <> 0 ORDER BY cat_name");
                    $categories = $wpdb->get_results("SELECT * FROM $wpdb->categories ORDER BY cat_name");
                }
                if ($categories) {
                    echo "\n\t<tr valign=\"top\"><td><strong>Рубрика</strong></td><td><strong>Скрыть</strong></td></tr>";
                    $excluded = explode(',', $excluded_cats);
                    $level = 0;
                    $cats_added = array();
                    $last_parent = 0;
                    $cat_parent = 0;
                    foreach ($categories as $category) {
                        $category->cat_name = esc_html($category->cat_name);
                        if (false === in_array($category->cat_ID, $excluded)) {
                            $ex_ischecked = '';
                        } else {
                            $ex_ischecked = 'checked';
                        }
                        $last_parent = $cat_parent;
                        $cat_parent = $category->category_parent;
                        if ($cat_parent == 0) {
                            $level = 0;
                        } elseif ($last_parent != $cat_parent) {
                            if (in_array($cat_parent, $cats_added)) {
                                $level = $level - 1;
                            } else {
                                $level = $level + 1;
                            }
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
<?php
}


function link_cf_display_status($status)
{
    $statuses = [
        'publish' => 'Опубликованы',
        'private' => 'Личные',
        'draft'   => 'Черновик',
        'future'  => 'Запланированные',
    ];
?>
    <tr valign="top">
        <th scope="row"><?php _e('Статус записей:', CHERRYLINK_TEXT_DOMAIN) ?></th>
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
<?php
}



// now for linkate_posts







function link_cf_prepare_tooltip($anchor)
{
?>
    <div class='cherry-adm-tooltip'><a target="_blank" href="https://seocherry.ru/?page_id=1699#<?= $anchor; ?>" title="Перейти к справке"><img src='<?php echo CHERRYLINK_DIR_URL; ?>img/question-mark.png'></a>
    </div>
<?php
}
