<?php
/**
 * objkt data layer (shared by the objkt and Mederu blocks).
 *
 * Reads tokens from objkt's public GraphQL API (v3). Mederu's works are ordinary FA2 tokens that also
 * live on objkt, so the Mederu block uses this too (see inc/mederu/provider.php).
 *
 * Like the Teia client, it detects which token fields the API exposes, caches results, and serves the
 * last good copy when the API is unreachable.
 *
 * @package Cyberdeck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CYBERDECK_OBJKT_DEFAULT_ENDPOINT = 'https://data.objkt.com/v3/graphql';
const CYBERDECK_OBJKT_MAX_ADDRESSES    = 20;

/**
 * Configured API endpoints.
 *
 * @return string[]
 */
function cyberdeck_objkt_endpoints() {
	$list = array_filter( array( (string) cyberdeck_tezos_option( 'objkt_endpoint', CYBERDECK_OBJKT_DEFAULT_ENDPOINT ) ) );
	$list = apply_filters( 'cyberdeck_objkt_endpoints', $list );

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
 * GraphQL against the configured endpoint(s). Reuses the Teia transport (same wire protocol).
 *
 * @param string $query GraphQL document.
 * @return array|WP_Error
 */
function cyberdeck_objkt_gql( $query ) {
	$last = new WP_Error( 'objkt_no_endpoint', 'No valid https API endpoint is configured.' );
	foreach ( cyberdeck_objkt_endpoints() as $endpoint ) {
		$result = cyberdeck_gallery_http_graphql( $endpoint, $query );
		if ( ! is_wp_error( $result ) ) {
			return $result;
		}
		$last = $result;
		if ( 'gallery_graphql' === $result->get_error_code() ) {
			break;
		}
	}
	return $last;
}

/**
 * Public objkt page for a token (Mederu has no stable per-token URL we can rely on).
 */
function cyberdeck_objkt_token_url( $contract, $token_id ) {
	return 'https://objkt.com/tokens/' . rawurlencode( $contract ) . '/' . rawurlencode( $token_id );
}

/**
 * Accept one or several addresses (comma, space or newline separated), .tez names, or
 * mederu.art/user/<address> and teia.art/<address> links.
 *
 * @param string $input Raw editor input.
 * @return string[]|WP_Error
 */
function cyberdeck_objkt_resolve_accounts( $input ) {
	$parts = preg_split( '/[\s,;]+/', trim( (string) $input ), -1, PREG_SPLIT_NO_EMPTY );
	if ( ! $parts ) {
		return new WP_Error( 'objkt_no_address', 'Enter a Tezos address, a .tez name, or a mederu.art/user/… or teia.art/… link. Several can be separated by commas or new lines.' );
	}
	if ( count( $parts ) > CYBERDECK_OBJKT_MAX_ADDRESSES ) {
		return new WP_Error( 'objkt_too_many', sprintf( 'Up to %d accounts are supported.', CYBERDECK_OBJKT_MAX_ADDRESSES ) );
	}

	$addresses = array();
	foreach ( $parts as $part ) {
		if ( preg_match( '#^https?://(?:www\.)?mederu\.art/user/([^/?\#]+)#i', $part, $m ) ) {
			$part = rawurldecode( $m[1] );
		}
		$address = cyberdeck_gallery_resolve_account( $part ); // address, .tez name or teia.art link
		if ( is_wp_error( $address ) ) {
			return new WP_Error( 'objkt_unresolved', sprintf( 'Could not use "%s": %s', $part, $address->get_error_message() ) );
		}
		$addresses[ $address ] = $address;
	}
	return array_values( $addresses );
}

function cyberdeck_objkt_wanted_fields() {
	return array(
		'fa_contract',
		'token_id',
		'name',
		'description',
		'mime',
		'artifact_uri',
		'display_uri',
		'thumbnail_uri',
		'timestamp',
		'supply',
		'flag',
		'lowest_ask',
	);
}

function cyberdeck_objkt_minimal_fields() {
	return array( 'fa_contract', 'token_id', 'name', 'mime', 'artifact_uri', 'display_uri', 'thumbnail_uri' );
}

/**
 * Which token fields does the API expose? Cached.
 *
 * @param bool $force Skip the cache.
 * @return array{fields:string[],all:string[],introspected:bool}
 */
function cyberdeck_objkt_token_fields( $force = false ) {
	$cached = $force ? false : get_transient( 'cd_objkt_fields' );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$result = array(
		'fields'       => cyberdeck_objkt_minimal_fields(),
		'all'          => array(),
		'introspected' => false,
	);

	$data = cyberdeck_objkt_gql( '{ __type(name: "token") { fields { name } } }' );
	if ( ! is_wp_error( $data ) && ! empty( $data['__type']['fields'] ) ) {
		$all     = wp_list_pluck( $data['__type']['fields'], 'name' );
		$present = array_values( array_intersect( cyberdeck_objkt_wanted_fields(), $all ) );
		if ( in_array( 'fa_contract', $present, true ) && in_array( 'token_id', $present, true ) ) {
			$result = array(
				'fields'       => $present,
				'all'          => $all,
				'introspected' => true,
			);
		}
	}

	set_transient( 'cd_objkt_fields', $result, $result['introspected'] ? 12 * HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS );
	return $result;
}

/**
 * Field selection, including creator list and (optionally) trait attributes for material credits.
 */
function cyberdeck_objkt_selection( array $fields, $with_attributes ) {
	$selection = implode( ' ', $fields ) . ' creators { creator_address }';
	if ( $with_attributes ) {
		$selection .= ' attributes { attribute { name value } }';
	}
	return $selection;
}

/**
 * Build the GraphQL document. Every interpolated value is validated by the caller.
 *
 * @param string[] $addresses Validated addresses.
 * @param string   $source    created | collected | collection.
 * @param string[] $contracts Optional contract filter (validated).
 * @param string   $token_id  Digits or empty.
 * @param int      $fetch     Row limit.
 * @param string   $direction asc | desc.
 * @param string   $selection Token selection set.
 * @param int      $variant   Collected-order fallback variant (0..1).
 * @param array    $works     Optional explicit works (overrides everything else).
 */
function cyberdeck_objkt_build_query( array $addresses, $source, array $contracts, $token_id, $fetch, $direction, $selection, $variant = 0, array $works = array() ) {
	$list = '[' . implode( ', ', array_map( 'wp_json_encode', $addresses ) ) . ']';

	// Explicit list of works: exact (contract, token id) pairs, whatever else is set.
	if ( $works ) {
		return sprintf(
			'{ token(where: {_or: %s}, limit: %d) { %s } }',
			cyberdeck_gallery_gql_work_pairs( $works, 'fa_contract' ),
			(int) $fetch,
			$selection
		);
	}

	$token_conditions = array( 'supply: {_gt: "0"}' );
	if ( $contracts ) {
		$token_conditions[] = sprintf( 'fa_contract: {_in: %s}', cyberdeck_gallery_gql_list( $contracts ) );
	}
	if ( '' !== $token_id ) {
		$token_conditions[] = sprintf( 'token_id: {_eq: %s}', wp_json_encode( $token_id ) );
	}

	if ( 'collected' === $source ) {
		$order = 0 === $variant
			? sprintf( 'order_by: {last_incremented_at: %s}', $direction )
			: sprintf( 'order_by: {token: {timestamp: %s}}', $direction );

		return sprintf(
			'{ token_holder(where: {holder_address: {_in: %s}, quantity: {_gt: "0"}, token: {%s}}, %s, limit: %d) { token { %s } } }',
			$list,
			implode( ', ', $token_conditions ),
			$order,
			$fetch,
			$selection
		);
	}

	if ( 'collection' !== $source ) {
		array_unshift( $token_conditions, sprintf( 'creators: {creator_address: {_in: %s}}', $list ) );
	}

	return sprintf(
		'{ token(where: {%s}, order_by: {timestamp: %s}, limit: %d) { %s } }',
		implode( ', ', $token_conditions ),
		$direction,
		$fetch,
		$selection
	);
}

/**
 * Pull "Material Artist" style credits out of a token's attributes.
 *
 * @param mixed $attributes Decoded attributes relation.
 * @return string Comma separated names, or empty.
 */
function cyberdeck_objkt_credits( $attributes ) {
	$names = array();
	foreach ( (array) $attributes as $row ) {
		$attr = is_array( $row ) && isset( $row['attribute'] ) ? $row['attribute'] : array();
		$name = strtolower( (string) ( $attr['name'] ?? '' ) );
		if ( 'material artist' === $name || 'material artists' === $name ) {
			$value = trim( (string) ( $attr['value'] ?? '' ) );
			if ( '' !== $value ) {
				$names[ $value ] = $value;
			}
		}
	}
	return implode( ', ', array_values( $names ) );
}

/**
 * Normalize one objkt token row.
 *
 * @param array $row Token row.
 * @return array|null
 */
function cyberdeck_objkt_normalize_token( array $row ) {
	if ( empty( $row['fa_contract'] ) || ! isset( $row['token_id'] ) ) {
		return null;
	}

	return array(
		'fa2'         => (string) $row['fa_contract'],
		'token_id'    => (string) $row['token_id'],
		'title'       => (string) ( $row['name'] ?? '' ),
		'description' => (string) ( $row['description'] ?? '' ),
		'artist'      => (string) ( $row['creators'][0]['creator_address'] ?? '' ),
		'mime'        => (string) ( $row['mime'] ?? '' ),
		'artifact'    => (string) ( $row['artifact_uri'] ?? '' ),
		'display'     => (string) ( $row['display_uri'] ?? '' ),
		'thumbnail'   => (string) ( $row['thumbnail_uri'] ?? '' ),
		'minted_at'   => (string) ( $row['timestamp'] ?? '' ),
		'editions'    => isset( $row['supply'] ) ? (string) $row['supply'] : '',
		'url'         => cyberdeck_objkt_token_url( (string) $row['fa_contract'], (string) $row['token_id'] ),
		'credit'      => cyberdeck_objkt_credits( $row['attributes'] ?? array() ),
		'flag'        => isset( $row['flag'] ) ? (string) $row['flag'] : '',
		'price'       => cyberdeck_gallery_format_tez( $row['lowest_ask'] ?? null ),
	);
}

/**
 * Fetch works.
 *
 * @param array $args {
 *     @type string[] $addresses Validated addresses.
 *     @type string   $source    created | collected | collection.
 *     @type string[] $contracts Optional FA2 contracts to restrict to.
 *     @type array    $works     Optional explicit works [ ['fa2'=>..,'token_id'=>..], ... ].
 *     @type string   $token_id  Optional single token id.
 *     @type int      $fetch     Rows to request.
 *     @type string   $order     newest | oldest | random.
 *     @type int      $ttl       Cache seconds.
 *     @type bool     $credits   Also request trait attributes.
 * }
 * @return array{items:array,stale:bool}|WP_Error
 */
function cyberdeck_objkt_fetch_works( array $args ) {
	$works     = array_values( (array) ( $args['works'] ?? array() ) );
	$addresses = array_values( array_filter( (array) ( $args['addresses'] ?? array() ), 'cyberdeck_gallery_is_address' ) );
	$contracts = cyberdeck_gallery_parse_contracts( implode( ' ', (array) ( $args['contracts'] ?? array() ) ) );
	$source    = in_array( $args['source'] ?? '', array( 'collected', 'collection' ), true ) ? $args['source'] : 'created';

	if ( ! $works ) {
		if ( 'collection' === $source && ! $contracts ) {
			return new WP_Error( 'objkt_no_collection', 'Enter the collection\'s KT1… contract address.' );
		}
		if ( 'collection' !== $source && ! $addresses ) {
			return new WP_Error( 'objkt_bad_address', 'No valid Tezos address given.' );
		}
	}
	$token_id  = preg_match( '/^\d{1,12}$/', (string) ( $args['token_id'] ?? '' ) ) ? (string) $args['token_id'] : '';
	$fetch     = max( 1, min( 200, (int) ( $args['fetch'] ?? 36 ) ) );
	$direction = 'oldest' === ( $args['order'] ?? '' ) ? 'asc' : 'desc';
	$ttl       = max( MINUTE_IN_SECONDS, (int) ( $args['ttl'] ?? 30 * MINUTE_IN_SECONDS ) );

	$fields          = cyberdeck_objkt_token_fields()['fields'];
	$with_attributes = ! empty( $args['credits'] );
	$variant         = 0;
	$degraded        = false;

	while ( true ) {
		$query = cyberdeck_objkt_build_query( $addresses, $source, $contracts, $token_id, $works ? max( $fetch, count( $works ) ) : $fetch, $direction, cyberdeck_objkt_selection( $fields, $with_attributes ), $variant, $works );
		$key   = 'cd_objkt_w_' . md5( $query );

		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return array( 'items' => $cached, 'stale' => false );
		}

		$data = cyberdeck_objkt_gql( $query );

		if ( is_wp_error( $data ) ) {
			$retryable = 'gallery_graphql' === $data->get_error_code() && false !== stripos( $data->get_error_message(), 'not found' );
			if ( $retryable && ! $works && 'collected' === $source && 0 === $variant ) {
				$variant = 1; // different ordering column for holdings.
				continue;
			}
			if ( $retryable && $with_attributes ) {
				$with_attributes = false; // credits are a nice-to-have.
				continue;
			}
			if ( $retryable && ! $degraded ) {
				$degraded = true;
				$fields   = cyberdeck_objkt_minimal_fields();
				delete_transient( 'cd_objkt_fields' );
				continue;
			}

			$stale = get_transient( $key . '_stale' );
			if ( is_array( $stale ) ) {
				return array( 'items' => $stale, 'stale' => true );
			}
			return $data;
		}

		$collected = ( ! $works && 'collected' === $source );
		$rows      = $collected ? ( $data['token_holder'] ?? array() ) : ( $data['token'] ?? array() );
		$items = array();
		foreach ( (array) $rows as $row ) {
			if ( $collected ) {
				$row = $row['token'] ?? null;
			}
			$item = is_array( $row ) ? cyberdeck_objkt_normalize_token( $row ) : null;
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
 * Profile info for one address from objkt's holder table. Cached; never throws.
 *
 * @param string $address Tezos address.
 * @return array{name:string,bio:string,avatar:string}
 */
function cyberdeck_objkt_holder_profile( $address ) {
	$empty = array( 'name' => '', 'bio' => '', 'avatar' => '' );
	if ( ! cyberdeck_gallery_is_address( $address ) ) {
		return $empty;
	}

	$key    = 'cd_objkt_h_' . md5( $address );
	$cached = get_transient( $key );
	if ( is_array( $cached ) ) {
		return $cached;
	}

	$data = cyberdeck_gallery_http_graphql(
		(string) ( cyberdeck_objkt_endpoints()[0] ?? '' ),
		sprintf( '{ holder(where: {address: {_eq: %s}}) { alias tzdomain description logo } }', wp_json_encode( $address ) ),
		5
	);
	$row  = is_wp_error( $data ) ? array() : (array) ( $data['holder'][0] ?? array() );

	$profile = array(
		'name'   => (string) ( $row['alias'] ?? '' ) !== '' ? (string) $row['alias'] : (string) ( $row['tzdomain'] ?? '' ),
		'bio'    => (string) ( $row['description'] ?? '' ),
		'avatar' => (string) ( $row['logo'] ?? '' ),
	);

	set_transient( $key, $profile, array_filter( $profile ) ? 12 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
	return $profile;
}

/**
 * Connection check for the editor's button.
 *
 * @param string $address Optional address to count works for.
 * @return array
 */
function cyberdeck_objkt_diagnose( $address = '' ) {
	$endpoints = cyberdeck_objkt_endpoints();
	$meta      = cyberdeck_objkt_token_fields( true );
	$report    = array(
		'endpoint'       => $endpoints ? $endpoints[0] : '',
		'introspection'  => $meta['introspected'],
		'fields_used'    => $meta['fields'],
		'fields_missing' => array_values( array_diff( cyberdeck_objkt_wanted_fields(), $meta['fields'] ) ),
		'ok'             => false,
		'works_found'    => null,
		'message'        => '',
	);

	$ping = cyberdeck_objkt_gql( '{ __typename }' );
	if ( is_wp_error( $ping ) ) {
		$report['message'] = $ping->get_error_message();
		return $report;
	}
	$report['ok'] = true;

	if ( cyberdeck_gallery_is_address( $address ) ) {
		$result = cyberdeck_objkt_fetch_works(
			array(
				'addresses' => array( $address ),
				'fetch'     => 5,
				'ttl'       => MINUTE_IN_SECONDS,
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
			? 'Connected. The API exposes the fields the block uses.'
			: 'Connected, but schema introspection is unavailable; using the safe default field set.';
	}
	return $report;
}

/**
 * Provider definition for the objkt block.
 *
 * @return array
 */
function cyberdeck_objkt_provider() {
	return array(
		'slug'       => 'objkt',
		'page_label' => 'objkt',
		'sources'    => array( 'created', 'collected', 'collection' ),
		'multi'      => true,
		'default_contract' => '',
		'native'     => static function () {
			// objkt is an open marketplace (every Tezos collection), so there is no built-in native list;
			// admins may name collections created through objkt under Settings > Tezos blocks.
			return cyberdeck_gallery_parse_contracts( (string) cyberdeck_tezos_option( 'objkt_native_contracts', '' ) );
		},
		'resolve'    => static function ( $input ) {
			// A pure collection view only needs the contract, set in the block's own field.
			$input = trim( (string) $input );
			if ( '' === $input ) {
				return array( '' );
			}
			return cyberdeck_objkt_resolve_accounts( $input );
		},
		'fetch'      => static function ( array $a, array $addresses, $fetch ) {
			return cyberdeck_objkt_fetch_works(
				array(
					'addresses' => array_filter( $addresses ),
					'source'    => $a['source'],
					'contracts' => $a['contracts'],
					'works'     => $a['works'],
					'token_id'  => ( 'single' === $a['layout'] ) ? $a['tokenId'] : '',
					'fetch'     => $fetch,
					'order'     => $a['order'],
					'ttl'       => $a['cacheMinutes'] * MINUTE_IN_SECONDS,
					'credits'   => $a['showCredits'],
				)
			);
		},
		'profile'    => static function ( array $a, array $addresses ) {
			$addresses = array_values( array_filter( $addresses ) );
			$first     = $a['contracts'][0] ?? '';
			if ( 'collection' === $a['source'] || ! $addresses ) {
				return array(
					'name'   => $first ? substr( $first, 0, 5 ) . '…' . substr( $first, -5 ) : 'Collection',
					'bio'    => '',
					'avatar' => '',
					'url'    => ( $first && 1 === count( $a['contracts'] ) ) ? 'https://objkt.com/collections/' . rawurlencode( $first ) : '',
					'label'  => 'Collection',
				);
			}
			if ( count( $addresses ) > 1 ) {
				return array(
					'name'   => sprintf( '%d accounts', count( $addresses ) ),
					'bio'    => '',
					'avatar' => '',
					'url'    => '',
					'label'  => 'collected' === $a['source'] ? 'Collections of' : 'Works by',
				);
			}
			$address = $addresses[0];
			$holder  = cyberdeck_objkt_holder_profile( $address );
			$name    = '' !== $holder['name'] ? $holder['name'] : substr( $address, 0, 5 ) . '…' . substr( $address, -5 );
			return array(
				'name'   => $name,
				'bio'    => $holder['bio'],
				'avatar' => $holder['avatar'],
				'url'    => 'https://objkt.com/profile/' . rawurlencode( $address ),
				'label'  => 'collected' === $a['source'] ? 'Collection of' : 'Works by',
			);
		},
		'diagnose'   => 'cyberdeck_objkt_diagnose',
	);
}
