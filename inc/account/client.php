<?php
/**
 * Tezos Account block: data layer (TzKT or TzStats for chain stats, Tezos Domains for names).
 *
 * Every source is fetched separately, cached, and allowed to fail on its own, so one slow or
 * changed API never blanks the whole card. Only public chain data is read.
 *
 * @package Cyberdeck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CYBERDECK_ACCOUNT_DEFAULT_TZKT   = 'https://api.tzkt.io';
const CYBERDECK_ACCOUNT_DEFAULT_TZSTATS = 'https://api.tzstats.com';

/**
 * Base URL for a stats API (https only; set under Settings > Tezos blocks).
 */
function cyberdeck_account_base( $which ) {
	$key     = 'tzstats' === $which ? 'tzstats_endpoint' : 'tzkt_endpoint';
	$default = 'tzstats' === $which ? CYBERDECK_ACCOUNT_DEFAULT_TZSTATS : CYBERDECK_ACCOUNT_DEFAULT_TZKT;
	$url     = (string) cyberdeck_tezos_option( $key, $default );
	return untrailingslashit( 0 === strpos( $url, 'https://' ) ? $url : $default );
}

/**
 * GET JSON with caching and a stale fallback.
 *
 * @param string $url     Full URL.
 * @param string $cache   Transient key.
 * @param int    $ttl     Seconds.
 * @return array|WP_Error
 */
function cyberdeck_account_get( $url, $cache, $ttl ) {
	$cached = get_transient( $cache );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$response = wp_safe_remote_get(
		$url,
		array(
			'timeout'     => 8,
			'redirection' => 2,
			'headers'     => array( 'Accept' => 'application/json' ),
		)
	);

	$fail = null;
	if ( is_wp_error( $response ) ) {
		$fail = $response;
	} else {
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 404 === $code ) {
			// An address the explorer has never seen: a real answer, cached briefly.
			set_transient( $cache, array( '__empty' => true ), 5 * MINUTE_IN_SECONDS );
			return array( '__empty' => true );
		}
		if ( $code >= 400 || ! is_array( $body ) ) {
			$fail = new WP_Error( 'account_http', sprintf( 'The service answered with HTTP %d.', $code ) );
		}
	}

	if ( $fail ) {
		$stale = get_transient( $cache . '_stale' );
		return is_array( $stale ) ? $stale : $fail;
	}

	set_transient( $cache, $body, max( MINUTE_IN_SECONDS, (int) $ttl ) );
	set_transient( $cache . '_stale', $body, WEEK_IN_SECONDS );
	return $body;
}

/**
 * Chain stats from TzKT (default).
 *
 * @return array|WP_Error Normalized: balance (mutez), tx, first, last, delegate, delegate_name, tokens, type, alias.
 */
function cyberdeck_account_tzkt( $address, $ttl ) {
	$raw = cyberdeck_account_get( cyberdeck_account_base( 'tzkt' ) . '/v1/accounts/' . rawurlencode( $address ), 'cd_teia_acct_tzkt_' . md5( $address ), $ttl );
	if ( is_wp_error( $raw ) ) {
		return $raw;
	}
	if ( ! empty( $raw['__empty'] ) ) {
		return array( 'type' => 'empty' );
	}

	return array(
		'type'          => (string) ( $raw['type'] ?? '' ),
		'alias'         => (string) ( $raw['alias'] ?? '' ),
		'balance'       => isset( $raw['balance'] ) ? (string) (int) $raw['balance'] : '',
		'tx'            => isset( $raw['numTransactions'] ) ? (int) $raw['numTransactions'] : null,
		'first'         => (string) ( $raw['firstActivityTime'] ?? '' ),
		'last'          => (string) ( $raw['lastActivityTime'] ?? '' ),
		'delegate'      => (string) ( $raw['delegate']['address'] ?? '' ),
		'delegate_name' => (string) ( $raw['delegate']['alias'] ?? '' ),
		'tokens'        => isset( $raw['activeTokensCount'] ) ? (int) $raw['activeTokensCount'] : null,
	);
}

/**
 * Chain stats from TzStats (optional). Only fields confirmed in its public SDK are used, and each is shown
 * only if present. Its free API is documented for non-commercial use.
 *
 * @return array|WP_Error Same shape as the TzKT result.
 */
function cyberdeck_account_tzstats( $address, $ttl ) {
	$raw = cyberdeck_account_get( cyberdeck_account_base( 'tzstats' ) . '/explorer/account/' . rawurlencode( $address ), 'cd_teia_acct_tzstats_' . md5( $address ), $ttl );
	if ( is_wp_error( $raw ) ) {
		return $raw;
	}
	if ( ! empty( $raw['__empty'] ) ) {
		return array( 'type' => 'empty' );
	}

	$time = static function ( $ms ) {
		return is_numeric( $ms ) && (float) $ms > 0 ? gmdate( 'c', (int) ( (float) $ms / 1000 ) ) : '';
	};

	return array(
		'type'          => (string) ( $raw['address_type'] ?? '' ),
		'alias'         => '',
		// TzStats reports spendable_balance in tez (float); convert to mutez for one formatter.
		'balance'       => isset( $raw['spendable_balance'] ) ? (string) (int) round( (float) $raw['spendable_balance'] * 1000000 ) : '',
		'tx'            => isset( $raw['n_tx'] ) ? (int) $raw['n_tx'] : null,
		'first'         => $time( $raw['first_seen_time'] ?? null ),
		'last'          => $time( $raw['last_seen_time'] ?? null ),
		'delegate'      => (string) ( $raw['baker'] ?? '' ),
		'delegate_name' => '',
		'tokens'        => null,
	);
}

/**
 * Tezos Domains: the primary (reverse-record) name and the domains this account owns.
 *
 * @return array{primary:string,owned:string[],total:int,error:string}
 */
function cyberdeck_account_domains( $address, $limit, $ttl ) {
	$key    = 'cd_teia_acct_dom_' . md5( $address . '|' . $limit );
	$cached = get_transient( $key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$out = array( 'primary' => cyberdeck_gallery_display_name( $address ), 'owned' => array(), 'total' => 0, 'error' => '' );

	$addr = wp_json_encode( $address );
	$vars = array(
		sprintf( '{ domains(where: {owner: {equalTo: %s}}, first: %d, order: {field: NAME, direction: ASC}) { items { name } totalCount } }', $addr, $limit ),
		sprintf( '{ domains(where: {owner: {equalTo: %s}}, first: %d) { items { name } totalCount } }', $addr, $limit ),
		sprintf( '{ domains(where: {owner: {equalTo: %s}}, first: %d) { items { name } } }', $addr, $limit ),
	);

	$last = null;
	foreach ( $vars as $query ) {
		$data = cyberdeck_gallery_http_graphql( CYBERDECK_GALLERY_DOMAINS_ENDPOINT, $query );
		if ( is_wp_error( $data ) ) {
			$last = $data;
			if ( 'gallery_graphql' !== $data->get_error_code() ) {
				break; // network trouble: another query shape will not help.
			}
			continue;
		}
		$items = (array) ( $data['domains']['items'] ?? array() );
		foreach ( $items as $row ) {
			$name = (string) ( $row['name'] ?? '' );
			if ( preg_match( '/^[a-z0-9.-]+\.tez$/i', $name ) ) {
				$out['owned'][] = strtolower( $name );
			}
		}
		$out['total'] = isset( $data['domains']['totalCount'] ) ? (int) $data['domains']['totalCount'] : count( $out['owned'] );
		$last         = null;
		break;
	}
	if ( $last ) {
		$out['error'] = $last->get_error_message();
	}

	// The primary name goes first and is never repeated in the list.
	if ( '' !== $out['primary'] ) {
		$out['owned'] = array_values( array_diff( $out['owned'], array( strtolower( $out['primary'] ) ) ) );
	}

	set_transient( $key, $out, $out['error'] ? 2 * MINUTE_IN_SECONDS : max( MINUTE_IN_SECONDS, (int) $ttl ) );
	return $out;
}
