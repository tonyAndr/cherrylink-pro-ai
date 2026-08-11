<?php

declare(strict_types=1);

namespace CherryLink\Admin;

use CherryLink\Ajax\Security;
use CherryLink\Contracts\Registerable;
use CherryLink\Support\Options;
use CherryLink\Support\PluginMeta;

/**
 * Registers + enqueues the settings-page admin assets (Tailwind runtime, admin / index /
 * csv scripts, admin CSS), the shared AJAX nonce, the link-scheme flag, and the JS
 * translations. Only fires on the CherryLink settings screen.
 *
 * Ported from linkate_posts_wp_admin_style() in the legacy cherrylink_main.php.
 */
final class AdminAssets implements Registerable
{
    /** @var Options */
    private $options;

    public function __construct(Options $options)
    {
        $this->options = $options;
    }

    public function register(): void
    {
        \add_action('admin_enqueue_scripts', [$this, 'enqueue'], 1);
    }

    public function enqueue(): void
    {
        if (! PluginMeta::isSettingsPage()) {
            return;
        }

        $url  = CHERRYLINK_DIR_URL;
        $lang = CHERRYLINK_DIR . 'languages';

        \wp_register_style('cherrylink-css-admin', $url . 'css/cherry-admin.css', [], PluginMeta::assetVer('css/cherry-admin.css'));
        \wp_enqueue_style('cherrylink-css-admin');

        \wp_register_script('cherrylink-js-tailwind', $url . 'js/tailwind.js', [], PluginMeta::assetVer('js/tailwind.js'));
        \wp_register_script('cherrylink-js-admin', $url . 'js/cherry-admin.js', ['jquery', 'wp-i18n'], PluginMeta::assetVer('js/cherry-admin.js'));
        \wp_register_script('cherrylink-js-admin-csv', $url . 'js/cherry-admin-csv.js', ['jquery', 'wp-i18n'], PluginMeta::assetVer('js/cherry-admin-csv.js'));
        \wp_register_script('cherrylink-js-admin-index', $url . 'js/cherry-admin-index.js', ['jquery', 'wp-i18n'], PluginMeta::assetVer('js/cherry-admin-index.js'));

        // JS translations (languages/cherrylink-<locale>-<handle>.json) for the admin scripts.
        \wp_set_script_translations('cherrylink-js-admin', 'cherrylink', $lang);
        \wp_set_script_translations('cherrylink-js-admin-csv', 'cherrylink', $lang);
        \wp_set_script_translations('cherrylink-js-admin-index', 'cherrylink', $lang);

        $opts = $this->options->main();
        \wp_localize_script('cherrylink-js-admin', 'scheme', ['state' => isset($opts['linkate_scheme_exists'])]);

        // Shared AJAX nonce, localized to the first-enqueued admin handle so the
        // cherry_ajax global is defined before the index/csv scripts run.
        \wp_localize_script('cherrylink-js-admin', 'cherry_ajax', ['nonce' => Security::nonce()]);

        \wp_enqueue_script('cherrylink-js-tailwind');
        \wp_enqueue_script('cherrylink-js-admin');
        \wp_enqueue_script('cherrylink-js-admin-index');
        \wp_enqueue_script('cherrylink-js-admin-csv');
    }
}
