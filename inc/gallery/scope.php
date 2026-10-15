<?php
/**
 * Gallery blocks: scoping helpers.
 *
 * Every block can be limited to
 *  - "native" works (minted on the platform's own contract(s)),
 *  - a list of contracts (one, a few), and/or
 *  - an explicit list of works (contract + token id), in the order given.
 *
 * @package Cyberdeck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CYBERDECK_GALLERY_MAX_CONTRACTS = 10;
const CYBERDECK_GALLERY_MAX_WORKS     = 60;

/**
 * Parse a list of KT1… contract addresses (comma, space or newline separated).
 *
 * @param string $text Raw input.
 * @return string[] Unique, valid addresses.
 */
function cyberdeck_gallery_parse_contracts( $text ) {
	$out = array();
	foreach ( preg_split( '/[\s,;]+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY ) as $part ) {
		if ( 0 === strpos( $part, 'KT1' ) && cyberdeck_gallery_is_address( $part ) ) {
			$out[ $part ] = $part;
		}
	}
	return array_slice( array_values( $out ), 0, CYBERDECK_GALLERY_MAX_CONTRACTS );
}

/**
 * Parse a list of works, one per line. Accepted forms:
 *   KT1…:123    KT1…/123    KT1… 123
 *   https://objkt.com/tokens/KT1…/123   (also /asset/)
 *   https://teia.art/objkt/123          (Hic et Nunc / Teia OBJKT)
 *   https://teia.art/KT1…/123
 *   123                                 (needs a default contract)
 *
 * @param string $text            Raw input.
 * @param string $default_contract Contract for bare ids ('' = not allowed).
 * @return array{works:array<int,array{fa2:string,token_id:string}>,invalid:string[]}
 */
function cyberdeck_gallery_parse_works( $text, $default_contract = '' ) {
	$works   = array();
	$invalid = array();
	$kt      = '(KT1[1-9A-HJ-NP-Za-km-z]{33})';

	foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
		$line = trim( $line );
		if ( '' === $line || '#' === $line[0] ) {
			continue;
		}

		$fa2 = '';
		$id  = '';
		if ( preg_match( '#^https?://(?:www\.)?objkt\.com/(?:tokens|asset)/' . $kt . '/(\d{1,12})#i', $line, $m ) ) {
			list( , $fa2, $id ) = $m;
		} elseif ( preg_match( '#^https?://(?:www\.)?teia\.art/objkt/(\d{1,12})#i', $line, $m ) ) {
			$fa2 = CYBERDECK_TEIA_OBJKT_CONTRACT;
			$id  = $m[1];
		} elseif ( preg_match( '#^https?://(?:www\.)?teia\.art/' . $kt . '/(\d{1,12})#i', $line, $m ) ) {
			list( , $fa2, $id ) = $m;
		} elseif ( preg_match( '#^' . $kt . '\s*[:/\s]\s*(\d{1,12})$#', $line, $m ) ) {
			list( , $fa2, $id ) = $m;
		} elseif ( preg_match( '/^\d{1,12}$/', $line ) && cyberdeck_gallery_is_address( $default_contract ) ) {
			$fa2 = $default_contract;
			$id  = $line;
		}

		if ( '' === $fa2 || ! cyberdeck_gallery_is_address( $fa2 ) ) {
			$invalid[] = mb_substr( $line, 0, 60 );
			continue;
		}
		$works[ $fa2 . ':' . $id ] = array(
			'fa2'      => $fa2,
			'token_id' => (string) (int) $id,
		);
	}

	return array(
		'works'   => array_slice( array_values( $works ), 0, CYBERDECK_GALLERY_MAX_WORKS ),
		'invalid' => $invalid,
	);
}

/**
 * GraphQL string list literal: ["a","b"]. Callers pass only validated values.
 *
 * @param string[] $values Values.
 * @return string
 */
function cyberdeck_gallery_gql_list( array $values ) {
	return '[' . implode( ', ', array_map( 'wp_json_encode', array_values( $values ) ) ) . ']';
}

/**
 * GraphQL `_or` list matching exact (contract, token id) pairs.
 *
 * @param array  $works          Works from cyberdeck_gallery_parse_works().
 * @param string $contract_field Column holding the contract (fa2_address / fa_contract).
 * @return string
 */
function cyberdeck_gallery_gql_work_pairs( array $works, $contract_field ) {
	$pairs = array();
	foreach ( $works as $work ) {
		$pairs[] = sprintf(
			'{%s: {_eq: %s}, token_id: {_eq: %s}}',
			$contract_field,
			wp_json_encode( $work['fa2'] ),
			wp_json_encode( $work['token_id'] )
		);
	}
	return '[' . implode( ', ', $pairs ) . ']';
}
