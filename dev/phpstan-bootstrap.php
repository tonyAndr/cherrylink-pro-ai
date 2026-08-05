<?php

/**
 * PHPStan bootstrap: declare the plugin constants that the entry file
 * (cherrylink_main.php) defines at runtime, so static analysis of src/ can
 * resolve them without loading the whole plugin. Values mirror the entry file.
 */

declare(strict_types=1);

defined('CHERRYLINK_TEXT_DOMAIN') || define('CHERRYLINK_TEXT_DOMAIN', 'cherrylink-td');
