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
 * Mederu's native contracts.
 *
 * Mederu mints through a "Studio" FA2 contract, and every collection an artist creates in Mederu deploys its own FA2
 * contract. There is no single address, so:
 *  1. contracts listed under Settings > Tezos blocks win (add your own collections there), otherwise
 *  2. the Studio contract is detected on objkt by its collection name ("Studio Mederu"; filter
 *     `cyberdeck_mederu_name_patterns`).
 *
 * @return array{contracts:string[],source:string,detected:array}
 */
function cyberdeck_mederu_contracts_info() {
	$list = (string) cyberdeck_tezos_option( 'mederu_contracts', '' ) . ' ' . (string) cyberdeck_tezos_option( 'mederu_studio_contract', '' );
	$set  = cyberdeck_gallery_parse_contracts( $list );
	if ( $set ) {
		return array( 'contracts' => $set, 'source' => 'settings', 'detected' => array() );
	}

	$patterns = apply_filters( 'cyberdeck_mederu_name_patterns', array( 'studio mederu' ) );
	$found    = array_slice( cyberdeck_objkt_find_collections( (array) $patterns ), 0, 5 );
	return array(
		'contracts' => wp_list_pluck( $found, 'contract' ),
		'source'    => $found ? 'detected' : 'none',
		'detected'  => $found,
	);
}

/**
 * @return string[]
 */
function cyberdeck_mederu_contracts() {
	return cyberdeck_mederu_contracts_info()['contracts'];
}

/**
 * Link-out button for Mederu's own sales mechanisms that cannot run on another site:
 * free-mint pages (gas paid by Mederu) and gacha drops (random mint). Only mederu.art hosts are accepted.
 *
 * @param array $a Clean attributes.
 * @return string
 */
function cyberdeck_mederu_drop_button( array $a ) {
	$url = esc_url_raw( (string) ( $a['dropUrl'] ?? '' ), array( 'https' ) );
	if ( '' === $url ) {
		return '';
	}
	$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
	if ( ! in_array( $host, array( 'mederu.art', 'www.mederu.art', 'gacha.mederu.art' ), true ) ) {
		return '';
	}
	$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
	$label = 'Open on mederu';
	if ( 'gacha.mederu.art' === $host ) {
		$label = 'Mint from the gacha';
	} elseif ( 0 === strpos( $path, '/free-mint/' ) ) {
		$label = 'Claim the free mint';
	}
	if ( '' !== (string) ( $a['dropLabel'] ?? '' ) ) {
		$label = (string) $a['dropLabel'];
	}
	return '<p class="gx-drop"><a class="gx-drop__link" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $label ) . '</a></p>';
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
		'native_required' => 'Could not identify Mederu\'s contracts automatically. Add them under Settings > Tezos blocks (or in the block, "Only these contracts"), or choose "Any contract".',
		'extra'      => 'cyberdeck_mederu_drop_button',
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
			$info             = cyberdeck_mederu_contracts_info();
			$report['native'] = $info;
			$report['message'] .= $info['contracts']
				? ' Native to Mederu = ' . count( $info['contracts'] ) . ' contract(s) (' . $info['source'] . ').'
				: ' No Mederu contracts could be identified; list them in Settings.';
			return $report;
		},
	);
}
