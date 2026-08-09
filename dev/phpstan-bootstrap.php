<?php

/**
 * PHPStan bootstrap: declare the plugin constants that the entry file
 * (cherrylink_main.php) defines at runtime, so static analysis of src/ can
 * resolve them without loading the whole plugin. Values mirror the entry file.
 */

declare(strict_types=1);

defined('CHERRYLINK_TEXT_DOMAIN') || define('CHERRYLINK_TEXT_DOMAIN', 'cherrylink');

// Plugin path/URL constants — defined at runtime by cherrylink_main.php via
// plugin_dir_path()/plugin_dir_url(); mirror them so analysis of the services
// that build asset URLs from them resolves cleanly.
defined('CHERRYLINK_DIR') || define('CHERRYLINK_DIR', '/plugin/');
defined('CHERRYLINK_DIR_URL') || define('CHERRYLINK_DIR_URL', 'https://example.test/wp-content/plugins/cherrylink-pro-ai/');
defined('CHERRYLINK_INITIAL_LIMIT') || define('CHERRYLINK_INITIAL_LIMIT', 100);

// API client constants — defined at runtime by cherrylink_vector_api.php (derived from
// saved options); mirror them here so static analysis of src/Api/Client.php resolves them.
defined('CHERRY_VECTOR_API_URL') || define('CHERRY_VECTOR_API_URL', 'https://seocherry.apov.dev');
defined('CL_ALT_API_PORT') || define('CL_ALT_API_PORT', 9443);
defined('CL_ALT_API_IP') || define('CL_ALT_API_IP', '31.129.48.202');

// ── License gate globals (defined at runtime in cherrylink_install.php) ──────
// Namespaced services call these shims inside their hook callbacks; declare them
// here so analysis of src/ resolves them without loading the whole plugin.
if (! function_exists('cl_is_licensed')) {
    function cl_is_licensed(): bool
    {
        return true;
    }
}
if (! function_exists('cl_mark_index_dirty')) {
    function cl_mark_index_dirty(): void
    {
    }
}
if (! function_exists('cl_clear_index_dirty')) {
    function cl_clear_index_dirty(): void
    {
    }
}
if (! function_exists('cl_index_status_data')) {
    /** @return array<string,mixed> */
    function cl_index_status_data(bool $refresh = false): array
    {
        return [];
    }
}
if (! function_exists('linkate_posts_install')) {
    /** @return mixed */
    function linkate_posts_install()
    {
        return true;
    }
}

// ── Legacy globals used by Debug\Debugger (defined in cherrylink_main.php +
//    env-info/W18T.class.php at runtime) ──────────────────────────────────────
if (! function_exists('linkate_posts')) {
    /** @return mixed */
    function linkate_posts(string $args = '')
    {
        return '';
    }
}
if (! class_exists('W18T')) {
    class W18T
    {
        /** @var mixed */
        public $operating_system;

        /** @var mixed */
        public $interpreter;

        /** @var mixed */
        public $web_server;

        /** @var mixed */
        public $database_server;

        /** @var mixed */
        public $platform;
    }
}

// ── Action Scheduler stubs (bundled vendor lib, not covered by WP stubs) ─────
if (! function_exists('as_enqueue_async_action')) {
    function as_enqueue_async_action(string $hook, array $args = [], string $group = ''): int
    {
        return 0;
    }
}
if (! function_exists('as_has_scheduled_action')) {
    function as_has_scheduled_action(string $hook, ?array $args = null, string $group = ''): bool
    {
        return false;
    }
}
if (! function_exists('as_get_scheduled_actions')) {
    function as_get_scheduled_actions(array $args = [], string $return_format = 'OBJECT'): array
    {
        return [];
    }
}
if (! class_exists('ActionScheduler_Store')) {
    class ActionScheduler_Store
    {
        public const STATUS_PENDING = 'pending';

        /** @param int|string $action_id */
        public function cancel_action($action_id): void
        {
        }
    }
}
if (! class_exists('ActionScheduler_Runner')) {
    class ActionScheduler_Runner
    {
        public function run(string $context = ''): int
        {
            return 0;
        }
    }
}
// ── LinkatePosts lifecycle/asset helpers (global class in cherrylink_main.php) ──
if (! class_exists('LinkatePosts')) {
    class LinkatePosts
    {
        /** @param string $rel */
        public static function asset_ver($rel): string
        {
            return '';
        }

        public static function get_linkate_version(): string
        {
            return '';
        }

        public static function linkate_is_plugin_admin_page(string $page = 'settings'): bool
        {
            return false;
        }
    }
}

if (! class_exists('ActionScheduler')) {
    class ActionScheduler
    {
        public static function store(): ActionScheduler_Store
        {
            return new ActionScheduler_Store();
        }

        public static function runner(): ActionScheduler_Runner
        {
            return new ActionScheduler_Runner();
        }
    }
}
