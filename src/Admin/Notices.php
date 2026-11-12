<?php

declare(strict_types=1);

namespace CherryLink\Admin;

use CherryLink\Contracts\Registerable;
use CherryLink\Support\Connect;
use CherryLink\Support\PluginMeta;
use CherryLink\Support\View;

/**
 * Admin notices prompting the initial site scan: one right after activation (while the
 * manual-indexation transient is set) and a standing reminder until the first scan
 * completes. Ported from cherrylink_activation_notice() in the legacy cherrylink_main.php.
 */
final class Notices implements Registerable
{
    /** User meta: the "Connect CherryLink" plugins-screen notice was dismissed. */
    public const DISMISS_META = 'cherrylink_connect_notice_dismissed';
    public const DISMISS_ACTION = 'cherrylink_dismiss_connect';

    public function register(): void
    {
        \add_action('admin_notices', [$this, 'scanPrompt']);
        \add_action('admin_notices', [$this, 'connectPrompt']);
        \add_action('admin_init', [$this, 'maybeDismiss']);
    }

    /** Plugins screen only: a dismissible "Connect CherryLink to start" notice until connected. */
    public function connectPrompt(): void
    {
        global $pagenow;
        if ($pagenow !== 'plugins.php' || ! \current_user_can('cherrylink_settings')) {
            return;
        }
        $meta = \get_option('linkate_posts_meta', []);
        if (! empty($meta['key_valid']) || \get_user_meta(\get_current_user_id(), self::DISMISS_META, true)) {
            return;
        }
        $dismiss = \wp_nonce_url(\add_query_arg(self::DISMISS_ACTION, '1', \admin_url('plugins.php')), self::DISMISS_ACTION);
        $html = (new View())->render('notices/connect-prompt', [
            'connect_url' => Connect::settingsUrl(),
            'dismiss_url' => $dismiss,
        ]);
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- View renders an escaped template
    }

    public function maybeDismiss(): void
    {
        if (! isset($_GET[self::DISMISS_ACTION]) || ! \current_user_can('cherrylink_settings')) {
            return;
        }
        \check_admin_referer(self::DISMISS_ACTION);
        \update_user_meta(\get_current_user_id(), self::DISMISS_META, 1);
        \wp_safe_redirect(\admin_url('plugins.php'));
        exit;
    }

    public function scanPrompt(): void
    {
        // Guideline 11: only on CherryLink's own screen, and never before connecting
        // (the setup wizard covers that case).
        $screen = \function_exists('get_current_screen') ? \get_current_screen() : null;
        if ($screen === null || ! PluginMeta::isSettingsPage()) {
            return;
        }
        $meta   = \get_option('linkate_posts_meta', []);
        if (empty($meta['key_valid'])) {
            return;
        }
        $status = ! empty($meta['indexing_process']) ? $meta['indexing_process'] : 'VALUE_NOT_EXIST';

        $scan_url = \admin_url('options-general.php?page=cherrylink-pro&subpage=scan');
        $view     = new View();

        // Fresh activation — show once, then clear the transient.
        if (\get_transient('cherry-manual-indexation-needed')) {
            $html = $view->render('notices/scan-prompt', [
                'line1'    => '<strong>CherryLink Pro</strong> ' . \esc_html__('установлен!', 'cherrylink'),
                /* translators: %s: bold "you need to scan your site" phrase */
                'line2'    => \sprintf(
                    \esc_html__('Для начала работы с ним %s.', 'cherrylink'),
                    '<strong>' . \esc_html__('необходимо просканировать ваш сайт', 'cherrylink') . '</strong>'
                ),
                'scan_url' => $scan_url,
            ]);
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- View renders an escaped template
            \delete_transient('cherry-manual-indexation-needed');
        } elseif ($status === 'VALUE_NOT_EXIST' || $status === 'IN_PROGRESS') {
            $html = $view->render('notices/scan-prompt', [
                'line1'    => '<strong>CherryLink Pro</strong>: ' . \esc_html__('сканирование не завершено!', 'cherrylink'),
                /* translators: %s: bold "you need to finish scanning" phrase */
                'line2'    => \sprintf(
                    \esc_html__('Плагин может работать некорректно или не видеть все записи, %s.', 'cherrylink'),
                    '<strong>' . \esc_html__('необходимо завершить сканирование', 'cherrylink') . '</strong>'
                ),
                'scan_url' => $scan_url,
            ]);
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- View renders an escaped template
        }
    }
}
