<?php
/**
 * Gallery blocks: shared transport and account helpers (GraphQL POST, .tez names).
 *
 * @package Cyberdeck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CYBERDECK_GALLERY_DOMAINS_ENDPOINT = 'https://api.tezos.domains/graphql';
const CYBERDECK_GALLERY_STALE_TTL        = WEEK_IN_SECONDS;

/**
 * One GraphQL POST.
 *
 * @param string $endpoint Endpoint URL.
 * @param string $query    GraphQL document.
 * @param int    $timeout  Seconds.
 * @return array|WP_Error   The "data" member.
 */
function cyberdeck_gallery_http_graphql( $endpoint, $query, $timeout = 8 ) {
	$response = wp_safe_remote_post(
		$endpoint,
		array(
			'timeout'     => $timeout,
			'redirection' => 2,
			'headers'     => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			'body'        => wp_json_encode( array( 'query' => $query ) ),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( ! is_array( $body ) ) {
		return new WP_Error( 'gallery_bad_response', sprintf( 'The indexer returned an unreadable response (HTTP %d).', $code ) );
	}
	if ( ! empty( $body['errors'] ) ) {
		$message = isset( $body['errors'][0]['message'] ) ? (string) $body['errors'][0]['message'] : 'Unknown GraphQL error.';
		return new WP_Error( 'gallery_graphql', $message, array( 'status' => $code ) );
	}
	if ( $code >= 400 ) {
		return new WP_Error( 'gallery_http', sprintf( 'The indexer answered with HTTP %d.', $code ) );
	}

	return isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();
}

/**
 * Accept a tz address, a .tez name or a teia.art profile link; return an address.
 *
 * @param string $input What the editor typed.
 * @return string|WP_Error
 */
function cyberdeck_gallery_resolve_account( $input ) {
	$input = trim( (string) $input );
	if ( '' === $input ) {
		return new WP_Error( 'gallery_no_address', 'Enter a Tezos address, a .tez name or a teia.art profile link.' );
	}

	if ( preg_match( '#^https?://(?:www\.)?teia\.art/([^/?\#]+)#i', $input, $m ) ) {
		$input = rawurldecode( $m[1] );
	}

	if ( cyberdeck_gallery_is_address( $input ) ) {
		return $input;
	}

	if ( preg_match( '/^[a-z0-9-]+(\.[a-z0-9-]+)*\.tez$/i', $input ) ) {
		$name   = strtolower( $input );
		$key    = 'cd_teia_d_' . md5( $name );
		$cached = get_transient( $key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$data = cyberdeck_gallery_http_graphql(
			CYBERDECK_GALLERY_DOMAINS_ENDPOINT,
			sprintf( '{ domain(name: %s) { address } }', wp_json_encode( $name ) )
		);
		$addr = is_wp_error( $data ) ? '' : (string) ( $data['domain']['address'] ?? '' );
		if ( cyberdeck_gallery_is_address( $addr ) ) {
			set_transient( $key, $addr, 12 * HOUR_IN_SECONDS );
			return $addr;
		}
		return new WP_Error( 'gallery_unresolved', sprintf( 'Could not resolve %s to an address.', $name ) );
	}

	return new WP_Error( 'gallery_unresolved', 'Could not turn that into an address. Paste the account\'s tz1… address instead.' );
}

/**
 * Display name for an address (reverse .tez record), cached. Never throws.
 *
 * @param string $address Tezos address.
 * @return string Empty when unknown.
 */
function cyberdeck_gallery_display_name( $address ) {
	if ( ! cyberdeck_gallery_is_address( $address ) ) {
		return '';
	}
	$key    = 'cd_teia_n_' . md5( $address );
	$cached = get_transient( $key );
	if ( false !== $cached ) {
		return (string) $cached;
	}

	$data = cyberdeck_gallery_http_graphql(
		CYBERDECK_GALLERY_DOMAINS_ENDPOINT,
		sprintf( '{ reverseRecord(address: %s) { domain { name } } }', wp_json_encode( $address ) ),
		5
	);
	$name = is_wp_error( $data ) ? '' : (string) ( $data['reverseRecord']['domain']['name'] ?? '' );

	set_transient( $key, $name, '' === $name ? HOUR_IN_SECONDS : 12 * HOUR_IN_SECONDS );
	return $name;
}
