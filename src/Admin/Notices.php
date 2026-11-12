<?php

declare(strict_types=1);

namespace CherryLink\Admin;

use CherryLink\Contracts\Registerable;
use CherryLink\Support\Connect;
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
}
