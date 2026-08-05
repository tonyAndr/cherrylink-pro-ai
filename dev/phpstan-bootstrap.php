<?php

/**
 * PHPStan bootstrap: declare the plugin constants that the entry file
 * (cherrylink_main.php) defines at runtime, so static analysis of src/ can
 * resolve them without loading the whole plugin. Values mirror the entry file.
 */

declare(strict_types=1);

defined('CHERRYLINK_TEXT_DOMAIN') || define('CHERRYLINK_TEXT_DOMAIN', 'cherrylink-td');

// API client constants — defined at runtime by cherrylink_vector_api.php (derived from
// saved options); mirror them here so static analysis of src/Api/Client.php resolves them.
defined('CHERRY_VECTOR_API_URL') || define('CHERRY_VECTOR_API_URL', 'https://seocherry.apov.dev');
defined('CL_ALT_API_PORT') || define('CL_ALT_API_PORT', 9443);
defined('CL_ALT_API_IP') || define('CL_ALT_API_IP', '31.129.48.202');
