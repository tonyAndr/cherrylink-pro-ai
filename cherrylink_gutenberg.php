<?php

/*
 * CherryLink Plugin
 */

// Disable direct access
defined( 'ABSPATH' ) || exit;
// Define lib name
define('LINKATE_GUTENBERG_ASSETS', true);

function register_cherrylink_gutenberg_scripts() {
    $asset_file = include( plugin_dir_path( __FILE__ ) . 'js/cherry-gutenberg.asset.php' );

    $js_path = plugins_url( 'js/cherry-gutenberg.js', __FILE__ );

    wp_register_script(
        'cherrylink-gutenberg',
        $js_path,
        $asset_file['dependencies'],
        $asset_file['version']
    );
    wp_localize_script( 'cherrylink-gutenberg', 'ajax_var', array(
        'url'   => 'admin-ajax.php',
        'nonce' => wp_create_nonce( 'cherry_nonce' ),
    ) );
    wp_enqueue_script( 'cherrylink-gutenberg' );

    wp_enqueue_style(
        'cherrylink-gutenberg',
        plugins_url( 'js/cherry-gutenberg.css', __FILE__ ),
        array( 'wp-components' ),
        $asset_file['version']
    );
}
