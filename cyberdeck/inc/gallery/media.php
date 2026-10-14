<?php
/**
 * Gallery blocks: media helpers (mime -> kind, URI -> browsable URL), shared by Teia, Mederu, ...
 *
 * @package Cyberdeck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every media "kind" the block understands, in the order shown in the editor.
 *
 * @return array<string,string> slug => label
 */
function cyberdeck_gallery_kinds() {
	return array(
		'image'       => 'Images',
		'gif'         => 'Animated GIFs',
		'video'       => 'Video',
		'audio'       => 'Audio',
		'model'       => '3D models (GLB / glTF)',
		'interactive' => 'Interactive / generative (HTML, zip)',
		'other'       => 'Other (PDF, text, unknown)',
	);
}

/**
 * Map a mime type to a kind.
 *
 * @param string $mime Mime type, e.g. "model/gltf-binary".
 * @return string One of the keys of cyberdeck_gallery_kinds().
 */
function cyberdeck_gallery_kind( $mime ) {
	$mime = strtolower( trim( explode( ';', (string) $mime )[0] ) );

	if ( 'image/gif' === $mime ) {
		return 'gif';
	}
	if ( 0 === strpos( $mime, 'image/' ) ) {
		return 'image';
	}
	if ( 0 === strpos( $mime, 'video/' ) ) {
		return 'video';
	}
	if ( 0 === strpos( $mime, 'audio/' ) ) {
		return 'audio';
	}
	if ( 0 === strpos( $mime, 'model/' ) || 'application/glb' === $mime ) {
		return 'model';
	}
	if ( in_array( $mime, array( 'application/x-directory', 'text/html', 'application/zip', 'application/x-zip-compressed' ), true ) ) {
		return 'interactive';
	}
	return 'other';
}

/**
 * IPFS gateway presets. "custom" is configured under Settings > Tezos blocks.
 *
 * @return array<string,array{label:string,base:string}>
 */
function cyberdeck_gallery_gateways() {
	$custom = trailingslashit( (string) cyberdeck_tezos_option( 'custom_gateway', '' ) );

	return array(
		'teia'   => array(
			'label' => 'Teia cache (cache.teia.rocks)',
			'base'  => 'https://cache.teia.rocks/ipfs/',
		),
		'ipfs'   => array(
			'label' => 'ipfs.io',
			'base'  => 'https://ipfs.io/ipfs/',
		),
		'dweb'   => array(
			'label' => 'dweb.link',
			'base'  => 'https://dweb.link/ipfs/',
		),
		'custom' => array(
			'label' => 'Custom (Settings > Tezos blocks)',
			'base'  => ( 0 === strpos( $custom, 'https://' ) && '/' !== $custom ) ? $custom : '',
		),
	);
}

/**
 * Base URL for a gateway slug. Falls back to the Teia cache.
 *
 * @param string $slug Gateway slug.
 * @return string
 */
function cyberdeck_gallery_gateway_base( $slug ) {
	$all = cyberdeck_gallery_gateways();
	if ( isset( $all[ $slug ] ) && '' !== $all[ $slug ]['base'] ) {
		return $all[ $slug ]['base'];
	}
	return $all['teia']['base'];
}

/**
 * Turn a token URI (ipfs://, ar://, https://, bare CID) into a URL a browser can load.
 * Anything else (data:, javascript:, http:) returns an empty string.
 *
 * @param string $uri     Raw URI from token metadata.
 * @param string $gateway Gateway slug.
 * @return string
 */
function cyberdeck_gallery_resolve_uri( $uri, $gateway = 'teia' ) {
	$uri = trim( (string) $uri );
	if ( '' === $uri ) {
		return '';
	}

	if ( 0 === stripos( $uri, 'ipfs://' ) ) {
		$path = substr( $uri, 7 );
		$path = preg_replace( '#^ipfs/#i', '', $path );
		return cyberdeck_gallery_gateway_base( $gateway ) . ltrim( $path, '/' );
	}

	if ( 0 === stripos( $uri, 'ar://' ) ) {
		return 'https://arweave.net/' . ltrim( substr( $uri, 5 ), '/' );
	}

	// Bare CID (v0 "Qm..." or v1 "baf...").
	if ( preg_match( '#^(Qm[1-9A-HJ-NP-Za-km-z]{44}|baf[a-z2-7]{50,})(/.*)?$#', $uri ) ) {
		return cyberdeck_gallery_gateway_base( $gateway ) . $uri;
	}

	if ( 0 === stripos( $uri, 'https://' ) ) {
		return esc_url_raw( $uri, array( 'https' ) );
	}

	return '';
}

/**
 * Validate a Tezos account address (tz1/tz2/tz3/tz4/KT1).
 *
 * @param string $address Candidate.
 * @return bool
 */
function cyberdeck_gallery_is_address( $address ) {
	return (bool) preg_match( '/^(tz[1-4]|KT1)[1-9A-HJ-NP-Za-km-z]{33}$/', (string) $address );
}

/**
 * Format a mutez amount as "1.5 ꜩ". Empty for null/zero/non-numeric.
 *
 * @param mixed $mutez Amount in mutez.
 * @return string
 */
function cyberdeck_gallery_format_tez( $mutez ) {
	if ( null === $mutez || ! is_numeric( $mutez ) || (float) $mutez <= 0 ) {
		return '';
	}
	$tez = (float) $mutez / 1000000;
	$out = rtrim( rtrim( number_format( $tez, 6, '.', '' ), '0' ), '.' );
	return $out . ' ꜩ';
}
