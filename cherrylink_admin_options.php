<?php
/*
 * CherryLink Plugin
 */

// Disable direct access
defined('ABSPATH') || exit;

// ========================================================================================= //
// ============================== CherryLink Setup Settings Pages  ============================== //
// ========================================================================================= //

function linkate_posts_option_menu()
{
    add_options_page(__('CherryLink Options', CHERRYLINK_TEXT_DOMAIN), __('CherryLink Pro', CHERRYLINK_TEXT_DOMAIN), 'cherrylink_settings', 'cherrylink-pro', 'cherrylink_pro_options_page');
}

add_action('admin_menu', 'linkate_posts_option_menu', 1);

function cherrylink_pro_options_page()
{
?>

    <div class="wrap">
        <div class="cherry-admin-logo">
            <h1></h1>
            <div class="<?= CL_TWC::$H1 ?>">CherryLink Pro 🍒</div>
        </div>

        <?php
        $m = new lp_admin_subpages();
        $m->add_subpage('Основные', 'main', 'linkate_posts_main_options_subpage');
        $m->add_subpage('Сканирование сайта', 'scan', 'linkate_posts_index_options_subpage');
        $m->add_subpage('Для экспертов', 'pro', 'linkate_posts_expert_options_subpage');
        $m->add_subpage('Статистика', 'statistics', 'linkate_posts_statistics_options_subpage');
        $m->display();
        // add_action('in_admin_footer', 'linkate_posts_admin_footer');
        ?>
    </div>
<?php
}

function linkate_posts_license_field()
{
    $options = get_option('linkate-posts', []);
    $options_meta = get_option('linkate_posts_meta', []);
    if (isset($_POST['update_license'])) {
        check_admin_referer('linkate-posts-update-options');
        // Fill up the options with the values chosen...
        $options = link_cf_options_from_post($options, array('hash_field'));
        update_option('linkate-posts', $options);
        linkate_handle_license_response();
        // Show a message to say we've done something
        echo '<div class=" notice-success notice"><p>' . __('<b>Обновление ключа</b>', CHERRYLINK_TEXT_DOMAIN) . '</p></div>';
    }
    if (isset($_POST['remove_license'])) {
        check_admin_referer('linkate-posts-update-options');
        // Fill up the options with the values chosen...
        $options_meta['key_valid'] = false;
        $options_meta['expires_at'] = '';
        $options['hash_field'] = '';
        unset($options['activations_left']);

        update_option('linkate-posts', $options);
        update_option('linkate_posts_meta', $options_meta);
        // Show a message to say we've done something
        echo '<div class="notice-error notice"><p>' . __('<b>Ключ сброшен</b>', CHERRYLINK_TEXT_DOMAIN) . '</p></div>';
    }
    // get updated meta
    $options_meta = get_option('linkate_posts_meta', []);
    $info = linkate_checkNeededOption();
    $key_error = '';
    if (isset($options_meta['key_error_reason'])) {

        switch ($options_meta['key_error_reason']) {
            case 'no_connection':
                $key_error = 'Нет связи с сервером. Попробуйте позже или обратитесь в тех. поддержку.';
                break;
            case 'bad_domain':
                $key_error = 'Ключ привязан к другому домену, активация невозможна.';
                break;
            case 'key_expired':
                $key_error = 'Срок действия ключа истек.';
                break;
            case 'wrong_key':
                $key_error = 'Неверно указан ключ.';
                break;
            default:
                $key_error = '';
                break;
        }
    }
    if ($info) {
        $license_class = "border-4 border-lime-300 bg-white text-black";
        $license_header = "<h2 class='" . CL_TWC::$H2 . "'>Лицензия активирована</h2>";
    } else {
        $license_class = "border-4 border-amber-300 bg-white text-black";
        $license_header = "<h2 class='" . CL_TWC::$H2 . "'>Введите ключ лицензии</h2><p>Получите ключ у нас на сайте: [<strong><a href=\"https://seocherry.ru/\">SeoCherry.ru</a></strong>].</p>";
    }

?>
    <div class="p-6 rounded-lg shadow-xl <?php echo $license_class; ?>">
        <?php echo $license_header; ?>
        <?php if ($info) : ?>
            <p>Действует лицензия на текущий домен, ключ скрыт в целях безопасности.</p>
            <form method="post" action="">
                <input type="submit" class="<?= CL_TWC::$BTN_NORMAL ?> " name="remove_license" value="<?php _e('Сбросить лицензию', CHERRYLINK_TEXT_DOMAIN) ?>" />
                <?php if (function_exists('wp_nonce_field')) wp_nonce_field('linkate-posts-update-options'); ?>
            </form>
        <?php else : ?>
            <form method="post" action="">
                <label class="font-bold" for="hash_field"><?php _e('Ваш ключ:', CHERRYLINK_TEXT_DOMAIN) ?></label>
                <br>
                <input type="text" name="hash_field" id="hash_field" required class=" mt-2 <?= CL_TWC::$INP_BASE ?>" value="<?php echo htmlspecialchars(stripslashes($options['hash_field'])); ?>">
                <?php
                if ($key_error) {
                ?>
                    <p style="font-weight:bold; color:red"><?= $key_error ?></p>
                <?php
                }
                ?>
                <br>
                <input type="submit" class="<?= CL_TWC::$BTN_SAVE ?> " name="update_license" value="<?php _e('Сохранить', CHERRYLINK_TEXT_DOMAIN) ?>" />
                <?php if (function_exists('wp_nonce_field')) wp_nonce_field('linkate-posts-update-options'); ?>
            </form>
        <?php endif; ?>
    </div>
<?php

}
function linkate_posts_index_status_display($page = 'main')
{
    global $wpdb, $table_prefix;
    $options_meta = get_option('linkate_posts_meta', []);
    $table_index = $table_prefix . "linkate_posts";
    $table_scheme = $table_prefix . "linkate_scheme";


    $index_rows = $wpdb->get_var("SELECT COUNT(*) FROM $table_index");
    if ($index_rows) {
        $index_status_text = " найдено $index_rows записей и страниц.";
        $index_status_class = "";
    } else {
        $index_status_text = " записи не найдены, просканируйте сайт.";
        $index_status_class = "";
    }

    $scheme_rows = $wpdb->get_var("SELECT COUNT(*) FROM $table_scheme");
    if ($scheme_rows) {
        $scheme_status_text = " найдено $scheme_rows ссылок в записях и страницах.";
        $scheme_status_class = "";
    } else {
        $scheme_status_text = " ссылки не найдены.";
        $scheme_status_class = "";
    }

    // Is there index, was it successful, is it in progress or crushed?
    $index_process_status = isset($options_meta['indexing_process']) ? $options_meta['indexing_process'] : 'VALUE_NOT_EXIST';
    $index_process_status_text = '';
    switch ($index_process_status) {
        case 'VALUE_NOT_EXIST':
            $index_process_status_text = '<code class="bad-index">[Нужно просканировать сайт]</code>';
            break;
        case 'IN_PROGRESS':
            $index_process_status_text = '<code class="bad-index">[Сканирование не завершено]</code>';
            break;
        case 'DONE':
            $index_process_status_text = '<code class="good-index">[Все хорошо]</code>';
            break;
        default:
            $index_process_status_text = '';
            break;
    }

?>
    <div class="p-6 rounded-lg shadow-xl bg-white">
        <h2 class="<?= CL_TWC::$H2 ?>">Готовность к работе <?php echo $index_process_status_text; ?></h2>
        <ul class="list-disc list-inside">
            <li><span id="cherry_index_status" class="<?php echo $index_status_class; ?>"><?php echo $index_status_text; ?></span></li>
            <li><span id="cherry_scheme_status" class="<?php echo $scheme_status_class; ?>"><?php echo $scheme_status_text; ?></span></li>
        </ul>
        <?php
        if ($page === 'main') {
        ?>
            <a href="/wp-admin/options-general.php?page=cherrylink-pro&subpage=statistics"><button class="<?= CL_TWC::$BTN_ACTION ?> mt-2">Проверьте сайт</button></a>
            <a href="/wp-admin/options-general.php?page=cherrylink-pro&subpage=scan"><button class="<?= CL_TWC::$BTN_ACTION ?> mt-2"><?= $index_process_status === 'DONE' ? "Сканировать сайт" :  "Пересканировать" ?></button></a>
        <?php
        }

        //link_cf_prepare_tooltip('                <p>Справа от заголовка "Статус индексирования" есть шильдик с одним из вариантов:</p><ul><li>[Индекс не создан]</li>                <li>[Создание индекса не закончено]</li><li>[Индекс создан]</li></ul>                <p>Текст "Создание индекса не закончено" обычно означает, что индексация не завершилась корректно.                 Рекомендуется пересоздать индекс. Эта же надпись появится, если вы создаете индекс прямо сейчас, например, в другой вкладке браузера.</p>                <p>Текст "Индекс не создан" говорит сам за себя. Необходимо его создать кнопкой "Пересоздать индекс".</p>                <p>Если [Индекс создан], или шильдика с надписью нет вообще, то никаких действий не требуется.</p>'); 
        ?>
    </div>
<?php
}

function linkate_posts_index_progress()
{
?>
    <div class="text-center">
        <progress id="reindex_progress" class="rounded-lg"></progress>
        <div id="reindex_progress_text" class="text-lg pb-6"></div>

        <input type="submit" class="<?= CL_TWC::$BTN_SCAN ?> button-reindex" name="reindex_all" value="Начать сканирование" />
        <?php if (function_exists('wp_nonce_field')) ?>
    </div>

<?php
}

// ========================================================================================= //
// ============================== CherryLink Settings Pages Callbacks  ============================== //
// ========================================================================================= //


function linkate_posts_main_options_subpage()
{
    $options = get_option('linkate-posts', []);

    // Create options file to export
    if (!isset($_POST['import_settings'])) {
        $str = http_build_query($options);
        $res = file_put_contents(CHERRYLINK_DIR . '/export_options.txt', $str);
    }

    if (isset($_POST['import_settings']) && isset($_FILES['upload_options'])) {
        check_admin_referer('linkate-posts-update-options');
        // Fill up the options with the values chosen...
        $name    = basename($_FILES['upload_options']['name']);
        $ext     = end(explode('.', $name));
        if ($ext === 'txt') {
            // get text from file
            $str = file_get_contents($_FILES['upload_options']['tmp_name']);
            // convert to array
            parse_str($str, $arr);
            // get args
            $keys = array_keys($arr);
            // they say - it's a bad practice
            // I say - it's okay
            $_POST = array_merge($arr, $_POST);
            // rewrite options
            $options = link_cf_options_from_post($options, $keys);
            update_option('linkate-posts', $options);
            echo '<div class="notice-success notice"><p>' . __('<b>Настройки импортированы.</b>', CHERRYLINK_TEXT_DOMAIN) . '</p></div>';
        } else {
            echo '<div class="notice-error notice"><p>' . __('<b>Не удалось импортировать...</b>', CHERRYLINK_TEXT_DOMAIN) . '</p></div>';
        }
    }
    //now we drop into html to display the option page form
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

                <div class="<?= CL_TWC::$CARD ?> cherry-settings-export-container">

                    <div>
                        <h2 class="<?= CL_TWC::$H2 ?>">Экспорт и импорт настроек</h2>
                        <p>Для переноса настроек между сайтами, скачайте файл настроек <strong>export_options.txt</strong> и импортируйте его на другом сайте. </p>
                        <a class="inline-block <?= CL_TWC::$BTN_NORMAL ?> " href="<?php echo CHERRYLINK_DIR_URL . '/export_options.txt'; ?>" download>Скачать файл настроек</a>
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
    if (isset($_POST['export_settings'])) {
        check_admin_referer('linkate-posts-update-options');

        $str = http_build_query($options);
        header("Content-Disposition: attachment; filename=\"cherrylink_options.txt\"");
        header("Content-Type: application/force-download");
        header("Content-Length: " . mb_strlen($str));
        header("Connection: close");

        echo $str;
        exit();
    }
}


function linkate_posts_expert_options_subpage()
{
    global $wpdb, $table_prefix;
    $options = get_option('linkate-posts', []);
    if (isset($_POST['update_options_filter']) || isset($_POST['update_options_output']) || isset($_POST['update_options_relevance'])) {
        check_admin_referer('linkate-posts-update-options');
        // Fill up the options with the values chosen...
        $options = link_cf_options_from_post($options, array(
            'show_customs',
            'excluded_posts',
            // 'included_posts',
            'excluded_authors',
            // 'included_authors',
            'excluded_cats',
            // 'included_cats',
            // 'tag_str', 
            // 'custom', 
            'limit_ajax',
            // 'show_private', 
            'show_pages',
            'status',
            // 'age', 
            'match_cat',
            // 'match_tags',
            // 'sort', 
            // 'quickfilter_dblclick', 
            'output_template',
            'consider_max_incoming_links',
            'max_incoming_links',
            'link_before',
            'link_after',
            // 'link_temp_alt',
            'template_image_size',
            'no_selection_action',
            'term_before',
            'term_after',
            // 'term_temp_alt',
            'anons_len',
            'relative_links',
            'suggestions_switch_action',
            'multilink',
            'show_cat_filter'
        ));
        update_option('linkate-posts', $options);
        // Show a message to say we've done something
        echo '<div class="notice-success notice"><p>' . __('<b>Настройки обновлены.</b>', CHERRYLINK_TEXT_DOMAIN) . '</p></div>';
    }

    if (isset($_POST['recreate_db'])) {
        check_admin_referer('linkate-posts-update-options');
        delete_option('linkate_posts_meta');

        $table_name = $table_prefix . 'linkate_posts';
        $wpdb->query("DROP TABLE `$table_name`");

        $table_name = $table_prefix . 'linkate_scheme';
        $wpdb->query("DROP TABLE `$table_name`");

        $table_name = $table_prefix . 'linkate_stopwords';
        $wpdb->query("DROP TABLE `$table_name`");

        $result = linkate_posts_install();
        if ($result) {
            echo '<div class="notice-success notice"><p>' . __('<b>Таблицы в БД были успешно пересозданы.</b>', CHERRYLINK_TEXT_DOMAIN) . '</p></div>';
        } else {
            echo '<div class="notice-error notice"><p>' . __('<b>Операция пересоздания таблиц завершилась с ошибкой.</b>', CHERRYLINK_TEXT_DOMAIN) . '</p></div>';
        }
    }

    if (isset($_POST['reset_options'])) {
        check_admin_referer('linkate-posts-update-options');
        // Fill up the options with the values chosen...
        fill_options(NULL);
        // Show a message to say we've done something
        echo '<div class="notice-success notice"><p>' . __('<b>Настройки сброшены.</b>', CHERRYLINK_TEXT_DOMAIN) . '</p></div>';
    }


    if (isset($_POST['truncate_all'])) {
        $options_meta = get_option('linkate_posts_meta', []);
        $table_index = $table_prefix . "linkate_posts";
        $table_scheme = $table_prefix . "linkate_scheme";
        check_admin_referer('linkate-posts-update-options');
        // Remove scheme
        unset($options['linkate_scheme_exists']);
        unset($options['linkate_scheme_time']);
        update_option('linkate-posts', $options);

        unset($options_meta['indexing_process']);
        update_option('linkate_posts_meta', $options_meta);
        $wpdb->query("TRUNCATE `$table_scheme`");
        // Remove index
        $wpdb->query("TRUNCATE `$table_index`");

        // Show a message to say we've done something
        echo '<div class="notice-success notice"><p>' . __('<b>Базы данных плагина очищены.</b>', CHERRYLINK_TEXT_DOMAIN) . '</p></div>';
    }

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
                        link_cf_display_output_template($options['output_template']);
                        link_cf_display_limit_ajax($options['limit_ajax']);
                        link_cf_display_show_pages($options['show_pages']);
                        link_cf_display_match_cat($options['match_cat']);
                        link_cf_display_status($options['status']);
                        // link_cf_display_show_private($options['show_private']);
                        // link_cf_display_age($options['age']);

                        // link_cf_display_quickfilter_dblclick($options['quickfilter_dblclick']);


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
                            // link_cf_display_sort($options['sort']);
                            // link_cf_display_match_tags($options['match_tags']);
                            // link_cf_display_match_author($options['match_author']);
                            // link_cf_display_tag_str($options['tag_str']);
                            link_cf_display_excluded_posts($options['excluded_posts']);
                            // link_cf_display_included_posts($options['included_posts']);
                            link_cf_display_authors($options['excluded_authors'], $options['included_authors']);
                            link_cf_display_cats($options['excluded_cats'], $options['included_cats']);
                            // link_cf_display_custom($options['custom']);
                            ?>
                        </table>
                    </div>
                    <input type="submit" class="<?= CL_TWC::$BTN_SAVE ?> mt-6" name="update_options_filter" value="<?php _e('Сохранить настройки', CHERRYLINK_TEXT_DOMAIN) ?>" />
                    <?php if (function_exists('wp_nonce_field')) wp_nonce_field('linkate-posts-update-options'); ?>
                </div>
                <div class="<?= CL_TWC::$CARD ?>">


                    <h2 id="anchor-template" class="<?= CL_TWC::$H2 ?>">Разметка ссылки для записи/страницы</h2>
                    <p>Шаблон обрамления выделенного текста ссылкой на <i>запись, страницу</i> и пр.</p>

                    <table class="optiontable form-table">

                        <?php
                        link_cf_display_relative_links($options['relative_links']);
                        link_cf_display_replace_template($options['link_before'], $options['link_after'], $options['link_temp_alt']);
                        ?>
                    </table>


                    <input type="checkbox" id="spoiler_output" />
                    <label for="spoiler_output" class="">Показать еще...</label>

                    <div class="spoiler_output">
                        <h2 class="<?= CL_TWC::$H2 ?>">Разметка ссылки для рубрики/таксономии</h2>
                        <p>Шаблон обрамления выделенного текста ссылкой на <i>рубрику, метку</i> и пр.</p>

                        <table class="optiontable form-table">
                            <?php link_cf_display_replace_term_template($options['term_before'], $options['term_after'], $options['term_temp_alt']); ?>
                        </table>
                        <h2 class="<?= CL_TWC::$H2 ?>">Общие настройки шаблонов и вставки</h2>
                        <table class="optiontable form-table">

                            <?php
                            link_cf_display_anons_len($options['anons_len']);
                            link_cf_display_multilink($options['multilink']);
                            link_cf_display_no_selection_action($options['no_selection_action']);

                            link_cf_display_suggestions_switch_action($options['suggestions_switch_action']);
                            link_cf_template_image_size($options['template_image_size'])
                            ?>
                        </table>
                        <p style="color:red"><strong>Изменения шаблона не повлияют на уже вставленные ссылки в статьях!</strong></p>
                    </div>

                    <input type="submit" class="<?= CL_TWC::$BTN_SAVE ?> mt-6" name="update_options_output" value="<?php _e('Сохранить настройки', CHERRYLINK_TEXT_DOMAIN) ?>" />
                    <!-- <input type="submit" id="restore_templates" class="button button-download" style="float: right;" value="<?php _e('Восстановить шаблоны по умолчанию', CHERRYLINK_TEXT_DOMAIN) ?>" /> -->
                    <?php if (function_exists('wp_nonce_field')) wp_nonce_field('linkate-posts-update-options'); ?>
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
                <input class="<?= CL_TWC::$BTN_DANGER ?> mt-2" name="truncate_all" type="submit" value="Очистить таблицы ссылок в БД" />
                <input type="submit" class="<?= CL_TWC::$BTN_DANGER ?> mt-2" name="recreate_db" value="<?php _e('Пересоздать таблицы БД', CHERRYLINK_TEXT_DOMAIN) ?>" />

                <input type="submit" class="<?= CL_TWC::$BTN_DANGER ?> mt-2" name="reset_options" value="<?php _e('Вернуть настройки по умолчанию', CHERRYLINK_TEXT_DOMAIN) ?>" />
                <?php if (function_exists('wp_nonce_field')) wp_nonce_field('linkate-posts-update-options'); ?>
            </form>
        </div>
    </div>
<?php
}

function linkate_posts_index_options_subpage()
{
    $options = get_option('linkate-posts', []);

    //php moved below for ajax
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
            <label for="spoiler_scan" id="label_spoiler_scan" class="hover:bg-gray-100 hover:text-blue-700 focus:z-10 focus:ring-4 focus:ring-gray-100 hover:cursor-pointer">Настройка сканирования</label>

            <div class="spoiler_scan">

                <form id="options_form" method="post" action="">
                    <p>При редактировании этих настроек обязательно нажмите кнопку "Начать сканирование", чтобы изменения вступили в силу.</p>
                    <hr>
                    <table class="optiontable form-table">
                        <?php
                        link_cf_display_seo_meta_source($options['seo_meta_source']);
                        ?>
                    </table>

                </form>
            </div>

        </div>

        <!--  We save and update index using ajax call, see function linkate_ajax_call_reindex below -->
    </div>

<?php
}

function linkate_posts_statistics_options_subpage()
{
    global $wpdb, $table_prefix;
    $options = get_option('linkate-posts', []);
    $options_meta = get_option('linkate_posts_meta', []);
    $table_index = $table_prefix . "linkate_posts";
    $table_scheme = $table_prefix . "linkate_scheme";

    $scheme_rows = $wpdb->get_var("SELECT COUNT(*) FROM $table_scheme");

    //php moved below for ajax
?>
    <div class=" linkateposts-tab-content pt-3">
        <div class="<?= CL_TWC::$CARD ?>">
            <h2 class="<?= CL_TWC::$H2 ?>">Поиск проблем с перелинковкой</h2>
            <p>Нажмите на кнопку "Проверить перелинковку", чтобы найти записи, в которых:</p>
            <ol class="list-inside list-decimal">
                <li>Есть повторяющиеся ссылки;</li>
                <li>Нет входящих ссылок;</li>
                <li>Нет исходящих ссылок.</li>
            </ol>
            <!-- <p>Подробную статистику по перелинковке вы можете скачать в формате CSV с помощью инструмента Экспорт перелинковки на вкладке "Индекс ссылок".</p> -->
            <?php //link_cf_prepare_tooltip(''); 
            ?>
            <form id="form_generate_stats" method="post" action="">
                <?php link_cf_display_scheme_statistics_options(); ?>
                <progress id="link_check_progress"></progress>
                <input id="generate_preview" type="submit" class="<?= CL_TWC::$BTN_NORMAL ?>" name="generate_preview" value="<?php _e('Проверить перелинковку', CHERRYLINK_TEXT_DOMAIN) ?>" />
            </form>
            <br>
        </div>

        <div id="cherry_preview_stats_container" style="display:none">
            <div class="<?= CL_TWC::$CARD ?> mt-3">
                <h2 class="<?= CL_TWC::$H2 ?>">
                    Найдены проблемы с перелинковкой
                </h2>
                <div class="mb-3" id="cherry_preview_stats_summary" data-linkscount="<?= $scheme_rows ?>"></div>
                <input type="checkbox" id="spoiler_has_repeats" />
                <label for="spoiler_has_repeats" id="label_spoiler_has_repeats"></label>
                <div class="spoiler_has_repeats">
                </div>
                <br>
                <input type="checkbox" id="spoiler_no_incoming" />
                <label for="spoiler_no_incoming" id="label_spoiler_no_incoming"></label>
                <div class="spoiler_no_incoming">
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
        <?php $show_options = (isset($options['linkate_scheme_exists']) &&  $options['linkate_scheme_exists']) ? 'block' : 'none'; ?>
        <div class="<?= CL_TWC::$CARD ?> mt-6" style="display: <?php echo $show_options; ?>">

            <input type="checkbox" id="spoiler_scheme" />
            <label for="spoiler_scheme" class="hover:bg-gray-100 hover:text-blue-700 focus:z-10 focus:ring-4 focus:ring-gray-100 hover:cursor-pointer">Экспорт перелинковки в .CSV</label>

            <div class="spoiler_scheme">

                <form id="form_generate_csv" method="post" action="">
                    <?php link_cf_display_scheme_export_options(); ?>
                    <progress id="csv_progress"></progress>
                    <input id="generate_csv" type="submit" class="<?= CL_TWC::$BTN_NORMAL ?>" name="generate_csv" value="<?php _e('Скачать схему в .CSV', CHERRYLINK_TEXT_DOMAIN) ?>" />

                </form>
            </div>
        </div>
        <!--  We save and update index using ajax call, see function linkate_ajax_call_reindex below -->
    </div>

<?php
}
