<?php
/**
 * Teia block: data layer.
 *
 * - Reads tokens from Teia's TezTok (Hasura) indexer, server side.
 * - Detects which token fields the indexer actually exposes (introspection), so a
 *   renamed/missing field degrades gracefully instead of breaking the block.
 * - Caches results in transients and serves the last good copy if the indexer is down.
 *
 * @package Cyberdeck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CYBERDECK_TEIA_DEFAULT_ENDPOINT = 'https://teztok.teia.rocks/v1/graphql';

/** FA2 contract that Hic et Nunc / Teia OBJKTs are minted on. */
const CYBERDECK_TEIA_OBJKT_CONTRACT = 'KT1RJ6PbjHpwc3M5rw5s2Nbmefwbuwbdxton';

/**
 * Public Teia page for a token.
 *
 * @param string $fa2      Contract address.
 * @param string $token_id Token id.
 * @return string
 */
function cyberdeck_teia_token_url( $fa2, $token_id ) {
	if ( CYBERDECK_TEIA_OBJKT_CONTRACT === $fa2 ) {
		return 'https://teia.art/objkt/' . rawurlencode( $token_id );
	}
	return 'https://teia.art/' . rawurlencode( $fa2 ) . '/' . rawurlencode( $token_id );
}

/**
 * Configured indexer endpoints, primary first.
 *
 * @return string[]
 */
function cyberdeck_teia_endpoints() {
	$primary  = (string) cyberdeck_tezos_option( 'teia_endpoint', CYBERDECK_TEIA_DEFAULT_ENDPOINT );
	$fallback = (string) cyberdeck_tezos_option( 'teia_endpoint_fallback', '' );

	$list = array_filter( array( $primary, $fallback ) );
	$list = apply_filters( 'cyberdeck_teia_endpoints', $list );

	return array_values(
		array_filter(
			(array) $list,
			static function ( $url ) {
				return is_string( $url ) && 0 === strpos( $url, 'https://' );
			}
		)
	);
}

/**
 * GraphQL against the configured endpoints (primary, then fallback).
 *
 * @param string $query GraphQL document.
 * @return array|WP_Error
 */
function cyberdeck_teia_gql( $query ) {
	$last = new WP_Error( 'teia_no_endpoint', 'No valid https indexer endpoint is configured.' );

	foreach ( cyberdeck_teia_endpoints() as $endpoint ) {
		$result = cyberdeck_gallery_http_graphql( $endpoint, $query );
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}
		$last = $result;
		// A query error (bad field) would fail on the fallback too; only retry on transport errors.
		if ( 'gallery_graphql' === $result->get_error_code() ) {
			break;
		}
	}
	return $last;
}

/**
 * Token fields we would like, and the minimum we can live with.
 */
function cyberdeck_teia_wanted_fields() {
	return array(
		'fa2_address',
		'token_id',
		'name',
		'description',
		'artist_address',
		'minter_address',
		'mime_type',
		'artifact_uri',
		'display_uri',
		'thumbnail_uri',
		'minted_at',
		'editions',
		'formats',
	);
}

function cyberdeck_teia_minimal_fields() {
	return array( 'fa2_address', 'token_id', 'name', 'artist_address', 'mime_type', 'artifact_uri', 'display_uri', 'thumbnail_uri' );
}

/**
 * Which token fields does the indexer expose? Cached.
 *
 * @param bool $force Skip the cache.
 * @return array{fields:string[],all:string[],introspected:bool}
 */
function cyberdeck_teia_token_fields( $force = false ) {
	$cached = $force ? false : get_transient( 'cd_teia_fields' );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$result = array(
		'fields'       => cyberdeck_teia_minimal_fields(),
		'all'          => array(),
		'introspected' => false,
	);

	$data = cyberdeck_teia_gql( '{ __type(name: "tokens") { fields { name } } }' );
	if ( ! is_wp_error( $data ) && ! empty( $data['__type']['fields'] ) ) {
		$all     = wp_list_pluck( $data['__type']['fields'], 'name' );
		$present = array_values( array_intersect( cyberdeck_teia_wanted_fields(), $all ) );

		if ( in_array( 'fa2_address', $present, true ) && in_array( 'token_id', $present, true ) ) {
			$result = array(
				'fields'       => $present,
				'all'          => $all,
				'introspected' => true,
			);
		}
	}

	set_transient( 'cd_teia_fields', $result, $result['introspected'] ? 12 * HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS );
	return $result;
}

/**
 * Turn one indexer row into the shape the renderer uses. URIs stay raw here so the
 * cache does not depend on which IPFS gateway a block picks.
 *
 * @param array $row Token row.
 * @return array|null
 */
function cyberdeck_teia_normalize_token( array $row ) {
	if ( empty( $row['fa2_address'] ) || ! isset( $row['token_id'] ) ) {
		return null;
	}

	$artifact = (string) ( $row['artifact_uri'] ?? '' );
	$mime     = (string) ( $row['mime_type'] ?? '' );

	// The indexer's mime_type is sometimes wrong (e.g. mp4 labelled image/jpeg); the
	// token's own "formats" entry for the artifact is more reliable when present.
	$formats = $row['formats'] ?? array();
	if ( is_string( $formats ) ) {
		$formats = json_decode( $formats, true );
	}
	if ( is_array( $formats ) && '' !== $artifact ) {
		foreach ( $formats as $format ) {
			if ( is_array( $format ) && ( $format['uri'] ?? '' ) === $artifact && ! empty( $format['mimeType'] ) ) {
				$mime = (string) $format['mimeType'];
				break;
			}
		}
	}

	return array(
		'fa2'         => (string) $row['fa2_address'],
		'token_id'    => (string) $row['token_id'],
		'title'       => (string) ( $row['name'] ?? '' ),
		'description' => (string) ( $row['description'] ?? '' ),
		'artist'      => (string) ( $row['artist_address'] ?? $row['minter_address'] ?? '' ),
		'mime'        => $mime,
		'artifact'    => $artifact,
		'display'     => (string) ( $row['display_uri'] ?? '' ),
		'thumbnail'   => (string) ( $row['thumbnail_uri'] ?? '' ),
		'minted_at'   => (string) ( $row['minted_at'] ?? '' ),
		'editions'    => isset( $row['editions'] ) ? (string) $row['editions'] : '',
		'url'         => cyberdeck_teia_token_url( (string) $row['fa2_address'], (string) $row['token_id'] ),
		'credit'      => '',
		'flag'        => '',
	);
}

/**
 * Fetch works from the Teia indexer.
 *
 * @param array $args {
 *     @type string   $address   Tezos address (validated). Not needed when $works is given.
 *     @type string   $source    "created" | "collected".
 *     @type string[] $contracts Optional FA2 contracts to restrict to.
 *     @type array    $works     Optional explicit [ ['fa2'=>..,'token_id'=>..], ... ] (overrides address/source).
 *     @type string   $token_id  Optional single token id.
 *     @type int      $fetch     How many rows to ask the indexer for.
 *     @type string   $order     "newest" | "oldest" | "random".
 *     @type int      $ttl       Cache seconds.
 * }
 * @return array{items:array,stale:bool}|WP_Error
 */
function cyberdeck_teia_fetch_works( array $args ) {
	$works     = array_values( (array) ( $args['works'] ?? array() ) );
	$address   = (string) ( $args['address'] ?? '' );
	$contracts = cyberdeck_gallery_parse_contracts( implode( ' ', (array) ( $args['contracts'] ?? array() ) ) );
	if ( ! $works && ! cyberdeck_gallery_is_address( $address ) ) {
		return new WP_Error( 'teia_bad_address', 'That does not look like a Tezos address.' );
	}

	$source    = 'collected' === ( $args['source'] ?? '' ) ? 'collected' : 'created';
	$token_id  = preg_match( '/^\d{1,12}$/', (string) ( $args['token_id'] ?? '' ) ) ? (string) $args['token_id'] : '';
	$fetch     = max( 1, min( 200, (int) ( $args['fetch'] ?? 36 ) ) );
	$direction = 'oldest' === ( $args['order'] ?? '' ) ? 'asc' : 'desc';
	$ttl       = max( MINUTE_IN_SECONDS, (int) ( $args['ttl'] ?? 30 * MINUTE_IN_SECONDS ) );

	$meta   = cyberdeck_teia_token_fields();
	$fields = $meta['fields'];
	$retry  = true;

	while ( true ) {
		$query = cyberdeck_teia_build_query(
			array(
				'address'   => $address,
				'source'    => $source,
				'contracts' => $contracts,
				'works'     => $works,
				'token_id'  => $token_id,
				'fetch'     => $works ? max( $fetch, count( $works ) ) : $fetch,
				'direction' => $direction,
				'fields'    => $fields,
			)
		);
		$key = 'cd_teia_w_' . md5( $query );

		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return array( 'items' => $cached, 'stale' => false );
		}

		$data = cyberdeck_teia_gql( $query );

		if ( is_wp_error( $data ) ) {
			// Unknown field: re-detect the schema once and retry with the minimal set.
			if ( $retry && 'gallery_graphql' === $data->get_error_code() && false !== stripos( $data->get_error_message(), 'not found' ) ) {
				$retry  = false;
				$fields = cyberdeck_teia_minimal_fields();
				delete_transient( 'cd_teia_fields' );
				continue;
			}

			$stale = get_transient( $key . '_stale' );
			if ( is_array( $stale ) ) {
				return array( 'items' => $stale, 'stale' => true );
			}
			return $data;
		}

		$collected = ( ! $works && 'collected' === $source );
		$rows      = $collected ? ( $data['holdings'] ?? array() ) : ( $data['tokens'] ?? array() );
		$items     = array();
		foreach ( (array) $rows as $row ) {
			if ( $collected ) {
				$row = $row['token'] ?? null;
			}
			$item = is_array( $row ) ? cyberdeck_teia_normalize_token( $row ) : null;
			if ( $item ) {
				$items[] = $item;
			}
		}

		set_transient( $key, $items, $ttl );
		set_transient( $key . '_stale', $items, CYBERDECK_GALLERY_STALE_TTL );

		return array( 'items' => $items, 'stale' => false );
	}
}

/**
 * Build the GraphQL document. Every interpolated value is validated by the caller
 * (address/contract regex, digits-only token id, whitelisted field names).
 *
 * @param array $q address, source, contracts[], works[], token_id, fetch, direction, fields[].
 */
function cyberdeck_teia_build_query( array $q ) {
	$fields    = $q['fields'];
	$selection = implode( ' ', $fields );

	// Explicit list of works: exact (contract, token id) pairs.
	if ( ! empty( $q['works'] ) ) {
		return sprintf(
			'{ tokens(where: {_or: %s}, limit: %d) { %s } }',
			cyberdeck_gallery_gql_work_pairs( $q['works'], 'fa2_address' ),
			(int) $q['fetch'],
			$selection
		);
	}

	if ( 'collected' === $q['source'] ) {
		$token_filter = $q['contracts'] ? sprintf( ', token: {fa2_address: {_in: %s}}', cyberdeck_gallery_gql_list( $q['contracts'] ) ) : '';
		return sprintf(
			'{ holdings(where: {holder_address: {_eq: %s}, amount: {_gt: "0"}%s}, order_by: {first_received_at: %s}, limit: %d) { token { %s } } }',
			wp_json_encode( $q['address'] ),
			$token_filter,
			$q['direction'],
			(int) $q['fetch'],
			$selection
		);
	}

	$owner_field = in_array( 'artist_address', $fields, true ) ? 'artist_address' : 'minter_address';
	$conditions  = array( sprintf( '%s: {_eq: %s}', $owner_field, wp_json_encode( $q['address'] ) ) );
	if ( $q['contracts'] ) {
		$conditions[] = sprintf( 'fa2_address: {_in: %s}', cyberdeck_gallery_gql_list( $q['contracts'] ) );
	}
	if ( '' !== $q['token_id'] ) {
		$conditions[] = sprintf( 'token_id: {_eq: %s}', wp_json_encode( $q['token_id'] ) );
	}

	$order_field = in_array( 'minted_at', $fields, true ) ? 'minted_at' : 'token_id';

	return sprintf(
		'{ tokens(where: {%s}, order_by: {%s: %s}, limit: %d) { %s } }',
		implode( ', ', $conditions ),
		$order_field,
		$q['direction'],
		(int) $q['fetch'],
		$selection
	);
}

/**
 * Connection check used by the editor's "Check connection" button.
 *
 * @param string $address Optional address to count works for.
 * @return array
 */
function cyberdeck_teia_diagnose( $address = '' ) {
	$endpoints = cyberdeck_teia_endpoints();
	$meta      = cyberdeck_teia_token_fields( true );
	$report    = array(
		'endpoint'       => $endpoints ? $endpoints[0] : '',
		'introspection'  => $meta['introspected'],
		'fields_used'    => $meta['fields'],
		'fields_missing' => array_values( array_diff( cyberdeck_teia_wanted_fields(), $meta['fields'] ) ),
		'ok'             => false,
		'works_found'    => null,
		'message'        => '',
	);

	$ping = cyberdeck_teia_gql( '{ __typename }' );
	if ( is_wp_error( $ping ) ) {
		$report['message'] = $ping->get_error_message();
		return $report;
	}
	$report['ok'] = true;

	if ( cyberdeck_gallery_is_address( $address ) ) {
		$result = cyberdeck_teia_fetch_works(
			array(
				'address' => $address,
				'fetch'   => 5,
				'ttl'     => MINUTE_IN_SECONDS,
			)
		);
		if ( is_wp_error( $result ) ) {
			$report['ok']      = false;
			$report['message'] = $result->get_error_message();
		} else {
			$report['works_found'] = count( $result['items'] );
		}
	}

	if ( '' === $report['message'] ) {
		$report['message'] = $meta['introspected']
			? 'Connected. The indexer exposes the fields the block uses.'
			: 'Connected, but schema introspection is unavailable; using the safe default field set.';
	}
	return $report;
}

/**
 * Provider definition consumed by the shared gallery renderer.
 *
 * @return array
 */
function cyberdeck_teia_provider() {
	return array(
		'slug'       => 'teia',
		'page_label' => 'Teia',
		'sources'    => array( 'created', 'collected' ),
		'multi'      => false,
		'default_contract' => CYBERDECK_TEIA_OBJKT_CONTRACT,
		'native'     => static function () {
			// Hic et Nunc / Teia OBJKTs: the contract Teia mints on.
			return array( CYBERDECK_TEIA_OBJKT_CONTRACT );
		},
		'resolve'    => static function ( $input ) {
			$address = cyberdeck_gallery_resolve_account( $input );
			return is_wp_error( $address ) ? $address : array( $address );
		},
		'fetch'      => static function ( array $a, array $addresses, $fetch ) {
			return cyberdeck_teia_fetch_works(
				array(
					'address'   => $addresses[0],
					'source'    => $a['source'],
					'contracts' => $a['contracts'],
					'works'     => $a['works'],
					'token_id'  => ( 'single' === $a['layout'] ) ? $a['tokenId'] : '',
					'fetch'     => $fetch,
					'order'     => $a['order'],
					'ttl'       => $a['cacheMinutes'] * MINUTE_IN_SECONDS,
				)
			);
		},
		'profile'    => static function ( array $a, array $addresses ) {
			$address = $addresses[0];
			$name    = cyberdeck_gallery_display_name( $address );
			if ( '' === $name ) {
				$name = substr( $address, 0, 5 ) . '…' . substr( $address, -5 );
			}
			return array(
				'name'   => $name,
				'bio'    => '',
				'avatar' => '',
				'url'    => 'https://teia.art/' . $address,
				'label'  => 'collected' === $a['source'] ? 'Collection of' : 'Works by',
			);
		},
		'diagnose'   => 'cyberdeck_teia_diagnose',
	);
}
