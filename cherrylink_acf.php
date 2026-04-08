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
            case 'limit':
            case 'skip':
                $options[$arg] = link_cf_check_cardinal($_POST[$arg]);
                break;
            case 'excluded_cats':
            case 'included_cats':
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
            case 'included_authors':
            case 'show_customs':
            case 'suggestions_donors_src':
                if (isset($_POST[$arg]) && !empty($_POST[$arg])) {

                    $options[$arg] = is_array($_POST[$arg]) ? implode(',', $_POST[$arg]) : $_POST[$arg];
                } else {
                    $options[$arg] = '';
                }
                break;
            case 'excluded_posts':
            case 'included_posts':
                if (!isset($_POST[$arg])) {
                    $_POST[$arg] = '';
                }
                $check = explode(',', rtrim($_POST[$arg]));
                $ids = array();
                foreach ($check as $id) {
                    $id = link_cf_check_cardinal($id);
                    if ($id !== 0) $ids[] = $id;
                }
                $options[$arg] = implode(',', array_unique($ids));
                break;
            case 'stripcodes':
                $st = explode("\n", trim($_POST['starttags']));
                $se = explode("\n", trim($_POST['endtags']));
                if (count($st) != count($se)) {
                    $options['stripcodes'] = array(array());
                } else {
                    $num = count($st);
                    for ($i = 0; $i < $num; $i++) {
                        $options['stripcodes'][$i]['start'] = $st[$i];
                        $options['stripcodes'][$i]['end'] = $se[$i];
                    }
                }
                break;
            case 'age':
                if (isset($_POST['age']) && is_array($_POST['age'])) {
                    $options['age']['direction'] = $_POST['age']['direction'];
                    $options['age']['length'] = link_cf_check_cardinal($_POST['age']['length']);
                    $options['age']['duration'] = $_POST['age']['duration'];
                } else {
                    $options['age']['direction'] = $_POST['age-direction'];
                    $options['age']['length'] = link_cf_check_cardinal($_POST['age-length']);
                    $options['age']['duration'] = $_POST['age-duration'];
                }
                break;
            case 'custom':
                if (isset($_POST['custom']) && is_array($_POST['custom'])) {
                    $options['custom']['key'] = $_POST['custom']['key'];
                    $options['custom']['op'] = $_POST['custom']['op'];
                    $options['custom']['value'] = $_POST['custom']['value'];
                } else {
                    $options['custom']['key'] = $_POST['custom-key'];
                    $options['custom']['op'] = $_POST['custom-op'];
                    $options['custom']['value'] = $_POST['custom-value'];
                }
                break;
            case 'sort':
                if (isset($_POST['sort']) && is_array($_POST['sort'])) {
                    $options['sort']['by1'] = $_POST['sort']['by1'];
                    $options['sort']['order1'] = $_POST['sort']['order1'];
                    $options['sort']['case1'] = $_POST['sort']['case1'];
                    $options['sort']['order2'] = $_POST['sort']['order2'];
                    $options['sort']['by2'] = $_POST['sort']['by2'];
                    $options['sort']['case2'] = $_POST['sort']['case2'];
                } else {
                    $options['sort']['by1'] = $_POST['sort-by1'];
                    $options['sort']['order1'] = $_POST['sort-order1'];
                    $options['sort']['case1'] = $_POST['sort-case1'];
                    $options['sort']['order2'] = $_POST['sort-order2'];
                    $options['sort']['by2'] = $_POST['sort-by2'];
                    $options['sort']['case2'] = $_POST['sort-case2'];
                }

                if ($options['sort']['order1'] === 'SORT_ASC') $options['sort']['order1'] = SORT_ASC;
                else $options['sort']['order1'] = SORT_DESC;
                if ($options['sort']['order2'] === 'SORT_ASC') $options['sort']['order2'] = SORT_ASC;
                else $options['sort']['order2'] = SORT_DESC;
                if ($options['sort']['by1'] === '') {
                    $options['sort']['order1'] = SORT_ASC;
                    $options['sort']['case1'] = 'false';
                    $options['sort']['by2'] = '';
                }
                if ($options['sort']['by2'] === '') {
                    $options['sort']['order2'] = SORT_ASC;
                    $options['sort']['case2'] = 'false';
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
            case 'num_terms':
                $options['num_terms'] = $_POST['num_terms'];
                if ($options['num_terms'] < 1) $options['num_terms'] = 50;
                break;

            case 'weight_title':
            case 'weight_content':
            case 'weight_custom':
                $options[$arg] = round((float) $_POST[$arg], 2);
                break;
            case 'multilink':
            case 'compare_seotitle':
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
            case 'crb_temp_before': // For Relevant Block Addon
            case 'crb_temp_link':
            case 'crb_temp_after':
                $options[$arg] = link_cf_is_base64_encoded($_POST[$arg]) ? $_POST[$arg] : base64_encode(urlencode(str_replace("'", "\"", $_POST[$arg])));
                break;
            case 'crb_css_override':
                $options[$arg] = $_POST[$arg];
                break;
            case 'export':
                //        	parse_str(base64_decode($_POST['export']),$options);
                $options = $_POST['export'];
                parse_str($_POST['export'], $options);
                break;
            case 'index_custom_fields':
                $options['index_custom_fields'] = str_replace("\r", "", trim($_POST['index_custom_fields']));
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

function link_cf_check_cardinal($string)
{
    $value = intval($string);
    return ($value > 0) ? $value : 0;
}

function link_cf_get_available_tags($is_term)
{
    $tags = '
		<strong>Доступные теги:</strong>
		<ul class="linkate-available-tags">
		<li><strong>{title}</strong> - Заголовок H1;</li>
		<li><strong>{url}</strong> - адрес ссылки;</li>';

    if (!$is_term) {
        $tags .= '
			<li><strong>{title_seo}</strong> - Из AIOSeo или Yoast;</li>
	        <li><strong>{categorynames}</strong> - категории;</li> 
			<li><strong>{date}</strong> - дата;</li>
			<li><strong>{author}</strong> - автор;</li>
			<li><strong>{postid}</strong> - id поста;</li>
			<li><strong>{imagesrc}</strong> - ссылка на картинку-превью;</li>
			<li><strong>{imgtag}</strong> - готовый HTML тег с картинкой (только для блока ссылок);</li>
			<li><strong>{anons}</strong> - текст анонса.</li>';
    }
    $tags .= '</ul>';
    return $tags;
}

// ========================================================================================= //
// ============================== Output/Template   ============================== //
// ========================================================================================= //

function link_cf_display_multilink($multilink)
{
?>
    <tr valign="top">
        <th scope="row"><label for="multilink"><?php _e('Разрешить множественную вставку ссылок:', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td><input name="multilink" type="checkbox" id="multilink" value="cb_multilink" <?php echo $multilink; ?> /></td>
        <td><?php link_cf_prepare_tooltip("expert_template_multilink"); ?></td>
    </tr>
<?php
}
function link_cf_display_no_selection_action($no_selection_action)
{
?>
    <tr valign="top">
        <th scope="row"><label for="no_selection_action"><?php _e('Если текст не выделен, что делаем?', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td>
            <select name="no_selection_action" id="no_selection_action">
                <option <?php if ($no_selection_action == 'title') {
                            echo 'selected="selected"';
                        } ?> value="title">Вставить в анкор Title Seo</option>
                <option <?php if ($no_selection_action == 'h1') {
                            echo 'selected="selected"';
                        } ?> value="h1">Вставить в анкор Заголовок H1</option>
                <option <?php if ($no_selection_action == 'placeholder') {
                            echo 'selected="selected"';
                        } ?> value="placeholder">Вставить в анкор заглушку ТЕКСТ_ССЫЛКИ</option>
                <option <?php if ($no_selection_action == 'empty') {
                            echo 'selected="selected"';
                        } ?> value="empty">Ничего (будет вставлен 1 пробел)</option>
            </select>
        </td>
    </tr>
<?php
}
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

function link_cf_display_omit_current_post($omit_current_post)
{
?>
    <tr valign="top">
        <th scope="row"><label for="omit_current_post"><?php _e('Скрыть ссылку на текущий пост?', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td>
            <select name="omit_current_post" id="omit_current_post">
                <option <?php if ($omit_current_post == 'false') {
                            echo 'selected="selected"';
                        } ?> value="false">Нет</option>
                <option <?php if ($omit_current_post == 'true') {
                            echo 'selected="selected"';
                        } ?> value="true">Да</option>
            </select>
        </td>
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
function link_cf_display_suggestions_switch_action($suggestions_switch_action)
{
?>
    <tr valign="top">
        <th scope="row"><label for="suggestions_switch_action"><?php _e('Быстрые действия в подсказках: переход к анкору в тексте при наведении мышкой, вставка ссылки по клику на элемент', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td>
            <select name="suggestions_switch_action" id="suggestions_switch_action">
                <option <?php if ($suggestions_switch_action == 'false') {
                            echo 'selected="selected"';
                        } ?> value="false">Нет</option>
                <option <?php if ($suggestions_switch_action == 'true') {
                            echo 'selected="selected"';
                        } ?> value="true">Да</option>
            </select>
        </td>
    </tr>
<?php
}

function link_cf_display_suggestions_donors($suggestions_donors_src, $suggestions_donors_join)
{
?>
    <tr valign="top">
        <th scope="row"><label for="suggestions_donors_src"><?php _e('Доноры слов/фраз для подсказок анкоров', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td>
            <table class="linkateposts-inner-table">
                <?php
                $opts = array('title', 'content');
                $turned_on = explode(',', $suggestions_donors_src);
                echo "\n\t<tr valign=\"top\"><td><strong>Источник</strong></td><td>Включить?</td></tr>";
                foreach ($opts as $opt) {
                    if (false === in_array($opt, $turned_on)) {
                        $ischecked = '';
                    } else {
                        $ischecked = 'checked';
                    }
                    echo "\n\t<tr valign=\"top\"><td>$opt</td><td><input type=\"checkbox\" name=\"suggestions_donors_src[]\" value=\"$opt\" $ischecked /></td></tr>";
                }
                ?>
            </table>
        </td>
    </tr>
    <tr valign="top">
        <th scope="row"><label for="suggestions_donors_join"><?php _e('Что делать с донорами для подсказок?', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td>
            <select name="suggestions_donors_join" id="suggestions_donors_join">
                <option <?php if ($suggestions_donors_join == 'join') {
                            echo 'selected="selected"';
                        } ?> value="join">Дополнить друг друга (берем все слова = больше подсказок)</option>
                <option <?php if ($suggestions_donors_join == 'intersection') {
                            echo 'selected="selected"';
                        } ?> value="intersection">Выбрать только общие слова (пересечение = меньше подсказок)</option>
            </select>
        </td>
        <td><?php link_cf_prepare_tooltip("Пример:<br>
            У нас есть 2 поля, которые содержат слова:
            <ol>
            <li>Заголовок (Н1) - [ипотека, квартира, дом]</li>
            <li>Контент (текст записи) - [кредит, ипотека, документы, квартира]</li>
            </ol>
            Если мы их объединим, то в подсказках будут все уникальные слова:<br>
            <strong>[ипотека, квартира, дом, документы, кредит]</strong>
            <br><br>
            При пересечении (ищем общие слова):<br>
            <strong>[квартира, ипотека] - только эти слова встретились в обоих полях одновременно.</strong>
            <br><br>
            Если какое-либо из полей пустое, то оно не учитывается."); ?></td>
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
function link_cf_display_quickfilter_dblclick($quickfilter_dblclick)
{
?>
    <tr valign="top">
        <th scope="row"><label for="quickfilter_dblclick"><?php _e('При выделении слова в редакторе вставлять его в поле быстрого фильтра автоматически <span style="color: red">(classic editor)</span>', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td>
            <select name="quickfilter_dblclick" id="quickfilter_dblclick">
                <option <?php if ($quickfilter_dblclick == 'false') {
                            echo 'selected="selected"';
                        } ?> value="false">Нет</option>
                <option <?php if ($quickfilter_dblclick == 'true') {
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

function link_cf_display_singleword_suggestions($singleword_suggestions)
{
?>
    <tr valign="top">
        <th scope="row"><label for="singleword_suggestions"><?php _e('Предлагать однословные подсказки анкоров', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td>
            <select name="singleword_suggestions" id="singleword_suggestions">
                <option <?php if ($singleword_suggestions == 'false') {
                            echo 'selected="selected"';
                        } ?> value="false">Нет</option>
                <option <?php if ($singleword_suggestions == 'true') {
                            echo 'selected="selected"';
                        } ?> value="true">Да</option>
            </select>
        </td>
    </tr>
<?php
}

function link_cf_display_match_author($match_author)
{
?>
    <tr valign="top">
        <th scope="row"><label for="match_author"><?php _e('Только ссылки на посты от того же автора?', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td>
            <select name="match_author" id="match_author">
                <option <?php if ($match_author == 'false') {
                            echo 'selected="selected"';
                        } ?> value="false">Нет</option>
                <option <?php if ($match_author == 'true') {
                            echo 'selected="selected"';
                        } ?> value="true">Да</option>
            </select>
        </td>
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

function link_cf_display_match_tags($match_tags)
{
?>
    <tr valign="top">
        <th scope="row"><label for="match_tags"><?php _e('Ссылки с совпадающими метками (поле для ввода ниже)', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td>
            <select name="match_tags" id="match_tags">
                <option <?php if ($match_tags == 'false') {
                            echo 'selected="selected"';
                        } ?> value="false">Все равно</option>
                <option <?php if ($match_tags == 'any') {
                            echo 'selected="selected"';
                        } ?> value="any">Один из перечесленных</option>
                <option <?php if ($match_tags == 'all') {
                            echo 'selected="selected"';
                        } ?> value="all">Все обязательно</option>
            </select>
        </td>
    </tr>
<?php
}

function link_cf_display_anons_len($len)
{
?>
    <tr valign="top">
        <th scope="row"><label for="anons_len"><?php _e('Длина анонса в символах (тег {anons}):', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td><input name="anons_len" type="number" min="0" id="anons_len" value="<?php echo htmlspecialchars(stripslashes($len)); ?>" /></td>
        <td><?php link_cf_prepare_tooltip("expert_template_anons_len"); ?></td>
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

function link_cf_display_output_template($output_template)
{
?>
    <tr valign="top">
        <th scope="row"><label for="output_template"><?php _e('Заголовок ссылок в списке:', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td>
            <select name="output_template" id="output_template">
                <option <?php if ($output_template == 'h1') {
                            echo 'selected="selected"';
                        } ?> value="h1">H1 - заголовок записи</option>
                <option <?php if ($output_template == 'seotitle') {
                            echo 'selected="selected"';
                        } ?> value="seotitle">SEO Title</option>
            </select>
        </td>
        <td><?php link_cf_prepare_tooltip("expert_panel_output_template"); ?></td>
    </tr>
<?php
}

function link_cf_display_replace_template($link_before, $link_after, $link_temp_alt)
{
?>
    <tr valign="top">
        <th scope="row"><label for="link_before"><?php _e('Вывод ссылки перед выделенным текстом:', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td><textarea name="link_before" id="link_before" rows="4" cols="38"><?php echo htmlspecialchars(stripslashes(urldecode(base64_decode($link_before)))); ?></textarea></td>
        <td><?php link_cf_prepare_tooltip("expert_template_link_before_after"); ?></td>
    </tr>
    <tr valign="top">
        <th scope="row"><label for="link_after"><?php _e('Вывод после выделенного текста:', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td><textarea name="link_after" id="link_after" rows="4" cols="38"><?php echo htmlspecialchars(stripslashes(urldecode(base64_decode($link_after)))); ?></textarea></td>
    </tr>

    <!-- <tr valign="top">
        <th scope="row"><label for="link_temp_alt"><?php _e('Альтернативный шаблон:', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td><textarea name="link_temp_alt" id="link_temp_alt" rows="4" cols="38"><?php echo htmlspecialchars(stripslashes(urldecode(base64_decode($link_temp_alt)))); ?></textarea></td>
        <td><?php link_cf_prepare_tooltip("Альтернативный шаблон будет использован, если нажата комбинация CTRL/CMD+Click. Код в данном поле дан для примера, меняйте его по своему усмотрению."); ?></td>
    </tr> -->
<?php
}

function link_cf_display_replace_term_template($term_before, $term_after, $term_temp_alt)
{
?>
    <tr valign="top">
        <th scope="row"><label for="term_before"><?php _e('Вывод ссылки перед выделенным текстом:', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td><textarea name="term_before" id="term_before" rows="4" cols="38"><?php echo htmlspecialchars(stripslashes(urldecode(base64_decode($term_before)))); ?></textarea></td>
        <td><?php link_cf_prepare_tooltip("expert_template_term"); ?></td>
    </tr>
    <tr valign="top">
        <th scope="row"><label for="term_after"><?php _e('Вывод после выделенного текста:', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td><textarea name="term_after" id="term_after" rows="4" cols="38"><?php echo htmlspecialchars(stripslashes(urldecode(base64_decode($term_after)))); ?></textarea></td>
    </tr>
    <!-- <tr valign="top">
        <th scope="row"><label for="term_temp_alt"><?php _e('Альтернативный шаблон:', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td><textarea name="term_temp_alt" id="term_temp_alt" rows="4" cols="38"><?php echo htmlspecialchars(stripslashes(urldecode(base64_decode($term_temp_alt)))); ?></textarea></td>
        <td><?php link_cf_prepare_tooltip("Альтернативный шаблон будет использован, если нажата комбинация CTRL/CMD+Click. Код в данном поле дан для примера, меняйте его по своему усмотрению."); ?></td>
    </tr> -->
<?php
}

function link_cf_display_tag_str($tag_str)
{
?>
    <tr valign="top">
        <th scope="row"><label for="tag_str"><?php _e('Совпадающие метки:<br />(a,b _через запятую_, чтобы совпала любая из перечисленных, a+b _через плюс_, чтобы совпали все метки)', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td><input name="tag_str" type="text" id="tag_str" value="<?php echo $tag_str; ?>" size="40" /></td>
    </tr>
<?php
}

function link_cf_display_excluded_posts($excluded_posts)
{
?>
    <tr valign="top">
        <th scope="row"><label for="excluded_posts"><?php _e('Исключить записи с ID (через запятую):', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td><input name="excluded_posts" type="text" id="excluded_posts" value="<?php echo $excluded_posts; ?>" size="40" /> <?php _e('', CHERRYLINK_TEXT_DOMAIN); ?></td>
    </tr>
<?php
}

function link_cf_display_included_posts($included_posts)
{
?>
    <tr valign="top">
        <th scope="row"><label for="included_posts"><?php _e('Только записи из списка ID (через запятую):', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td><input name="included_posts" type="text" id="included_posts" value="<?php echo $included_posts; ?>" size="40" /> <?php _e('', CHERRYLINK_TEXT_DOMAIN); ?></td>
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
function link_cf_display_sidebar()
{
    $options = get_option('linkate-posts', []);
    // $actLeft = '';
    if (
        isset($options['hash_last_status'])
        && $options['hash_last_status']
        && isset($options['activations_left'])
        && $options['activations_left'] > 0
    ) {
        // $actLeft = '<hr><p>Оставшееся количество активаций на вашем ключе: <strong>' . $options['activations_left'] . '</strong>.</p>';
    }
?>
    <div class="linkateposts-admin-sidebar">
        <div class="plugin-update-warning"></div>
        <?php linkate_posts_license_field(); ?>
        <div class="sb-news">
            <h2>Поддержка остановлена!</h2>
            <p>Текущая версия плагина больше не поддерживается и обновления приходить не будут. Вы можете использовать CherryLink и ваш лицензионный ключ на ваших сайтах в рамках купленной лицензии.</p>
            <p><strong>Новый плагин CherryLink Pro доступен на нашем сайте: <a href="https://seocherry.ru/" target="_blank">SeoCherry.ru</a>.</strong></p>
        </div>
        <div class="sb-info">
            <h2>Есть вопрос?</h2>
            <p>Если есть вопросы о работе плагина - пишите в <a href="https://t.me/joinchat/HCjIHgtC9ePAkJOP1V_cPg" target="_blank">телеграм-чат</a> или на почту <strong>mail@seocherry.ru</strong>. </p>
            <p>Другие плагины разработчика можно найти на сайте проекта <a href="https://seocherry.ru/" target="_blank">SeoCherry.ru</a>.</p>
        </div>
    </div>
<?php
}

function link_cf_display_authors($excluded_authors, $included_authors)
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
                    $included = explode(',', $included_authors);
                    echo "\n\t<tr valign=\"top\"><td><strong>Имя юзера</strong></td><td><strong>Скрыть</strong></td></tr>";
                    foreach ($users as $user) {
                        if (false === in_array($user->ID, $excluded)) {
                            $ex_ischecked = '';
                        } else {
                            $ex_ischecked = 'checked';
                        }
                        // if (false === in_array($user->ID, $included)) {
                        //     $in_ischecked = '';
                        // } else {
                        //     $in_ischecked = 'checked';
                        // }
                        echo "\n\t<tr valign=\"top\"><td>$user->user_login</td><td><input type=\"checkbox\" name=\"excluded_authors[]\" value=\"$user->ID\" $ex_ischecked /></td></tr>";
                    }
                }
                ?>
            </table>
        </td>
    </tr>
<?php
}

function link_cf_display_cats($excluded_cats, $included_cats)
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
                    $included = explode(',', $included_cats);
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
                        if (false === in_array($category->cat_ID, $included)) {
                            $in_ischecked = '';
                        } else {
                            $in_ischecked = 'checked';
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


function link_cf_display_age($age)
{
?>
    <tr valign="top">
        <th scope="row"><label for="age-direction"><?php _e('Скрыть записи по возрасту:', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td>

            <select name="age-direction" id="age-direction">
                <option <?php if ($age['direction'] == 'before') {
                            echo 'selected="selected"';
                        } ?> value="before">младше</option>
                <option <?php if ($age['direction'] == 'after') {
                            echo 'selected="selected"';
                        } ?> value="after">старше</option>
                <option <?php if ($age['direction'] == 'none') {
                            echo 'selected="selected"';
                        } ?> value="none">-----</option>
            </select>
            <input style="vertical-align: middle; width: 60px;" name="age-length" type="number" id="age-length" value="<?php echo $age['length']; ?>" size="4" />

            <select name="age-duration" id="age-duration">
                <option <?php if ($age['duration'] == 'day') {
                            echo 'selected="selected"';
                        } ?> value="day">дней</option>
                <option <?php if ($age['duration'] == 'month') {
                            echo 'selected="selected"';
                        } ?> value="month">месяцев</option>
                <option <?php if ($age['duration'] == 'year') {
                            echo 'selected="selected"';
                        } ?> value="year">лет</option>
            </select>


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

function link_cf_display_custom($custom)
{
?>
    <tr valign="top">
        <th scope="row"><?php _e('Совпадающие по кастомному полю:', CHERRYLINK_TEXT_DOMAIN) ?></th>
        <td>
            <table>
                <tr>
                    <td style="border-bottom-width: 0">Имя поля</td>
                    <td style="border-bottom-width: 0"></td>
                    <td style="border-bottom-width: 0">Значение</td>
                </tr>
                <tr>
                    <td style="border-bottom-width: 0"><input name="custom-key" type="text" id="custom-key" value="<?php echo $custom['key']; ?>" size="20" /></td>
                    <td style="border-bottom-width: 0">
                        <select name="custom-op" id="custom-op">
                            <option <?php if ($custom['op'] == '=') {
                                        echo 'selected="selected"';
                                    } ?> value="=">=</option>
                            <option <?php if ($custom['op'] == '!=') {
                                        echo 'selected="selected"';
                                    } ?> value="!=">!=</option>
                            <option <?php if ($custom['op'] == '>') {
                                        echo 'selected="selected"';
                                    } ?> value=">">></option>
                            <option <?php if ($custom['op'] == '>=') {
                                        echo 'selected="selected"';
                                    } ?> value=">=">>=</option>
                            <option <?php if ($custom['op'] == '<') {
                                        echo 'selected="selected"';
                                    } ?> value="<">
                                << /option>
                            <option <?php if ($custom['op'] == '<=') {
                                        echo 'selected="selected"';
                                    } ?> value="<=">
                                <=< /option>
                            <option <?php if ($custom['op'] == 'LIKE') {
                                        echo 'selected="selected"';
                                    } ?> value="LIKE">LIKE</option>
                            <option <?php if ($custom['op'] == 'NOT LIKE') {
                                        echo 'selected="selected"';
                                    } ?> value="NOT LIKE">NOT LIKE</option>
                            <option <?php if ($custom['op'] == 'REGEXP') {
                                        echo 'selected="selected"';
                                    } ?> value="REGEXP">REGEXP</option>
                            <option <?php if ($custom['op'] == 'EXISTS') {
                                        echo 'selected="selected"';
                                    } ?> value="EXISTS">EXISTS</option>
                        </select>
                    </td>
                    <td style="border-bottom-width: 0"><input name="custom-value" type="text" id="custom-value" value="<?php echo $custom['value']; ?>" size="20" /></td>
                </tr>
            </table>
        </td>
    </tr>
<?php
}

function link_cf_display_sort($sort)
{
    global $wpdb;
?>
    <tr valign="top">
        <th scope="row"><?php _e('Сортировать по:<br />можно оставить пустым для сортировки по умолчанию', CHERRYLINK_TEXT_DOMAIN) ?></th>
        <td>
            <table>
                <tr>
                    <td style="border-bottom-width: 0"></td>
                    <td style="border-bottom-width: 0">Тег <?php link_cf_prepare_tooltip(link_cf_get_available_tags(false)); ?></td>
                    <td style="border-bottom-width: 0">Порядок</td>
                    <td style="border-bottom-width: 0">Заглавные буквы</td>
                </tr>
                <tr>
                    <td style="border-bottom-width: 0">Условие №1</td>
                    <td style="border-bottom-width: 0"><input name="sort-by1" type="text" id="sort-by1" value="<?php echo $sort['by1']; ?>" size="20" /></td>
                    <td style="border-bottom-width: 0">
                        <select name="sort-order1" id="sort-order1">
                            <option <?php if ($sort['order1'] == SORT_ASC) {
                                        echo 'selected="selected"';
                                    } ?> value="SORT_ASC">По возрастанию</option>
                            <option <?php if ($sort['order1'] == SORT_DESC) {
                                        echo 'selected="selected"';
                                    } ?> value="SORT_DESC">По убыванию</option>
                        </select>
                    </td>
                    <td style="border-bottom-width: 0">
                        <select name="sort-case1" id="sort-case1">
                            <option <?php if ($sort['case1'] == 'false') {
                                        echo 'selected="selected"';
                                    } ?> value="false">чувствительный</option>
                            <option <?php if ($sort['case1'] == 'true') {
                                        echo 'selected="selected"';
                                    } ?> value="true">без разницы</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <td style="border-bottom-width: 0">Условие №2</td>
                    <td style="border-bottom-width: 0"><input name="sort-by2" type="text" id="sort-by2" value="<?php echo $sort['by2']; ?>" size="20" /></td>
                    <td style="border-bottom-width: 0">
                        <select name="sort-order2" id="sort-order2">
                            <option <?php if ($sort['order2'] == SORT_ASC) {
                                        echo 'selected="selected"';
                                    } ?> value="SORT_ASC">По возрастанию</option>
                            <option <?php if ($sort['order2'] == SORT_DESC) {
                                        echo 'selected="selected"';
                                    } ?> value="SORT_DESC">По убыванию</option>
                        </select>
                    </td>
                    <td style="border-bottom-width: 0">
                        <select name="sort-case2" id="sort-case2">
                            <option <?php if ($sort['case2'] == 'false') {
                                        echo 'selected="selected"';
                                    } ?> value="false">чувствительный</option>
                            <option <?php if ($sort['case2'] == 'true') {
                                        echo 'selected="selected"';
                                    } ?> value="true">без разницы</option>
                        </select>
                        <br>

                    </td>
                </tr>
            </table>
        </td>
    </tr>
<?php
}

// now for linkate_posts
function link_cf_display_num_term_length_limit($term_length_limit)
{
?>
    <tr valign="top">
        <th scope="row"><label for="term_length_limit"><?php _e('Не учитывать слова короче (кол-во букв, включительно):', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td><input name="term_length_limit" type="number" id="term_length_limit" style="width: 60px;" value="<?php echo $term_length_limit; ?>" size="3" min="0" /></td>
        <td><?php link_cf_prepare_tooltip("Этот параметр позволяет отсеить различные союзы, предлоги и пр. Все, что короче или равно по длине заданному значению просто игнорируется алгоритмом.<br><br>Если вы хотите разрешить некоторые короткие слова или аббревиатуры, то добавьте их в белый список в редакторе стоп-слов."); ?></td>
    </tr>
<?php
}


function link_cf_display_num_terms($num_terms)
{
?>
    <tr valign="top">
        <th scope="row"><label for="num_terms"><?php _e('Количество ключевых слов для определения схожести:', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td><input name="num_terms" type="number" id="num_terms" style="width: 60px;" value="<?php echo $num_terms; ?>" size="3" /></td>
        <td><?php link_cf_prepare_tooltip("expert_relevancy_num_terms"); ?></td>
    </tr>
<?php
}

function link_cf_display_weights($options)
{
?>
    <tr valign="top">
        <th scope="row"><?php _e('Значимость полей:', CHERRYLINK_TEXT_DOMAIN) ?></th>
        <td>
            <label for="weight_content">содержание записи: </label><input name="weight_content" type="number" style="width: 60px;" id="weight_content" value="<?php echo round(100 * $options['weight_content']); ?>" size="3" /> %
            <br><br>
            <label for="weight_title">заголовок записи: </label><input name="weight_title" type="number" style="width: 60px;" id="weight_title" value="<?php echo round(100 * $options['weight_title']); ?>" size="3" /> %
            <br><br>
            <label for="weight_custom">произвольные поля: </label><input name="weight_custom" type="number" style="width: 60px;" id="weight_custom" value="<?php echo round(100 * $options['weight_custom']); ?>" size="3" /> %
        </td>
        <td><?php link_cf_prepare_tooltip("expert_relevancy_weights"); ?></td>
    </tr>
<?php
}
function link_cf_display_stopwords()
{
?>
    <h3><label for="custom_stopwords"><?php _e('Ваши стоп-слова:', CHERRYLINK_TEXT_DOMAIN) ?></label></h3>
    <textarea name="custom_stopwords" id="custom_stopwords" rows="6" cols="38" placeholder="слово1&#10;слово2"></textarea>
    <br><br>
    <input name="is_white" type="checkbox" id="is_white" value="is_white" /><label for="is_white"><?php _e('Добавить в белый список', CHERRYLINK_TEXT_DOMAIN) ?></label>
    <br><br>
    <? link_cf_prepare_tooltip("scan_stopwords_white"); ?>
<?php
}

function link_cf_display_match_against_title($match_all_against_title)
{
?>
    <tr valign="top">
        <th scope="row"><label for="match_all_against_title"><?php _e('Одностороннее сравнение с тайтлом?', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td>
            <select name="match_all_against_title" id="match_all_against_title">
                <option <?php if ($match_all_against_title == 'false') {
                            echo 'selected="selected"';
                        } ?> value="false">Нет</option>
                <option <?php if ($match_all_against_title == 'true') {
                            echo 'selected="selected"';
                        } ?> value="true">Да</option>
            </select>
        <td><?php link_cf_prepare_tooltip("Берем слова из _текста_ И _тайтла_, который редактируем и сравниваем <strong>только</strong> с _тайтлом_ других статей)"); ?></td>
        </td>
    </tr>
<?php
}

function link_cf_display_ignore_relevance($ignore_relevance)
{
?>
    <tr valign="top">
        <th scope="row"><label for="ignore_relevance"><?php _e('Игнорировать релевантность статей (вывести все подряд)', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td>
            <select name="ignore_relevance" id="ignore_relevance">
                <option <?php if ($ignore_relevance == 'false') {
                            echo 'selected="selected"';
                        } ?> value="false">Нет</option>
                <option <?php if ($ignore_relevance == 'true') {
                            echo 'selected="selected"';
                        } ?> value="true">Да</option>
            </select>
        </td>
        <td><?php link_cf_prepare_tooltip("expert_relevancy_ignore"); ?></td>
    </tr>
<?php
}
function link_cf_display_clean_suggestions_stoplist($clean_suggestions_stoplist)
{
?>
    <tr valign="top">
        <th scope="row"><label for="clean_suggestions_stoplist"><?php _e('Применить стоп-слова к подсказкам анкоров?', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td>
            <select name="clean_suggestions_stoplist" id="clean_suggestions_stoplist">
                <option <?php if ($clean_suggestions_stoplist == 'false') {
                            echo 'selected="selected"';
                        } ?> value="false">Нет</option>
                <option <?php if ($clean_suggestions_stoplist == 'true') {
                            echo 'selected="selected"';
                        } ?> value="true">Да</option>
            </select>
        <td><?php link_cf_prepare_tooltip("Фильтрация подсказок по черным и белым спискам стоп-слов."); ?></td>
        </td>
    </tr>
<?php
}
function link_cf_display_use_stemming($use_stemming)
{
?>
    <tr valign="top">
        <th scope="row"><label for="use_stemming"><?php _e('Использовать стемминг и стоп-слова', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td>
            <select name="use_stemming" id="use_stemming">
                <option <?php if ($use_stemming == 'false') {
                            echo 'selected="selected"';
                        } ?> value="false">Нет</option>
                <option <?php if ($use_stemming == 'true') {
                            echo 'selected="selected"';
                        } ?> value="true">Да</option>
            </select>
        <td><?php link_cf_prepare_tooltip("scan_use_stemming"); ?></td>
        </td>
    </tr>
<?php
}

function link_cf_display_index_custom_fields($index_custom_fields)
{
?>
    <tr valign="top">
        <th scope="row"><label for="index_custom_fields"><?php _e('Сканировать текст из произвольных полей', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td>
            <textarea name="index_custom_fields" id="index_custom_fields" rows="4" cols="38" placeholder="my_custom_keywords&#10;acf_field1&#10;..."><?php echo $index_custom_fields; ?></textarea>
            <p>Ввeдите названия полей каждое с новой строки</p>
        </td>
        <td><?php link_cf_prepare_tooltip("scan_index_custom_fields"); ?></td>
    </tr>
<?php
}

function link_cf_display_seo_meta_source($seo_meta_source = "none")
{
?>
    <tr valign="top">
        <th scope="row"><label for="seo_meta_source"><?php _e('Использовать SEO поля', CHERRYLINK_TEXT_DOMAIN) ?></label></th>
        <td>
            <select name="seo_meta_source" id="seo_meta_source">
                <option <?php if ($seo_meta_source == 'none') {
                            echo 'selected="selected"';
                        } ?> value="none">Нет</option>
                <option <?php if ($seo_meta_source == 'yoast') {
                            echo 'selected="selected"';
                        } ?> value="yoast">Yoast SEO</option>
                <option <?php if ($seo_meta_source == 'aioseo') {
                            echo 'selected="selected"';
                        } ?> value="aioseo">AIO SEO</option>
                <option <?php if ($seo_meta_source == 'rankmath') {
                            echo 'selected="selected"';
                        } ?> value="rankmath">RankMath</option>
            </select>
        </td>
        <td><?php link_cf_prepare_tooltip("scan_seo_meta_source"); ?></td>
    </tr>
<?php
}

function link_cf_prepare_tooltip($anchor)
{
?>
    <div class='cherry-adm-tooltip'><a target="_blank" href="https://seocherry.ru/?page_id=1699#<?= $anchor; ?>" title="Перейти к справке"><img src='<?php echo CHERRYLINK_DIR_URL; ?>img/question-mark.png'></a>
    </div>
<?php
}
