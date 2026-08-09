<?php

declare(strict_types=1);

namespace CherryLink\Debug;

use CherryLink\Ajax\Security;
use CherryLink\Contracts\Registerable;

/**
 * Developer-facing diagnostics behind the settings-page "Отладка" panel.
 *
 * Four admin-ajax probes (environment, links dump, wpdb snapshot, TCP connectivity)
 * whose output goes into the debug textarea for copy-paste into support. Ported
 * verbatim from the legacy cherrylink_debug.php (the CHERRYLINK_DEBUGGER god-class);
 * register() reproduces the file-scope add_action wiring, and every handler still
 * opens with the shared nonce + manage_options capability gate.
 */
final class Debugger implements Registerable
{
    public function register(): void
    {
        \add_action('wp_ajax_output_admin_debug_env', [$this, 'env']);
        \add_action('wp_ajax_output_admin_debug_links', [$this, 'links']);
        \add_action('wp_ajax_output_admin_debug_wpdb', [$this, 'wpdb']);
        \add_action('wp_ajax_output_admin_debug_connectivity', [$this, 'connectivity']);
    }

    public function env(): void
    {
        Security::verify('manage_options');

        $environment = new \W18T();
        $theme       = \wp_get_theme();

        if (! \function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins    = \get_plugins();
        $plugins_output = $all_plugins ? \implode("\n", \array_keys($all_plugins)) : '';

        $dmt     = '==================================' . PHP_EOL;
        $output  = $dmt . '> Enviroment' . PHP_EOL;
        $output .= 'OS: ' . $environment->operating_system . ' - ' . $environment->operating_system->version . PHP_EOL;
        $output .= 'PHP: ' . $environment->interpreter->version . PHP_EOL;
        $output .= 'WEB: ' . $environment->web_server->version . PHP_EOL;
        $output .= 'DB: ' . $environment->database_server->version . PHP_EOL;
        $output .= 'WP: ' . $environment->platform->version . PHP_EOL;
        $output .= $dmt . '> Theme' . PHP_EOL;
        $output .= 'Name: ' . $theme->get('Name') . ' - ' . $theme->get('Version') . PHP_EOL;
        $output .= $dmt . '> Plugins' . PHP_EOL;
        $output .= $plugins_output . PHP_EOL;
        $output .= $dmt . '> CherryLink' . PHP_EOL;
        $output .= 'Version: ' . \LinkatePosts::get_linkate_version() . PHP_EOL;
        $output .= $dmt . '> Links output ' . PHP_EOL;

        echo $output; // phpcs:ignore WordPress.Security.EscapeOutput -- developer diagnostics, plain text
        \wp_die();
    }

    public function links(): void
    {
        Security::verify('manage_options');

        // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- gated by Security::verify(); trimmed id used as text only
        $post_id = isset($_POST['post_id']) ? \trim((string) \wp_unslash($_POST['post_id'])) : '';
        if ($post_id === '') {
            \wp_send_json(['links' => \__('(ID записи не указан)', 'cherrylink'), 'count' => 0]);
        }

        $data = \linkate_posts('manual_ID=' . $post_id . '&is_term=0&offset=0&mode=gutenberg&custom_text=&');

        \wp_send_json($data);
    }

    // Raw TCP-connect probe to each hop we care about, run FROM this server. Distinguishes
    // "connected", "refused" (host reachable, nothing on that port) and "timeout" (blocked /
    // dropped) — a hop map for figuring out whether a proxy/relay path is viable. Output is
    // developer-facing (goes into the debug textarea), not shown to end users.
    public function connectivity(): void
    {
        Security::verify('manage_options');

        $targets = [
            'Cherry API (наш сервер)' => ['seocherry.apov.dev', 443],
            'GitHub (обновления)'     => ['release-assets.githubusercontent.com', 443],
            'index-ru (RU релей)'     => ['31.129.48.202', 443],
            'index-com (COM выход)'   => ['49.13.105.95', 443],
        ];

        $dmt    = PHP_EOL . PHP_EOL . '==================================' . PHP_EOL;
        $output = $dmt . '> Connectivity (TCP connect, timeout 8s)' . PHP_EOL;
        foreach ($targets as $label => $hostport) {
            list($host, $port) = $hostport;
            $errno  = 0;
            $errstr = '';
            $start  = \microtime(true);
            $fp     = @\fsockopen($host, $port, $errno, $errstr, 8);
            $ms     = (int) \round((\microtime(true) - $start) * 1000);
            if ($fp) {
                \fclose($fp);
                $output .= \sprintf('[OK]    %-26s %s:%d  — %d ms' . PHP_EOL, $label, $host, $port, $ms);
            } else {
                $output .= \sprintf('[FAIL]  %-26s %s:%d  — %d ms  (errno %d: %s)' . PHP_EOL, $label, $host, $port, $ms, $errno, $errstr);
            }
        }

        echo $output; // phpcs:ignore WordPress.Security.EscapeOutput -- developer diagnostics, plain text
        \wp_die();
    }

    public function wpdb(): void
    {
        Security::verify('manage_options');

        $dmt    = PHP_EOL . PHP_EOL . '==================================' . PHP_EOL;
        $output = $dmt . '> WPDB' . PHP_EOL;

        $db_info = \get_option('cherry_debug_info');

        if ($db_info) {
            $error   = $db_info['error'] ? $db_info['error'] : 'none';
            $output .= 'WPDB Last Query: ' . $db_info['query'] . PHP_EOL;
            $output .= 'WPDB Last Error: ' . $error . PHP_EOL;
        } else {
            $output .= 'Not found';
        }

        echo $output; // phpcs:ignore WordPress.Security.EscapeOutput -- developer diagnostics, plain text
        \wp_die();
    }
}
