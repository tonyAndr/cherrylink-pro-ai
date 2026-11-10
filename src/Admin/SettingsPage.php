<?php

declare(strict_types=1);

namespace CherryLink\Admin;

use CherryLink\Api\Client;
use CherryLink\Contracts\Registerable;
use CherryLink\CustomFields\Acf;
use CherryLink\Indexing\IndexHooks;
use CherryLink\Plugin;
use CherryLink\Support\License;
use CherryLink\Support\Options;
use CherryLink\Support\Tailwind;
use CherryLink\Support\View;

/**
 * The CherryLink settings screen: menu registration, the tabbed page shell, and the
 * four subpage callbacks (main / scan / expert / statistics) plus the license, Vector
 * API, readiness and progress cards.
 *
 * Ported from the legacy cherrylink_admin_options.php. All HTML lives in templates/
 * (rendered via View); this class owns the POST-handling (save/import/export/reset/
 * truncate, each nonce-checked) and composition. Subpage/field methods are static so
 * the subpage templates can call them without threading an instance through View data.
 */
final class SettingsPage implements Registerable
{
    public function register(): void
    {
        \add_action('admin_menu', [$this, 'menu'], 1);
        // Settings export is served through a nonce-checked admin handler, never as a
        // static file inside the plugin directory.
        \add_action('admin_post_cherrylink_export_options', [$this, 'exportOptions']);
        \add_filter(
            'plugin_action_links_' . \plugin_basename(Plugin::instance()->file()),
            [$this, 'actionLinks']
        );
    }

    /**
     * Prepend a "Settings" link to the plugin's row on the Plugins screen.
     *
     * @param array<int,string> $links
     * @return array<int,string>
     */
    public function actionLinks($links): array
    {
        $settings = '<a href="' . \admin_url('options-general.php?page=cherrylink-pro') . '" title="'
            . \esc_attr__('Настройки CherryLink', 'cherrylink') . '">'
            . \esc_html__('Настройки', 'cherrylink') . '</a>';
        \array_unshift($links, $settings);

        return $links;
    }

    public function menu(): void
    {
        \add_options_page(
            \__('CherryLink Options', 'cherrylink'),
            \__('CherryLink Pro', 'cherrylink'),
            'cherrylink_settings',
            'cherrylink-pro',
            [$this, 'renderPage']
        );
    }

    public function renderPage(): void
    {
        $m = new Subpages();
        $m->addSubpage(\__('Основные', 'cherrylink'), 'main', [self::class, 'mainSubpage']);
        $m->addSubpage(\__('Сканирование сайта', 'cherrylink'), 'scan', [self::class, 'scanSubpage']);
        $m->addSubpage(\__('Для экспертов', 'cherrylink'), 'pro', [self::class, 'expertSubpage']);
        $m->addSubpage(\__('Статистика', 'cherrylink'), 'statistics', [self::class, 'statisticsSubpage']);

        self::render('admin/settings-page', ['menu' => $m]);
    }

    // ── output helpers ──────────────────────────────────────────────────────────

    /**
     * Render a template and echo it. View output is pre-escaped in the template layer.
     *
     * @param array<string,mixed> $data
     */
    private static function render(string $template, array $data = []): void
    {
        $html = (new View())->render($template, $data);
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- View renders pre-escaped template output
        echo $html;
    }

    /** Echo an admin-notice shell around an already-escaped message. */
    private static function notice(string $class, string $message): void
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $message is pre-escaped by the caller (esc_html__)
        echo '<div class="' . $class . ' notice"><p><b>' . $message . '</b></p></div>';
    }

    // ── cards / fields ────────────────────────────────────────────────────────

    public static function vectorApiField(): void
    {
        $options = (array) \get_option('linkate-posts', []);

        if (isset($_POST['update_vector_api'])) {
            \check_admin_referer('linkate-posts-update-options');
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw sanitizes; trim only strips whitespace
            $options['vector_api_url'] = \trim(\esc_url_raw(\wp_unslash($_POST['vector_api_url'] ?? '')));
            $options['vector_api_key'] = \sanitize_text_field(\wp_unslash($_POST['vector_api_key'] ?? ''));
            \update_option('linkate-posts', $options);
            self::notice('notice-success', \esc_html__('Настройки Vector API сохранены', 'cherrylink'));
        }

        // Note: CHERRY_VECTOR_API_URL and CHERRY_VECTOR_API_KEY can be defined in wp-config.php
        // to override the admin settings. If defined, the form below is not shown.
        if (\defined('CHERRY_VECTOR_API_URL')) {
            return;
        }

        self::render('admin/partials/vector-api-card', [
            'current_url' => $options['vector_api_url'] ?? '',
            'current_key' => $options['vector_api_key'] ?? '',
        ]);
    }

    public static function licenseField(): void
    {
        $options      = (array) \get_option('linkate-posts', []);
        $options_meta = (array) \get_option('linkate_posts_meta', []);
        $license      = new License(new Options());

        if (isset($_POST['update_license'])) {
            \check_admin_referer('linkate-posts-update-options');
            $previous_key = (string) ($options['hash_field'] ?? '');
            $options = Acf::optionsFromPost($options, ['hash_field']);
            \update_option('linkate-posts', $options);
            $scheduled = $license->scheduleValidation(true, $previous_key !== (string) ($options['hash_field'] ?? ''));
            self::notice(
                $scheduled ? 'notice-success' : 'notice-error',
                $scheduled
                    ? \esc_html__('Проверка ключа запланирована. Статус обновится после фоновой проверки.', 'cherrylink')
                    : \esc_html__('Не удалось запланировать проверку ключа. Повторите попытку вручную.', 'cherrylink')
            );
        }
        if (isset($_POST['retry_license'])) {
            \check_admin_referer('linkate-posts-update-options');
            $scheduled = $license->scheduleValidation();
            self::notice(
                $scheduled ? 'notice-success' : 'notice-error',
                $scheduled
                    ? \esc_html__('Проверка ключа запланирована. Статус обновится после фоновой проверки.', 'cherrylink')
                    : \esc_html__('Не удалось запланировать проверку ключа. Повторите попытку вручную.', 'cherrylink')
            );
        }
        if (isset($_POST['remove_license'])) {
            \check_admin_referer('linkate-posts-update-options');
            $options_meta['key_valid']  = false;
            $options_meta['expires_at'] = '';
            $options['hash_field']      = '';
            unset($options['activations_left']);
            unset(
                $options_meta['validation_key_hash'],
                $options_meta['validation_status'],
                $options_meta['validation_attempt'],
                $options_meta['validation_next_gmt'],
                $options_meta['key_error_reason']
            );

            \update_option('linkate-posts', $options);
            \update_option('linkate_posts_meta', $options_meta);
            if (\function_exists('wp_clear_scheduled_hook')) {
                \wp_clear_scheduled_hook(License::VALIDATION_HOOK);
            }
            self::notice('notice-error', \esc_html__('Ключ сброшен', 'cherrylink'));
        }

        $options_meta = (array) \get_option('linkate_posts_meta', []);
        $info         = $license->isValid();
        $validation_status = (string) ($options_meta['validation_status'] ?? '');
        $key_error    = self::licenseErrorText(
            $validation_status === 'pending' ? 'pending' : (string) ($options_meta['key_error_reason'] ?? '')
        );

        $h2 = Tailwind::$H2;
        if ($info) {
            $license_class  = 'border-4 border-lime-300 bg-white text-black';
            $license_header = "<h2 class='" . $h2 . "'>" . \esc_html__('Лицензия активирована', 'cherrylink') . '</h2>';
        } else {
            $license_class  = 'border-4 border-amber-300 bg-white text-black';
            $license_header = "<h2 class='" . $h2 . "'>" . \esc_html__('Введите ключ лицензии', 'cherrylink') . '</h2><p>'
                . \sprintf(
                    /* translators: %s: link to seocherry.ru */
                    \esc_html__('Получите ключ у нас на сайте: [%s].', 'cherrylink'),
                    '<strong><a href="https://seocherry.ru/">SeoCherry.ru</a></strong>'
                )
                . '</p>';
        }

        self::render('admin/partials/license-card', [
            'info'           => $info,
            'license_class'  => $license_class,
            'license_header' => $license_header,
            'options'        => $options,
            'key_error'      => $key_error,
            'validation_status' => $validation_status,
        ]);

        // The card's active-license branch hides the key field. Keep the manual retry
        // available when cron could not persist a validation event for that active key.
        if ($info && $validation_status === 'error') {
            self::notice('notice-error', $key_error);
            echo '<form method="post" action="">';
            echo '<input type="submit" name="retry_license" value="'
                . \esc_attr__('Проверить ключ повторно', 'cherrylink') . '" />';
            if (\function_exists('wp_nonce_field')) {
                \wp_nonce_field('linkate-posts-update-options');
            }
            echo '</form>';
        }
    }

    /** Map a stored key_error_reason to a translated, escaped message. */
    private static function licenseErrorText(string $reason): string
    {
        switch ($reason) {
            case 'no_connection':
                return \esc_html__('Нет связи с сервером. Попробуйте позже или обратитесь в тех. поддержку.', 'cherrylink');
            case 'pending':
                return \esc_html__('Проверка ключа выполняется в фоне. Обновите страницу через несколько секунд.', 'cherrylink');
            case 'missing_key':
                return \esc_html__('Введите ключ лицензии для проверки.', 'cherrylink');
            case 'validation_schedule_failed':
                return \esc_html__('Не удалось запланировать проверку ключа. Повторите попытку вручную.', 'cherrylink');
            case 'bad_domain':
                return \esc_html__('Ключ привязан к другому домену, активация невозможна.', 'cherrylink');
            case 'key_expired':
                return \esc_html__('Срок действия ключа истек.', 'cherrylink');
            case 'wrong_key':
                return \esc_html__('Неверно указан ключ.', 'cherrylink');
            default:
                return '';
        }
    }

    public static function indexStatusDisplay(string $page = 'main'): void
    {
        self::render('admin/partials/readiness-card', [
            'data'                 => IndexHooks::statusData(),
            'index_process_status' => \get_option('linkate_posts_meta', [])['indexing_process'] ?? 'VALUE_NOT_EXIST',
            'page'                 => $page,
        ]);
    }

    public static function indexProgress(): void
    {
        self::render('admin/partials/index-progress');
    }

    // ── subpages ───────────────────────────────────────────────────────────────

    public static function mainSubpage(): void
    {
        $options = (array) \get_option('linkate-posts', []);

        if (isset($_POST['import_settings']) && isset($_FILES['upload_options'])) {
            \check_admin_referer('linkate-posts-update-options');
            $name = \basename(\sanitize_file_name($_FILES['upload_options']['name'] ?? ''));
            $ext  = \strtolower((string) \pathinfo($name, PATHINFO_EXTENSION));
            if ($ext === 'txt') {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- upload guarded by isset() above; server-side tmp path, parsed then re-sanitized field-by-field
                $str = (string) \file_get_contents($_FILES['upload_options']['tmp_name']);
                \parse_str($str, $arr);
                $keys = \array_keys($arr);
                // merge parsed values into POST so optionsFromPost() picks them up
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified above; $_POST guaranteed set by the import_settings isset guard
                $_POST   = \array_merge($arr, $_POST);
                $options = Acf::optionsFromPost($options, $keys);
                \update_option('linkate-posts', $options);
                self::notice('notice-success', \esc_html__('Настройки импортированы.', 'cherrylink'));
            } else {
                self::notice('notice-error', \esc_html__('Не удалось импортировать...', 'cherrylink'));
            }
        }

        self::render('admin/main-subpage', [
            'export_url' => \wp_nonce_url(
                \admin_url('admin-post.php?action=cherrylink_export_options'),
                'cherrylink-export-options'
            ),
        ]);
    }

    /**
     * Stream the settings file to the current administrator.
     *
     * The previous implementation wrote `export_options.txt` into the plugin
     * directory on every settings-page render and linked to it directly: that fails
     * on read-only installs (visible PHP warning) and, more importantly, exposed the
     * settings — including the licence key — at a public plugin URL.
     */
    public function exportOptions(): void
    {
        if (! \current_user_can('cherrylink_settings')) {
            \wp_die(\esc_html__('Недостаточно прав.', 'cherrylink'), '', ['response' => 403]);
        }
        \check_admin_referer('cherrylink-export-options');

        $payload = \http_build_query((array) \get_option('linkate-posts', []));

        \nocache_headers();
        \header('Content-Type: text/plain; charset=utf-8');
        \header('Content-Disposition: attachment; filename="cherrylink_options.txt"');
        \header('Content-Length: ' . \strlen($payload));
        echo $payload; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw options download payload
        exit;
    }

    public static function expertSubpage(): void
    {
        global $wpdb, $table_prefix;
        $options = (array) \get_option('linkate-posts', []);
        if (isset($_POST['update_options_filter']) || isset($_POST['update_options_output']) || isset($_POST['update_options_relevance'])) {
            \check_admin_referer('linkate-posts-update-options');
            $options = Acf::optionsFromPost($options, [
                'show_customs',
                'excluded_authors',
                'excluded_cats',
                'limit_ajax',
                'show_pages',
                'status',
                'match_cat',
                'consider_max_incoming_links',
                'max_incoming_links',
                'relative_links',
                'show_cat_filter',
                'use_alt_server',
            ]);
            \update_option('linkate-posts', $options);
            self::notice('notice-success', \esc_html__('Настройки обновлены.', 'cherrylink'));
        }

        if (isset($_POST['recreate_db'])) {
            \check_admin_referer('linkate-posts-update-options');
            \delete_option('linkate_posts_meta');
            $result = (new License(new Options()))->install();
            if ($result) {
                self::notice('notice-success', \esc_html__('Настройки плагина сброшены.', 'cherrylink'));
            } else {
                self::notice('notice-error', \esc_html__('Операция завершилась с ошибкой.', 'cherrylink'));
            }
        }

        if (isset($_POST['reset_options'])) {
            \check_admin_referer('linkate-posts-update-options');
            Options::fill(null);
            self::notice('notice-success', \esc_html__('Настройки сброшены.', 'cherrylink'));
        }

        if (isset($_POST['truncate_all'])) {
            \check_admin_referer('linkate-posts-update-options');
            $api      = new Client();
            $ok_docs  = $api->delete_all_documents();
            $ok_links = $api->delete_all_links();
            // Clear local index table and indexing state
            $wpdb->query("TRUNCATE `{$table_prefix}linkate_posts`"); // phpcs:ignore WordPress.DB
            $options_meta = (array) \get_option('linkate_posts_meta', []);
            unset($options_meta['indexing_process']);
            \update_option('linkate_posts_meta', $options_meta);
            if ($ok_docs && $ok_links) {
                self::notice('notice-success', \esc_html__('Данные сайта удалены из API. Выполните полную переиндексацию.', 'cherrylink'));
            } else {
                self::notice('notice-warning', \esc_html__('Локальный индекс очищен, но API вернул ошибку. Проверьте подключение к Cherry Vector API.', 'cherrylink'));
            }
        }

        self::render('admin/expert-subpage', ['options' => $options]);
    }

    public static function scanSubpage(): void
    {
        $options = (array) \get_option('linkate-posts', []);

        if (isset($_POST['update_custom_fields'])) {
            \check_admin_referer('linkate-posts-update-options');
            $options['vector_custom_fields'] = \sanitize_textarea_field(\wp_unslash($_POST['vector_custom_fields'] ?? ''));
            \update_option('linkate-posts', $options);
            self::notice('notice-success', \esc_html__('Дополнительные поля сохранены.', 'cherrylink'));
        }

        self::render('admin/scan-subpage', ['options' => $options]);
    }

    public static function statisticsSubpage(): void
    {
        $options = (array) \get_option('linkate-posts', []);

        // Use the cached readiness snapshot — no blocking API call on page render.
        $scheme_rows = (int) (IndexHooks::statusData()['scheme_rows'] ?? 0);

        self::render('admin/statistics-subpage', [
            'scheme_rows'  => $scheme_rows,
            'show_options' => (isset($options['linkate_scheme_exists']) && $options['linkate_scheme_exists']) ? 'block' : 'none',
        ]);
    }
}
