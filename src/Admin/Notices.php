<?php

declare(strict_types=1);

namespace CherryLink\Admin;

use CherryLink\Contracts\Registerable;
use CherryLink\Support\View;

/**
 * Admin notices prompting the initial site scan: one right after activation (while the
 * manual-indexation transient is set) and a standing reminder until the first scan
 * completes. Ported from cherrylink_activation_notice() in the legacy cherrylink_main.php.
 */
final class Notices implements Registerable
{
    public function register(): void
    {
        \add_action('admin_notices', [$this, 'scanPrompt']);
    }

    public function scanPrompt(): void
    {
        $meta   = \get_option('linkate_posts_meta', []);
        $status = ! empty($meta['indexing_process']) ? $meta['indexing_process'] : 'VALUE_NOT_EXIST';

        $scan_url = \site_url() . '/wp-admin/options-general.php?page=cherrylink-pro&subpage=scan';
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
