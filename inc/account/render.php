<?php
/**
 * Tezos Account block: markup.
 *
 * @package Cyberdeck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function cyberdeck_account_register_assets() {
	$path = get_theme_file_path( 'assets/account/account.css' );
	wp_register_style( 'cyberdeck-account', get_theme_file_uri( 'assets/account/account.css' ), array(), file_exists( $path ) ? (string) filemtime( $path ) : '0.7.0' );
}
add_action( 'init', 'cyberdeck_account_register_assets', 5 );

/**
 * @param array $a Raw attributes.
 * @return array Clean attributes.
 */
function cyberdeck_account_clean( array $a ) {
	$pick = static function ( $value, array $allowed, $default ) {
		return in_array( $value, $allowed, true ) ? $value : $default;
	};
	$flag = static function ( $key, $default ) use ( $a ) {
		return isset( $a[ $key ] ) ? (bool) $a[ $key ] : $default;
	};

	return array(
		'address'      => isset( $a['address'] ) ? sanitize_text_field( $a['address'] ) : '',
		'layout'       => $pick( $a['layout'] ?? '', array( 'card', 'stats', 'line' ), 'card' ),
		'statsSource'  => $pick( $a['statsSource'] ?? '', array( 'tzkt', 'tzstats', 'none' ), 'tzkt' ),
		'showName'     => $flag( 'showName', true ),
		'showBalance'  => $flag( 'showBalance', true ),
		'showActivity' => $flag( 'showActivity', true ),
		'showDelegate' => $flag( 'showDelegate', false ),
		'showTokens'   => $flag( 'showTokens', true ),
		'showDomains'  => $flag( 'showDomains', true ),
		'domainsLimit' => max( 1, min( 30, (int) ( $a['domainsLimit'] ?? 12 ) ) ),
		'showLinks'    => $flag( 'showLinks', true ),
		'cacheMinutes' => max( 1, min( 1440, (int) ( $a['cacheMinutes'] ?? 15 ) ) ),
	);
}

function cyberdeck_account_short( $address ) {
	return strlen( $address ) > 12 ? substr( $address, 0, 6 ) . '…' . substr( $address, -4 ) : $address;
}

function cyberdeck_account_date( $iso ) {
	return $iso && preg_match( '/^\d{4}-\d{2}-\d{2}/', $iso ) ? substr( $iso, 0, 10 ) : '';
}

/**
 * Editor-only note (visitors never see source failures).
 */
function cyberdeck_account_note( $text ) {
	return current_user_can( 'edit_posts' ) ? '<p class="cd-acct__note">' . esc_html( $text ) . '</p>' : '';
}

/**
 * @param array $raw_attributes Block attributes.
 * @return string
 */
function cyberdeck_account_render( array $raw_attributes ) {
	$a = cyberdeck_account_clean( $raw_attributes );

	$address = cyberdeck_gallery_resolve_account( $a['address'] );
	if ( is_wp_error( $address ) ) {
		return current_user_can( 'edit_posts' ) ? '<div ' . get_block_wrapper_attributes( array( 'class' => 'cd-acct' ) ) . '>' . cyberdeck_account_note( 'Tezos Account block: ' . $address->get_error_message() ) . '</div>' : '';
	}

	$ttl   = $a['cacheMinutes'] * MINUTE_IN_SECONDS;
	$notes = '';

	// Chain stats.
	$stats = null;
	if ( 'none' !== $a['statsSource'] && ( $a['showBalance'] || $a['showActivity'] || $a['showDelegate'] || $a['showTokens'] ) ) {
		$stats = 'tzstats' === $a['statsSource'] ? cyberdeck_account_tzstats( $address, $ttl ) : cyberdeck_account_tzkt( $address, $ttl );
		if ( is_wp_error( $stats ) ) {
			$notes .= cyberdeck_account_note( ( 'tzstats' === $a['statsSource'] ? 'TzStats' : 'TzKT' ) . ' is unavailable: ' . $stats->get_error_message() );
			$stats  = null;
		}
	}

	// Names.
	$names = null;
	if ( $a['showName'] || $a['showDomains'] ) {
		$names = cyberdeck_account_domains( $address, $a['domainsLimit'], $ttl );
		if ( $names['error'] ) {
			$notes .= cyberdeck_account_note( 'Tezos Domains: could not list owned names (' . $names['error'] . ').' );
		}
	}

	$primary = $names['primary'] ?? '';
	if ( '' === $primary && $stats && ! empty( $stats['alias'] ) ) {
		$primary = $stats['alias'];
	}

	// Tiles.
	$tiles = array();
	if ( $stats && 'empty' === $stats['type'] ) {
		$tiles[] = array( 'Status', 'No activity yet' );
	} elseif ( $stats ) {
		if ( $a['showBalance'] && '' !== $stats['balance'] ) {
			$tiles[] = array( 'Balance', '0' === $stats['balance'] ? '0 ꜩ' : cyberdeck_gallery_format_tez( $stats['balance'] ) );
		}
		if ( $a['showActivity'] ) {
			if ( null !== $stats['tx'] ) {
				$tiles[] = array( 'Transactions', number_format( (int) $stats['tx'] ) );
			}
			if ( cyberdeck_account_date( $stats['first'] ) ) {
				$tiles[] = array( 'First seen', cyberdeck_account_date( $stats['first'] ) );
			}
			if ( cyberdeck_account_date( $stats['last'] ) ) {
				$tiles[] = array( 'Last active', cyberdeck_account_date( $stats['last'] ) );
			}
		}
		if ( $a['showTokens'] && null !== $stats['tokens'] ) {
			$tiles[] = array( 'Tokens held', number_format( (int) $stats['tokens'] ) );
		}
		if ( $a['showDelegate'] && '' !== $stats['delegate'] ) {
			$tiles[] = array( 'Delegate', '' !== $stats['delegate_name'] ? $stats['delegate_name'] : cyberdeck_account_short( $stats['delegate'] ) );
		}
	}
	if ( $a['showDomains'] && $names && ( $names['total'] > 0 || $names['owned'] ) ) {
		$tiles[] = array( 'Domains owned', number_format( max( $names['total'], count( $names['owned'] ) + ( '' !== $primary ? 1 : 0 ) ) ) );
	}

	$classes = 'cd-acct is-layout-' . $a['layout'];
	$wrapper = get_block_wrapper_attributes( array( 'class' => $classes ) );

	// One-line version.
	if ( 'line' === $a['layout'] ) {
		$bits = array();
		$bits[] = '<span class="cd-acct__id">' . esc_html( '' !== $primary ? $primary : cyberdeck_account_short( $address ) ) . '</span>';
		foreach ( $tiles as $tile ) {
			$bits[] = '<span class="cd-acct__bit"><span class="cd-acct__k">' . esc_html( $tile[0] ) . '</span> ' . esc_html( $tile[1] ) . '</span>';
		}
		return '<p ' . $wrapper . '>' . implode( '', $bits ) . '</p>';
	}

	$html = '<div ' . $wrapper . '>';

	if ( 'card' === $a['layout'] ) {
		$title = '' !== $primary && $a['showName'] ? $primary : cyberdeck_account_short( $address );
		$html .= '<header class="cd-acct__head">';
		$html .= '<h3 class="cd-acct__name">' . esc_html( $title ) . '</h3>';
		$html .= '<code class="cd-acct__addr" title="' . esc_attr( $address ) . '">' . esc_html( cyberdeck_account_short( $address ) ) . '</code>';
		$html .= '</header>';
	}

	if ( $tiles ) {
		$html .= '<dl class="cd-acct__stats">';
		foreach ( $tiles as $tile ) {
			$html .= '<div class="cd-acct__stat"><dt>' . esc_html( $tile[0] ) . '</dt><dd>' . esc_html( $tile[1] ) . '</dd></div>';
		}
		$html .= '</dl>';
	}

	if ( $a['showDomains'] && $names && $names['owned'] ) {
		$html .= '<div class="cd-acct__domains"><p class="cd-acct__k">Tezos Domains</p><ul>';
		foreach ( $names['owned'] as $name ) {
			$html .= '<li>' . esc_html( $name ) . '</li>';
		}
		if ( $names['total'] > count( $names['owned'] ) + ( '' !== $primary ? 1 : 0 ) ) {
			$html .= '<li class="cd-acct__more">+' . esc_html( (string) ( $names['total'] - count( $names['owned'] ) - ( '' !== $primary ? 1 : 0 ) ) ) . ' more</li>';
		}
		$html .= '</ul></div>';
	}

	if ( $a['showLinks'] ) {
		$html .= '<p class="cd-acct__links">';
		$html .= '<a href="' . esc_url( 'https://tzkt.io/' . $address ) . '" target="_blank" rel="noopener noreferrer">TzKT</a>';
		$html .= '<a href="' . esc_url( 'https://tzstats.com/' . $address ) . '" target="_blank" rel="noopener noreferrer">TzStats</a>';
		$html .= '<a href="' . esc_url( 'https://objkt.com/profile/' . $address ) . '" target="_blank" rel="noopener noreferrer">objkt</a>';
		$html .= '</p>';
	}

	$html .= $notes . '</div>';
	return $html;
}
