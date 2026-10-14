<?php
/**
 * Cyberdeck bootstrap.
 *
 * Almost everything visual lives in theme.json, styles/*.json and
 * assets/css/cyber.css. This file only wires up supports, assets,
 * block style variations, categories and the (future) block loader.
 *
 * @package Cyberdeck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CYBERDECK_VERSION', wp_get_theme()->get( 'Version' ) );

require_once get_theme_file_path( 'inc/blocks.php' );

/**
 * Theme supports.
 */
function cyberdeck_setup() {
	add_theme_support( 'wp-block-styles' );
	add_theme_support( 'responsive-embeds' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' ) );
}
add_action( 'after_setup_theme', 'cyberdeck_setup' );

/**
 * Effects, block styles and utility classes.
 * enqueue_block_assets runs on the front end AND inside the editor canvas,
 * so what you see while editing matches the published page.
 */
function cyberdeck_enqueue_assets() {
	$path = get_theme_file_path( 'assets/css/cyber.css' );
	wp_enqueue_style(
		'cyberdeck-cyber',
		get_theme_file_uri( 'assets/css/cyber.css' ),
		array(),
		file_exists( $path ) ? (string) filemtime( $path ) : CYBERDECK_VERSION
	);
}
add_action( 'enqueue_block_assets', 'cyberdeck_enqueue_assets' );

/**
 * Block style variations. They appear in each block's "Styles" panel in the editor.
 * The CSS for every name below lives in assets/css/cyber.css (.is-style-{name}).
 */
function cyberdeck_register_block_styles() {
	$styles = array(
		'core/group'               => array(
			'neon-panel'      => 'Neon Panel',
			'terminal-window' => 'Terminal Window',
			'grid-backdrop'   => 'Grid Backdrop',
			'crt'             => 'CRT Screen',
			'corner-brackets' => 'Corner Brackets',
		),
		'core/cover'               => array(
			'crt' => 'CRT Screen',
		),
		'core/heading'             => array(
			'glitch' => 'Glitch',
			'prompt' => 'Prompt',
			'neon'   => 'Neon Glow',
		),
		'core/paragraph'           => array(
			'typewriter' => 'Typewriter',
			'comment'    => 'Code Comment',
		),
		'core/button'              => array(
			'ghost' => 'Ghost',
		),
		'core/image'               => array(
			'corner-brackets' => 'Corner Brackets',
			'scanned'         => 'Scanned',
		),
		'core/post-featured-image' => array(
			'corner-brackets' => 'Corner Brackets',
			'scanned'         => 'Scanned',
		),
		'core/quote'               => array(
			'log-entry' => 'Log Entry',
		),
		'core/list'                => array(
			'chevrons' => 'Chevrons',
		),
		'core/separator'           => array(
			'dashed' => 'Dashed',
		),
		'core/site-title'          => array(
			'prompt' => 'Prompt',
		),
	);

	foreach ( $styles as $block => $variations ) {
		foreach ( $variations as $name => $label ) {
			register_block_style(
				$block,
				array(
					'name'  => $name,
					'label' => $label,
				)
			);
		}
	}
}
add_action( 'init', 'cyberdeck_register_block_styles' );

/**
 * Pattern categories.
 */
function cyberdeck_register_pattern_categories() {
	register_block_pattern_category(
		'cyberdeck',
		array( 'label' => 'Cyberdeck' )
	);
	register_block_pattern_category(
		'cyberdeck-web3',
		array( 'label' => 'Cyberdeck: Tezos / Web3' )
	);
}
add_action( 'init', 'cyberdeck_register_pattern_categories' );

/**
 * Reserved inserter category for the upcoming Tezos blocks
 * (objkt, Teia, Mederu, ...). Blocks that set "category": "cyberdeck-web3"
 * in their block.json will be grouped here, at the top of the inserter.
 *
 * @param array $categories Existing block categories.
 * @return array
 */
function cyberdeck_block_categories( $categories ) {
	array_unshift(
		$categories,
		array(
			'slug'  => 'cyberdeck-web3',
			'title' => 'Cyberdeck: Tezos / Web3',
			'icon'  => null,
		)
	);
	return $categories;
}
add_filter( 'block_categories_all', 'cyberdeck_block_categories' );
