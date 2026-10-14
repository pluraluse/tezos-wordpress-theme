<?php
/**
 * Mederu provider.
 *
 * mederu.art has no documented public API. Its works are ordinary FA2 tokens that also live on objkt
 * (Studio mints, gacha drops, and everything its feed aggregates), and Mederu's own docs say purchases
 * go through objkt. So data and collecting both reuse the objkt layer; what is Mederu-specific is the
 * profile link (mederu.art/user/<address>), the "minted on Mederu" filter, and material credits.
 *
 * @package Cyberdeck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mederu's own FA2 contract(s): Studio mints, and any gacha / free-mint contracts the admin adds
 * (Settings > Tezos blocks). The older single "Studio contract" setting still works.
 *
 * @return string[]
 */
function cyberdeck_mederu_contracts() {
	$list = (string) cyberdeck_tezos_option( 'mederu_contracts', '' ) . ' ' . (string) cyberdeck_tezos_option( 'mederu_studio_contract', '' );
	return cyberdeck_gallery_parse_contracts( $list );
}

/**
 * @return array
 */
function cyberdeck_mederu_provider() {
	return array(
		'slug'       => 'mederu',
		'page_label' => 'objkt',
		'sources'    => array( 'created', 'studio', 'collected' ),
		'multi'      => true,
		'default_contract' => '',
		'native'     => 'cyberdeck_mederu_contracts',
		'native_required' => 'Mederu\'s own contracts are not set. Add them under Settings > Tezos blocks, or choose "Any contract" in the block.',
		'resolve'    => 'cyberdeck_objkt_resolve_accounts',
		'fetch'      => static function ( array $a, array $addresses, $fetch ) {
			return cyberdeck_objkt_fetch_works(
				array(
					'addresses' => $addresses,
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
			if ( count( $addresses ) > 1 ) {
				return array(
					'name'   => sprintf( '%d artists', count( $addresses ) ),
					'bio'    => '',
					'avatar' => '',
					'url'    => 'https://mederu.art/',
					'label'  => 'collected' === $a['source'] ? 'Collections on' : 'Works on',
				);
			}
			$address = $addresses[0];
			$holder  = cyberdeck_objkt_holder_profile( $address );
			$name    = '' !== $holder['name'] ? $holder['name'] : substr( $address, 0, 5 ) . '…' . substr( $address, -5 );
			return array(
				'name'   => $name,
				'bio'    => $holder['bio'],
				'avatar' => $holder['avatar'],
				'url'    => 'https://mederu.art/user/' . rawurlencode( $address ),
				'label'  => 'collected' === $a['source'] ? 'Collection of' : 'On mederu',
			);
		},
		'diagnose'   => static function ( $address = '' ) {
			$report = cyberdeck_objkt_diagnose( $address );
			if ( ! cyberdeck_mederu_contracts() ) {
				$report['message'] .= ' Mederu contracts are not set, so "Native to Mederu" has nothing to match.';
			}
			return $report;
		},
	);
}
