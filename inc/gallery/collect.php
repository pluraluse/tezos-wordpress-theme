<?php
/**
 * Gallery blocks: "Collect" support (server side).
 *
 * What the server decides, so block settings and page content can never change it:
 *  - which marketplace contracts may be bought from, and which entrypoint each uses (adapters below)
 *  - the largest purchase offered on-site
 *  - the live listing (price, edition count, seller), fetched fresh from objkt's API
 *
 * The browser (assets/gallery/collect.js) then asks the visitor's own wallet, via Beacon, to
 * sign the transaction. The site never holds funds or keys.
 *
 * Scope: fixed-price listings paid in tez. Auctions, open editions, offers and token-priced
 * listings are never offered on-site; visitors are linked to the marketplace instead.
 *
 * @package Cyberdeck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cyberdeck_collect_enabled() {
	return '' !== (string) cyberdeck_tezos_option( 'collect_enabled', '' );
}

function cyberdeck_collect_rpc() {
	$rpc = (string) cyberdeck_tezos_option( 'rpc_url', 'https://mainnet.api.tez.ie' );
	return 0 === strpos( $rpc, 'https://' ) ? $rpc : 'https://mainnet.api.tez.ie';
}

/**
 * Largest purchase (price x editions) offered on-site, in mutez.
 */
function cyberdeck_collect_cap_mutez() {
	return (int) round( (float) cyberdeck_tezos_option( 'collect_cap_xtz', 50 ) * 1000000 );
}

/**
 * Marketplace contracts each block type may buy from. A listing on any other contract is shown
 * as "listed elsewhere" with a link, never purchased on-site.
 *
 * - objkt (and Mederu, whose docs route purchases through objkt): objkt Marketplace v4,
 *   address from docs.objkt.com ("Marketplace contracts").
 * - Teia: Teia's own swap contract, address from the teia-docs indexer notes.
 *
 * The entrypoint names are checked in the browser against the contract's real interface before
 * anything is sent; an interface that does not match is refused.
 *
 * @return array<string,array{label:string,contracts:array<string,string>}>
 */
function cyberdeck_collect_adapters() {
	$objkt = array(
		'label'     => 'objkt',
		'contracts' => array(
			'KT1WvzYHCNBvDSdwafTHv7nJ1dWmZ8GCYuuC' => 'fulfill_ask',
		),
	);

	$adapters = array(
		'objkt'  => $objkt,
		'mederu' => $objkt,
		'teia'   => array(
			'label'     => 'Teia',
			'contracts' => array(
				'KT1PHubm9HtyQEJ4BBpMTVomq6mhbfNZ9z5w' => 'collect',
			),
		),
	);

	$adapters = apply_filters( 'cyberdeck_collect_adapters', $adapters );

	// Defensive: drop anything that is not a well-formed contract => entrypoint pair.
	foreach ( $adapters as $slug => $adapter ) {
		foreach ( (array) ( $adapter['contracts'] ?? array() ) as $contract => $entrypoint ) {
			if ( ! cyberdeck_gallery_is_address( (string) $contract ) || ! preg_match( '/^[a-z_]{1,32}$/', (string) $entrypoint ) ) {
				unset( $adapters[ $slug ]['contracts'][ $contract ] );
			}
		}
	}
	return $adapters;
}

/**
 * Cheap per-visitor throttle for the public listing route (30 requests/minute).
 */
function cyberdeck_collect_rate_limited() {
	$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$key = 'cd_listing_rl_' . md5( $ip );
	$n   = (int) get_transient( $key );
	if ( $n >= 30 ) {
		return true;
	}
	set_transient( $key, $n + 1, MINUTE_IN_SECONDS );
	return false;
}

/**
 * Fetch the active listings of a token (objkt API) and decide what can be bought on-site.
 *
 * @param string $provider Block provider slug.
 * @param string $fa2      Collection contract.
 * @param string $token_id Token id (digits).
 * @return array Response body for the REST route.
 */
function cyberdeck_collect_lookup( $provider, $fa2, $token_id ) {
	$adapters = cyberdeck_collect_adapters();
	$adapter  = $adapters[ $provider ] ?? null;
	$page     = 'https://objkt.com/tokens/' . rawurlencode( $fa2 ) . '/' . rawurlencode( $token_id );
	if ( 'teia' === $provider ) {
		$page = cyberdeck_teia_token_url( $fa2, $token_id );
	}

	$base = array(
		'provider' => $provider,
		'label'    => $adapter['label'] ?? $provider,
		'page'     => $page,
		'network'  => 'mainnet',
	);

	if ( ! cyberdeck_collect_enabled() || ! $adapter ) {
		return array_merge( $base, array( 'state' => 'disabled' ) );
	}

	$query = sprintf(
		'{ listing_active(where: {fa_contract: {_eq: %s}, token: {token_id: {_eq: %s}}}, order_by: {price_xtz: asc}, limit: 25) { bigmap_key marketplace_contract price amount_left seller_address expiry currency { type } } }',
		wp_json_encode( $fa2 ),
		wp_json_encode( $token_id )
	);

	$key  = 'cd_listing_' . md5( $query );
	$rows = get_transient( $key );
	if ( ! is_array( $rows ) ) {
		$data = cyberdeck_objkt_gql( $query );
		if ( is_wp_error( $data ) ) {
			return array_merge( $base, array( 'state' => 'error', 'message' => 'Could not read the current price.' ) );
		}
		$rows = (array) ( $data['listing_active'] ?? array() );
		set_transient( $key, $rows, 15 );
	}

	$now       = time();
	$cap       = cyberdeck_collect_cap_mutez();
	$supported = array();
	$elsewhere = 0;

	foreach ( $rows as $row ) {
		$left     = (int) ( $row['amount_left'] ?? 0 );
		$price    = (string) ( $row['price'] ?? '' );
		$contract = (string) ( $row['marketplace_contract'] ?? '' );
		$expiry   = isset( $row['expiry'] ) ? strtotime( (string) $row['expiry'] ) : false;

		if ( $left < 1 || ! ctype_digit( $price ) || '' === (string) ( $row['bigmap_key'] ?? '' ) ) {
			continue;
		}
		if ( $expiry && $expiry < $now ) {
			continue;
		}

		$entrypoint = $adapter['contracts'][ $contract ] ?? null;
		$is_tez     = 'tez' === ( $row['currency']['type'] ?? '' );
		$within_cap = (int) $price <= $cap; // a single edition must fit the cap; the browser checks price x quantity too.

		if ( $entrypoint && $is_tez && $within_cap && cyberdeck_gallery_is_address( (string) ( $row['seller_address'] ?? '' ) ) ) {
			$supported[] = array(
				'id'          => (string) $row['bigmap_key'],
				'contract'    => $contract,
				'entrypoint'  => $entrypoint,
				'price_mutez' => $price,
				'left'        => $left,
				'seller'      => (string) $row['seller_address'],
				'expires'     => $expiry ? gmdate( 'c', $expiry ) : '',
			);
		} else {
			++$elsewhere;
		}
	}

	if ( $supported ) {
		usort(
			$supported,
			static function ( $a, $b ) {
				return (int) $a['price_mutez'] <=> (int) $b['price_mutez'];
			}
		);
		return array_merge(
			$base,
			array(
				'state'      => 'ok',
				'listing'    => $supported[0],
				'cap_mutez'  => (string) $cap,
				'also_other' => $elsewhere,
			)
		);
	}

	return array_merge( $base, array( 'state' => $elsewhere ? 'elsewhere' : 'none' ) );
}

/**
 * REST: GET /wp-json/cyberdeck/v1/listing?provider=objkt&fa2=KT1...&token=123  (public, read-only)
 */
function cyberdeck_collect_register_rest() {
	register_rest_route(
		'cyberdeck/v1',
		'/listing',
		array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'args'                => array(
				'provider' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
				),
				'fa2'      => array(
					'type'     => 'string',
					'required' => true,
				),
				'token'    => array(
					'type'     => 'string',
					'required' => true,
				),
			),
			'callback'            => static function ( WP_REST_Request $request ) {
				$provider = (string) $request['provider'];
				$fa2      = (string) $request['fa2'];
				$token_id = (string) $request['token'];

				if ( ! isset( cyberdeck_collect_adapters()[ $provider ] ) || ! cyberdeck_gallery_is_address( $fa2 ) || ! preg_match( '/^\d{1,12}$/', $token_id ) ) {
					return new WP_Error( 'collect_bad_request', 'Invalid request.', array( 'status' => 400 ) );
				}
				if ( cyberdeck_collect_rate_limited() ) {
					return new WP_Error( 'collect_rate_limited', 'Too many requests. Try again in a minute.', array( 'status' => 429 ) );
				}

				$response = rest_ensure_response( cyberdeck_collect_lookup( $provider, $fa2, $token_id ) );
				$response->header( 'Cache-Control', 'no-store' );
				return $response;
			},
		)
	);
}
add_action( 'rest_api_init', 'cyberdeck_collect_register_rest' );
