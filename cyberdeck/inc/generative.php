<?php
/**
 * Generative Canvas block (server side).
 *
 * @package Cyberdeck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Built-in sketches. Custom sketches registered in JS (cyberdeckGenerative.register) work too:
 * the block accepts any slug of [a-z0-9-], so nothing needs to be listed here for them to run.
 *
 * @return array<string,string> slug => label
 */
function cyberdeck_generative_sketches() {
	return apply_filters(
		'cyberdeck_generative_sketches',
		array(
			'flow'          => 'Flow field',
			'constellation' => 'Constellation',
			'truchet'       => 'Truchet tiles',
			'waves'         => 'Wave lines',
			'glitch'        => 'Glitch grid',
		)
	);
}

function cyberdeck_generative_register_assets() {
	$ver = static function ( $rel ) {
		$path = get_theme_file_path( $rel );
		return file_exists( $path ) ? (string) filemtime( $path ) : '0.5.0';
	};

	wp_register_style( 'cyberdeck-generative', get_theme_file_uri( 'assets/generative/generative.css' ), array(), $ver( 'assets/generative/generative.css' ) );
	wp_register_style( 'cyberdeck-generative-editor', get_theme_file_uri( 'assets/generative/generative-editor.css' ), array( 'cyberdeck-generative' ), $ver( 'assets/generative/generative-editor.css' ) );
	wp_register_script(
		'cyberdeck-generative-view',
		get_theme_file_uri( 'assets/generative/generative.js' ),
		array(),
		$ver( 'assets/generative/generative.js' ),
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
}
add_action( 'init', 'cyberdeck_generative_register_assets', 5 );

/**
 * The editor canvas is an iframe; load the engine into it so the preview animates.
 */
function cyberdeck_generative_editor_assets() {
	if ( is_admin() ) {
		wp_enqueue_script( 'cyberdeck-generative-view' );
	}
}
add_action( 'enqueue_block_assets', 'cyberdeck_generative_editor_assets' );

/**
 * Pick one of the artist's existing generative works (interactive HTML tokens) to show live.
 *
 * Reuses the gallery framework, so the same platform scoping applies: Teia -> Teia's own contract,
 * Mederu -> the contracts listed in Settings, objkt -> any contract. A single work (URL or KT1…:id)
 * skips the account lookup.
 *
 * @param array $attributes Block attributes.
 * @return array|WP_Error {item, platform}
 */
function cyberdeck_generative_resolve_minted( array $attributes ) {
	$from      = $attributes['mintFrom'] ?? 'teia';
	$from      = in_array( $from, array( 'teia', 'objkt', 'mederu' ), true ) ? $from : 'teia';
	$providers = cyberdeck_gallery_providers();
	if ( empty( $providers[ $from ] ) ) {
		return new WP_Error( 'gen_no_provider', 'Unknown platform.' );
	}
	$provider = $providers[ $from ];

	$a = cyberdeck_gallery_clean_attributes(
		array(
			'address'  => (string) ( $attributes['mintAddress'] ?? '' ),
			'works'    => (string) ( $attributes['mintWork'] ?? '' ),
			'scope'    => ( $attributes['mintScope'] ?? 'native' ),
			'types'    => array( 'interactive' ),
			'limit'    => 24,
			'order'    => 'newest',
			'layout'   => 'grid',
			'gateway'  => 'teia',
			'source'   => 'created',
		),
		$provider
	);

	if ( '' === $a['address'] && ! $a['works'] ) {
		return new WP_Error( 'gen_no_source', 'Enter a minted work or an artist address.' );
	}

	$loaded = cyberdeck_gallery_load_items( $a, $provider );
	if ( is_wp_error( $loaded ) ) {
		return $loaded;
	}

	$items = array_values(
		array_filter(
			$loaded['items'],
			static function ( $item ) {
				return '' !== $item['src'];
			}
		)
	);
	if ( ! $items ) {
		return new WP_Error( 'gen_none', $a['works'] ? 'That work is not an interactive (generative) token, so it cannot run live.' : 'No generative (interactive) works found for that source.' );
	}

	$index = 0;
	if ( ! $a['works'] && 'newest' !== ( $attributes['mintPick'] ?? 'seeded' ) ) {
		$index = (int) ( crc32( (string) ( $attributes['seed'] ?? 'cyberdeck' ) ) % count( $items ) );
	}

	return array(
		'item'     => $items[ $index ],
		'platform' => $provider['page_label'],
		'creator'  => $loaded['addresses'][0] ?? '',
	);
}

/**
 * Markup for the block.
 *
 * @param array  $attributes Block attributes.
 * @param string $content    Rendered inner blocks.
 * @return string
 */
function cyberdeck_generative_render( array $attributes, $content ) {
	$pick = static function ( $value, array $allowed, $default ) {
		return in_array( $value, $allowed, true ) ? $value : $default;
	};

	$sketch = isset( $attributes['sketch'] ) && preg_match( '/^[a-z0-9-]{1,40}$/', (string) $attributes['sketch'] ) ? $attributes['sketch'] : 'flow';
	$seed   = isset( $attributes['seed'] ) ? mb_substr( sanitize_text_field( $attributes['seed'] ), 0, 64 ) : 'cyberdeck';
	$mode   = $pick( $attributes['mode'] ?? '', array( 'section', 'fixed' ), 'section' );

	$data = array(
		'data-sketch'      => $sketch,
		'data-seed'        => '' !== $seed ? $seed : 'cyberdeck',
		'data-palette'     => $pick( $attributes['palette'] ?? '', array( 'theme', 'duo', 'primary', 'mono' ), 'theme' ),
		'data-density'     => (string) max( 1, min( 10, (int) ( $attributes['density'] ?? 5 ) ) ),
		'data-speed'       => (string) max( 0, min( 5, (float) ( $attributes['speed'] ?? 1 ) ) ),
		'data-interactive' => ( ! isset( $attributes['interactive'] ) || $attributes['interactive'] ) ? '1' : '0',
		'data-background'  => $pick( $attributes['background'] ?? '', array( 'void', 'transparent' ), 'void' ),
		'data-fps'         => (int) ( $attributes['fps'] ?? 30 ) === 60 ? '60' : '30',
		'data-mode'        => $mode,
	);

	$style = sprintf(
		'--cd-gen-min:%dvh;--cd-gen-dim:%s;',
		max( 20, min( 100, (int) ( $attributes['minHeight'] ?? 70 ) ) ),
		(string) ( max( 0, min( 80, (int) ( $attributes['dim'] ?? 25 ) ) ) / 100 )
	);

	$classes = 'cd-gen is-mode-' . $mode;
	$layer   = '<canvas class="cd-gen__canvas" aria-hidden="true"></canvas>';
	$note    = '';

	// Source: one of the artist's already-minted generative works, shown live.
	if ( 'minted' === ( $attributes['source'] ?? 'sketch' ) ) {
		$minted = cyberdeck_generative_resolve_minted( $attributes );
		if ( is_wp_error( $minted ) ) {
			// Visitors get the built-in sketch; editors get told why.
			if ( current_user_can( 'edit_posts' ) ) {
				$note = '<p class="cd-gen__note">Minted work unavailable (' . esc_html( $minted->get_error_message() ) . '). Showing the built-in sketch.</p>';
			}
		} else {
			$item = $minted['item'];
			$live = ( ! isset( $attributes['mintLive'] ) || $attributes['mintLive'] ) ? '1' : '0';

			$data = array_merge(
				$data,
				array(
					'data-source'      => 'minted',
					'data-src'         => $item['src'],
					'data-title'       => $item['title'],
					'data-token'       => $item['token_id'],
					'data-creator'     => $minted['creator'],
					'data-live'        => $live,
					'data-interactive' => ! empty( $attributes['mintInteractive'] ) ? '1' : '0',
				)
			);
			$classes .= ' is-source-minted';

			$layer = '<div class="cd-gen__frame">';
			if ( '' !== $item['poster'] ) {
				$layer .= '<img class="cd-gen__poster" src="' . esc_url( $item['poster'] ) . '" alt="" decoding="async" referrerpolicy="no-referrer">';
			}
			$layer .= '</div>';
			if ( ! isset( $attributes['showCredit'] ) || $attributes['showCredit'] ) {
				$layer .= '<a class="cd-gen__credit" href="' . esc_url( $item['page'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $item['title'] ) . ' · ' . esc_html( $minted['platform'] ) . '</a>';
			}
		}
	}

	$wrapper = get_block_wrapper_attributes( array_merge( array( 'class' => $classes, 'style' => $style ), $data ) );

	$html  = '<div ' . $wrapper . '>' . $layer;
	if ( 'section' === $mode ) {
		$html .= '<div class="cd-gen__content">' . $content . '</div>';
	}
	$html .= $note . '</div>';

	return $html;
}
