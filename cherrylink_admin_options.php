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
    echo (new \CherryLink\Support\View())->render('admin/settings-page');
}

function linkate_posts_vector_api_field()
{
    $options = get_option('linkate-posts', []);

    if (isset($_POST['update_vector_api'])) {
        check_admin_referer('linkate-posts-update-options');
        $options['vector_api_url'] = esc_url_raw(trim($_POST['vector_api_url'] ?? ''));
        $options['vector_api_key'] = sanitize_text_field(trim($_POST['vector_api_key'] ?? ''));
        update_option('linkate-posts', $options);
        echo '<div class="notice-success notice"><p><b>Настройки Vector API сохранены</b></p></div>';
    }

    // Note: CHERRY_VECTOR_API_URL and CHERRY_VECTOR_API_KEY can be defined in wp-config.php
    // to override the admin settings. If defined, the form below is not shown.
    if (defined('CHERRY_VECTOR_API_URL')) return;

    echo (new \CherryLink\Support\View())->render('admin/partials/vector-api-card', [
        'current_url' => $options['vector_api_url'] ?? '',
        'current_key' => $options['vector_api_key'] ?? '',
    ]);
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

    echo (new \CherryLink\Support\View())->render('admin/partials/license-card', [
        'info'           => $info,
        'license_class'  => $license_class,
        'license_header' => $license_header,
        'options'        => $options,
        'key_error'      => $key_error,
    ]);
}
function linkate_posts_index_status_display($page = 'main')
{
    echo (new \CherryLink\Support\View())->render('admin/partials/readiness-card', [
        'data'                 => cl_index_status_data(),
        'index_process_status' => get_option('linkate_posts_meta', [])['indexing_process'] ?? 'VALUE_NOT_EXIST',
        'page'                 => $page,
    ]);
}

function linkate_posts_index_progress()
{
    echo (new \CherryLink\Support\View())->render('admin/partials/index-progress');
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
    echo (new \CherryLink\Support\View())->render('admin/main-subpage', [
        'export_url' => CHERRYLINK_DIR_URL . '/export_options.txt',
    ]);

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
            'excluded_authors',
            'excluded_cats',
            'limit_ajax',
            // 'show_private',
            'show_pages',
            'status',
            'match_cat',
            'consider_max_incoming_links',
            'max_incoming_links',
            'relative_links',
            'show_cat_filter',
            'use_alt_server'
        ));
        update_option('linkate-posts', $options);
        // Show a message to say we've done something
        echo '<div class="notice-success notice"><p>' . __('<b>Настройки обновлены.</b>', CHERRYLINK_TEXT_DOMAIN) . '</p></div>';
    }

    if (isset($_POST['recreate_db'])) {
        check_admin_referer('linkate-posts-update-options');
        delete_option('linkate_posts_meta');
        $result = linkate_posts_install();
        if ($result) {
            echo '<div class="notice-success notice"><p>' . __('<b>Настройки плагина сброшены.</b>', CHERRYLINK_TEXT_DOMAIN) . '</p></div>';
        } else {
            echo '<div class="notice-error notice"><p>' . __('<b>Операция завершилась с ошибкой.</b>', CHERRYLINK_TEXT_DOMAIN) . '</p></div>';
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
        check_admin_referer('linkate-posts-update-options');
        $api = new CherryVectorAPI();
        $ok_docs  = $api->delete_all_documents();
        $ok_links = $api->delete_all_links();
        // Clear local index table and indexing state
        $wpdb->query("TRUNCATE `{$table_prefix}linkate_posts`");
        $options_meta = get_option('linkate_posts_meta', []);
        unset($options_meta['indexing_process']);
        update_option('linkate_posts_meta', $options_meta);
        if ($ok_docs && $ok_links) {
            echo '<div class="notice-success notice"><p><b>Данные сайта удалены из API. Выполните полную переиндексацию.</b></p></div>';
        } else {
            echo '<div class="notice-warning notice"><p><b>Локальный индекс очищен, но API вернул ошибку. Проверьте подключение к Cherry Vector API.</b></p></div>';
        }
    }

    echo (new \CherryLink\Support\View())->render('admin/expert-subpage', [
        'options' => $options,
    ]);
}

function linkate_posts_index_options_subpage()
{
    $options = get_option('linkate-posts', []);

    if (isset($_POST['update_custom_fields'])) {
        check_admin_referer('linkate-posts-update-options');
        $options['vector_custom_fields'] = sanitize_textarea_field($_POST['vector_custom_fields'] ?? '');
        update_option('linkate-posts', $options);
        echo '<div class="notice-success notice"><p><b>Дополнительные поля сохранены.</b></p></div>';
    }

    //php moved below for ajax
    echo (new \CherryLink\Support\View())->render('admin/scan-subpage', [
        'options' => $options,
    ]);
}

function linkate_posts_statistics_options_subpage()
{
    $options      = get_option('linkate-posts', []);
    $options_meta = get_option('linkate_posts_meta', []);

    // Use the cached readiness snapshot — no blocking API call on page render.
    $scheme_rows = (int) (cl_index_status_data()['scheme_rows'] ?? 0);

    //php moved below for ajax
    echo (new \CherryLink\Support\View())->render('admin/statistics-subpage', [
        'scheme_rows'  => $scheme_rows,
        'show_options' => (isset($options['linkate_scheme_exists']) && $options['linkate_scheme_exists']) ? 'block' : 'none',
    ]);
}
