<?php
/**
 * Gallery blocks: shared script/style handles and the editor "check connection" route.
 *
 * Handles are registered here and referenced by name from each block's block.json, so
 * Teia, Mederu (and later objkt) share one stylesheet and one front-end script.
 *
 * @package Cyberdeck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cyberdeck_gallery_register_assets() {
	$ver = static function ( $rel ) {
		$path = get_theme_file_path( $rel );
		return file_exists( $path ) ? (string) filemtime( $path ) : '0.3.0';
	};

	wp_register_style( 'cyberdeck-gallery', get_theme_file_uri( 'assets/gallery/gallery.css' ), array(), $ver( 'assets/gallery/gallery.css' ) );
	wp_register_style( 'cyberdeck-gallery-editor', get_theme_file_uri( 'assets/gallery/gallery-editor.css' ), array( 'cyberdeck-gallery' ), $ver( 'assets/gallery/gallery-editor.css' ) );

	wp_register_script(
		'cyberdeck-gallery-view',
		get_theme_file_uri( 'assets/gallery/gallery.js' ),
		array(),
		$ver( 'assets/gallery/gallery.js' ),
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
	wp_register_script(
		'cyberdeck-gallery-editor',
		get_theme_file_uri( 'assets/gallery/gallery-editor.js' ),
		array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-api-fetch', 'wp-server-side-render' ),
		$ver( 'assets/gallery/gallery-editor.js' ),
		true
	);
}
// Priority 5: handles must exist before blocks register at priority 10.
add_action( 'init', 'cyberdeck_gallery_register_assets', 5 );

/**
 * All gallery providers, keyed by slug.
 *
 * @return array<string,array>
 */
function cyberdeck_gallery_providers() {
	$providers = array();
	if ( function_exists( 'cyberdeck_teia_provider' ) ) {
		$providers['teia'] = cyberdeck_teia_provider();
	}
	if ( function_exists( 'cyberdeck_objkt_provider' ) ) {
		$providers['objkt'] = cyberdeck_objkt_provider();
	}
	if ( function_exists( 'cyberdeck_mederu_provider' ) ) {
		$providers['mederu'] = cyberdeck_mederu_provider();
	}
	return $providers;
}

/**
 * REST: GET /wp-json/cyberdeck/v1/gallery-check?provider=teia&address=...
 */
function cyberdeck_gallery_register_rest() {
	register_rest_route(
		'cyberdeck/v1',
		'/gallery-check',
		array(
			'methods'             => 'GET',
			'permission_callback' => static function () {
				return current_user_can( 'edit_posts' );
			},
			'args'                => array(
				'provider' => array(
					'type'              => 'string',
					'default'           => 'teia',
					'sanitize_callback' => 'sanitize_key',
				),
				'address'  => array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_textarea_field',
				),
			),
			'callback'            => static function ( WP_REST_Request $request ) {
				$providers = cyberdeck_gallery_providers();
				$provider  = $providers[ $request['provider'] ] ?? null;
				if ( ! $provider ) {
					return new WP_Error( 'gallery_unknown_provider', 'Unknown provider.', array( 'status' => 400 ) );
				}
				$resolved = call_user_func( $provider['resolve'], (string) $request['address'] );
				$first    = is_wp_error( $resolved ) ? '' : (string) $resolved[0];
				return rest_ensure_response( call_user_func( $provider['diagnose'], $first ) );
			},
		)
	);
}
add_action( 'rest_api_init', 'cyberdeck_gallery_register_rest' );
