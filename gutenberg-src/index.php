<?php
/**
 * Plugin Name: CherryLink Gutenberg Extension
 * Plugin URI: 
 * Description: Extension for CherryLink to add Gutenberg Support.
 * Version: 1.0.0
 * Author: Anton SeoCherry.ru
 *
 */
defined( 'ABSPATH' ) || exit;

/**
 * Registers all block assets so that they can be enqueued through Gutenberg in
 * the corresponding context.
 *
 * Passes translations to JavaScript.
 */
// function register_cherrylink_gutenberg_extension() {
// 	// automatically load dependencies and version
// 	// $asset_file = include( plugin_dir_path( __FILE__ ) . 'build/index.asset.php');

// 	wp_register_script(
// 		'cherrylink-gutenberg',
// 		plugins_url( 'build/index.js', __FILE__ ),
// 		array('react', 'wp-blocks', 'wp-edit-post', 'wp-element', 'wp-i18n', 'wp-plugins', 'wp-polyfill'),
// 		'111'
// 	);

// 	wp_localize_script( 'cherrylink-gutenberg', 'ajax_var', array(
//         'url'    => 'admin-ajax.php',
//         'nonce'  => wp_create_nonce( 'cherry_nonce' ),
//     ) );

// 	register_block_type( 'cherrylink-gutenberg/test-block', array(
// 		'editor_script' => 'cherrylink-gutenberg',
// 	) );

// 	// CRB META 
// 	register_post_meta( '', 'crb-meta-links', array(
// 		'show_in_rest' => true,
// 		'single' => true,
// 		'type' => 'string',
// 	) );

// 	register_post_meta( '', 'crb-meta-show', array(
// 		'show_in_rest' => true,
// 		'single' => true,
// 		'type' => 'string',
// 	) );

// 	register_post_meta( '', 'crb-meta-show-edited', array(
// 		'show_in_rest' => true,
// 		'single' => true,
// 		'type' => 'string',
// 	) );

// 	register_post_meta( '', 'crb-meta-use-manual', array(
// 		'show_in_rest' => true,
// 		'single' => true,
// 		'type' => 'string',
// 	) );
// }
// add_action( 'init', 'register_cherrylink_gutenberg_extension' );