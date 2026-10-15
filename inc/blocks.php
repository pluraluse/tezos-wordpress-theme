<?php
/**
 * Custom block loader.
 *
 * Two kinds of block are picked up automatically:
 *   1. No-build blocks:  blocks/<name>/block.json   (plain JS/CSS/PHP, like teia-profile)
 *   2. Built blocks:     blocks/build/<name>/block.json  (compiled with `npm run build`)
 *
 * @package Cyberdeck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Shared gallery framework, then the providers built on it (Teia, objkt, Mederu).
require_once get_theme_file_path( 'inc/gallery/media.php' );
require_once get_theme_file_path( 'inc/gallery/settings.php' );
require_once get_theme_file_path( 'inc/gallery/http.php' );
require_once get_theme_file_path( 'inc/gallery/scope.php' );
require_once get_theme_file_path( 'inc/gallery/assets.php' );
require_once get_theme_file_path( 'inc/gallery/render.php' );
require_once get_theme_file_path( 'inc/teia/client.php' );
require_once get_theme_file_path( 'inc/objkt/client.php' );
require_once get_theme_file_path( 'inc/mederu/provider.php' );
require_once get_theme_file_path( 'inc/gallery/collect.php' );
require_once get_theme_file_path( 'inc/generative.php' );
require_once get_theme_file_path( 'inc/account/client.php' );
require_once get_theme_file_path( 'inc/account/render.php' );

function cyberdeck_register_custom_blocks() {
	// 1. No-build blocks.
	$sources = glob( get_theme_file_path( 'blocks/*/block.json' ) );
	foreach ( $sources ? $sources : array() as $file ) {
		if ( 'build' === basename( dirname( $file ) ) ) {
			continue;
		}
		register_block_type( dirname( $file ) );
	}

	// 2. Built blocks.
	$build = get_theme_file_path( 'blocks/build' );
	if ( ! is_dir( $build ) ) {
		return;
	}

	$manifest = $build . '/blocks-manifest.php';
	if ( function_exists( 'wp_register_block_types_from_metadata_collection' ) && file_exists( $manifest ) ) {
		wp_register_block_types_from_metadata_collection( $build, $manifest );
		return;
	}

	$files = glob( $build . '/*/block.json' );
	foreach ( $files ? $files : array() as $file ) {
		register_block_type( dirname( $file ) );
	}
}
add_action( 'init', 'cyberdeck_register_custom_blocks' );
