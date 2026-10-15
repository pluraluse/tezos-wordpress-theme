<?php
/**
 * Gallery blocks: provider-agnostic markup builder.
 *
 * A "provider" (Teia, Mederu, later objkt, ...) supplies data; this file turns it into markup.
 * Everything is server rendered. Animated media (video, GIF, audio, GLB, interactive HTML)
 * is output as a still poster only; gallery.js loads the real player on demand, so a page
 * with dozens of pieces never starts dozens of decoders or WebGL contexts.
 *
 * Provider array:
 *   slug       string   e.g. "teia"
 *   page_label string   e.g. "Teia", "objkt" (used in link/overlay text)
 *   sources    string[] allowed values for the "source" attribute
 *   multi      bool     whether several addresses may be given
 *   resolve    callable( string $input ): string[]|WP_Error
 *   fetch      callable( array $a, string[] $addresses, int $fetch ): array{items:array,stale:bool}|WP_Error
 *   profile    callable( array $a, string[] $addresses ): array{name:string,bio:string,avatar:string,url:string,label:string}
 *
 * Normalized token (what fetch() returns, URIs still raw):
 *   fa2, token_id, title, description, mime, artifact, display, thumbnail,
 *   minted_at, editions, url (public page), credit (string), flag (string), price (display string, optional)
 *
 * @package Cyberdeck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Layouts every gallery block offers.
 *
 * @return string[]
 */
function cyberdeck_gallery_layouts() {
	return array( 'grid', 'masonry', 'mosaic', 'marquee', 'carousel', 'spotlight', 'list', 'feed', 'single' );
}

/**
 * Whitelist and default every block attribute.
 *
 * @param array $a        Raw attributes.
 * @param array $provider Provider definition.
 * @return array
 */
function cyberdeck_gallery_clean_attributes( array $a, array $provider ) {
	$pick = static function ( $value, array $allowed, $default ) {
		return in_array( $value, $allowed, true ) ? $value : $default;
	};

	// Legacy: the first Mederu build had a "studio" source; it is now source=created + scope=native.
	$legacy_studio = ( 'studio' === ( $a['source'] ?? '' ) );
	if ( $legacy_studio ) {
		$a['source'] = 'created';
		$a['scope']  = 'native';
	}

	$contracts = cyberdeck_gallery_parse_contracts( (string) ( $a['contracts'] ?? '' ) . "\n" . (string) ( $a['contract'] ?? '' ) );
	$default   = (string) ( $provider['default_contract'] ?? '' );
	if ( '' === $default && 1 === count( $contracts ) ) {
		$default = $contracts[0];
	}
	$parsed = cyberdeck_gallery_parse_works( (string) ( $a['works'] ?? '' ), $default );

	$kinds = array_keys( cyberdeck_gallery_kinds() );
	$types = isset( $a['types'] ) && is_array( $a['types'] ) ? array_values( array_intersect( $kinds, $a['types'] ) ) : $kinds;

	return array(
		'address'      => isset( $a['address'] ) ? sanitize_textarea_field( $a['address'] ) : '',
		'source'       => $pick( $a['source'] ?? '', $provider['sources'], $provider['sources'][0] ),
		'layout'       => $pick( $a['layout'] ?? '', cyberdeck_gallery_layouts(), 'grid' ),
		'limit'        => max( 1, min( 60, (int) ( $a['limit'] ?? 12 ) ) ),
		'order'        => $pick( $a['order'] ?? '', array( 'newest', 'oldest', 'random' ), 'newest' ),
		'types'        => $types,
		'dropUrl'      => isset( $a['dropUrl'] ) ? esc_url_raw( (string) $a['dropUrl'], array( 'https' ) ) : '',
		'dropLabel'    => isset( $a['dropLabel'] ) ? sanitize_text_field( $a['dropLabel'] ) : '',
		'scope'        => $pick( $a['scope'] ?? '', array( 'native', 'any' ), 'native' ),
		'contracts'    => $contracts,
		'works'        => $parsed['works'],
		'worksInvalid' => $parsed['invalid'],
		'tokenId'      => preg_match( '/^\d{1,12}$/', (string) ( $a['tokenId'] ?? '' ) ) ? (string) $a['tokenId'] : '',
		'columns'      => max( 1, min( 10, (int) ( $a['columns'] ?? 3 ) ) ),
		'gap'          => max( 0, min( 80, (int) ( $a['gap'] ?? 16 ) ) ),
		'aspect'       => $pick( $a['aspect'] ?? '', array( 'auto', '1/1', '4/3', '3/2', '16/9', '3/4', '2/3' ), 'auto' ),
		'fit'          => $pick( $a['fit'] ?? '', array( 'cover', 'contain' ), 'cover' ),
		'itemSize'     => max( 80, min( 600, (int) ( $a['itemSize'] ?? 220 ) ) ),
		'showProfile'  => ! isset( $a['showProfile'] ) || (bool) $a['showProfile'],
		'profileName'  => isset( $a['profileName'] ) ? sanitize_text_field( $a['profileName'] ) : '',
		'profileBio'   => isset( $a['profileBio'] ) ? sanitize_textarea_field( $a['profileBio'] ) : '',
		'showTitle'    => ! isset( $a['showTitle'] ) || (bool) $a['showTitle'],
		'showMeta'     => ! empty( $a['showMeta'] ),
		'showBadges'   => ! isset( $a['showBadges'] ) || (bool) $a['showBadges'],
		'showLink'     => ! isset( $a['showLink'] ) || (bool) $a['showLink'],
		'showCredits'  => ! empty( $a['showCredits'] ),
		'showPrice'    => ! empty( $a['showPrice'] ),
		'allowCollect' => ! empty( $a['allowCollect'] ),
		'includeFlagged' => ! empty( $a['includeFlagged'] ),
		'interaction'  => $pick( $a['interaction'] ?? '', array( 'lightbox', 'inline', 'page' ), 'lightbox' ),
		'maxActive'    => max( 1, min( 4, (int) ( $a['maxActive'] ?? 1 ) ) ),
		'gateway'      => $pick( $a['gateway'] ?? '', array_keys( cyberdeck_gallery_gateways() ), 'teia' ),
		'cacheMinutes' => max( 1, min( 1440, (int) ( $a['cacheMinutes'] ?? cyberdeck_tezos_option( 'cache_minutes', 30 ) ) ) ),
		'speed'        => max( 5, min( 400, (int) ( $a['speed'] ?? 40 ) ) ),
		'interval'     => max( 2, min( 20, (int) ( $a['interval'] ?? 5 ) ) ),
		'tilt'         => ! empty( $a['tilt'] ),
		'direction'    => $pick( $a['direction'] ?? '', array( 'left', 'right', 'up', 'down' ), 'left' ),
		'visible'      => max( 0, min( 12, (int) ( $a['visible'] ?? 0 ) ) ),
		'pauseOnHover' => ! isset( $a['pauseOnHover'] ) || (bool) $a['pauseOnHover'],
	);
}

/**
 * Message box. Only people who can edit posts see it; visitors get nothing.
 *
 * @param string $message Text.
 * @return string
 */
function cyberdeck_gallery_notice( $message ) {
	if ( ! current_user_can( 'edit_posts' ) ) {
		return '';
	}
	return sprintf( '<div %s><p class="gx-notice">%s</p></div>', get_block_wrapper_attributes( array( 'class' => 'cd-gallery is-notice' ) ), esc_html( $message ) );
}

/**
 * Resolve scope, fetch from the provider and turn the result into display-ready items.
 * Shared by the gallery blocks and by blocks that borrow works (e.g. the generative hero).
 *
 * @param array $a        Clean attributes (see cyberdeck_gallery_clean_attributes()).
 * @param array $provider Provider definition.
 * @return array{a:array,addresses:array,items:array,stale:bool}|WP_Error
 */
function cyberdeck_gallery_load_items( array $a, array $provider ) {
	// Scope: an explicit contract list wins; otherwise "native" means the platform's own contract(s).
	if ( ! $a['works'] && ! $a['contracts'] && 'native' === $a['scope'] && isset( $provider['native'] ) ) {
		$native = (array) call_user_func( $provider['native'] );
		if ( ! $native && ! empty( $provider['native_required'] ) ) {
			return new WP_Error( 'gallery_native_required', (string) $provider['native_required'] );
		}
		$a['contracts'] = $native;
	}

	// A list of specific works replaces the account lookup entirely.
	if ( $a['works'] ) {
		$addresses = array( '' );
		if ( '' === $a['profileName'] && '' === $a['profileBio'] ) {
			$a['showProfile'] = false;
		}
	} else {
		$addresses = call_user_func( $provider['resolve'], $a['address'] );
		if ( is_wp_error( $addresses ) ) {
			return $addresses;
		}
	}

	$all_types = count( $a['types'] ) === count( cyberdeck_gallery_kinds() );
	$single_id = ( ! $a['works'] && 'single' === $a['layout'] && '' !== $a['tokenId'] );
	if ( $a['works'] ) {
		$fetch = count( $a['works'] );
	} elseif ( $single_id ) {
		$fetch = 1;
	} elseif ( $all_types && 'random' !== $a['order'] ) {
		$fetch = $a['limit'] + 6;
	} else {
		$fetch = min( 200, max( $a['limit'] * 3, 48 ) );
	}

	$result = call_user_func( $provider['fetch'], $a, $addresses, $fetch );
	if ( is_wp_error( $result ) ) {
		return new WP_Error( 'gallery_load_failed', 'could not load works. ' . $result->get_error_message() );
	}

	$items = array();
	foreach ( $result['items'] as $token ) {
		$flag = $token['flag'] ?? '';
		if ( in_array( $flag, array( 'banned', 'removed' ), true ) || ( ! $a['includeFlagged'] && 'flagged' === $flag ) ) {
			continue; // banned/removed are never shown; "flagged" only when the editor opts in.
		}
		$item = cyberdeck_gallery_prepare_item( $token, $a );
		if ( $item && in_array( $item['kind'], $a['types'], true ) ) {
			$items[] = $item;
		}
	}
	if ( $a['works'] ) {
		// Exactly the works listed, in the order listed.
		$position = array();
		foreach ( $a['works'] as $index => $work ) {
			$position[ $work['fa2'] . ':' . $work['token_id'] ] = $index;
		}
		$items = array_values(
			array_filter(
				$items,
				static function ( $i ) use ( $position ) {
					return isset( $position[ $i['fa2'] . ':' . $i['token_id'] ] );
				}
			)
		);
		usort(
			$items,
			static function ( $x, $y ) use ( $position ) {
				return $position[ $x['fa2'] . ':' . $x['token_id'] ] <=> $position[ $y['fa2'] . ':' . $y['token_id'] ];
			}
		);
		$items = array_slice( $items, 0, 'single' === $a['layout'] ? 1 : count( $a['works'] ) );
	} else {
		if ( 'random' === $a['order'] ) {
			shuffle( $items );
		}
		$items = array_slice( $items, 0, 'single' === $a['layout'] ? 1 : $a['limit'] );
	}

	if ( ! $items ) {
		return new WP_Error( 'gallery_empty', 'no works matched for this account and filters.' );
	}

	return array(
		'a'         => $a,
		'addresses' => $addresses,
		'items'     => $items,
		'stale'     => ! empty( $result['stale'] ),
	);
}

/**
 * Render a gallery block.
 *
 * @param array $raw_attributes Block attributes.
 * @param array $provider       Provider definition.
 * @return string
 */
function cyberdeck_gallery_render( array $raw_attributes, array $provider ) {
	$a     = cyberdeck_gallery_clean_attributes( $raw_attributes, $provider );
	$title = $provider['page_label'] . ' block: ';

	// Collecting happens in the viewer overlay, and only when the site admin has switched it on.
	$collect = $a['allowCollect'] && cyberdeck_collect_enabled() && isset( cyberdeck_collect_adapters()[ $provider['slug'] ] );
	if ( $collect || 'spotlight' === $a['layout'] ) {
		// Collecting happens in the overlay; the spotlight's big image opens it too.
		$a['interaction'] = 'page' === $a['interaction'] ? 'page' : 'lightbox';
	}

	$loaded = cyberdeck_gallery_load_items( $a, $provider );
	if ( is_wp_error( $loaded ) ) {
		return cyberdeck_gallery_notice( $title . $loaded->get_error_message() );
	}
	$a         = $loaded['a'];
	$addresses = $loaded['addresses'];
	$items     = $loaded['items'];
	$result    = array( 'stale' => $loaded['stale'] );

	$has_model = (bool) array_filter(
		$items,
		static function ( $i ) {
			return 'model' === $i['kind'];
		}
	);

	$vertical = in_array( $a['layout'], array( 'marquee', 'carousel' ), true ) && in_array( $a['direction'], array( 'up', 'down' ), true );
	// How many items are in view at once: marquee has its own setting (0 = size by height), carousel uses columns.
	$visible  = 'carousel' === $a['layout'] ? $a['columns'] : $a['visible'];

	$classes = array(
		'cd-gallery',
		'is-provider-' . $provider['slug'],
		'is-layout-' . $a['layout'],
		$vertical ? 'is-dir-vertical' : '',
		( 'marquee' === $a['layout'] && $a['visible'] > 0 ) ? 'has-visible' : '',
		'auto' === $a['aspect'] ? 'has-auto-aspect' : 'has-fixed-aspect',
	);

	$style = sprintf(
		'--gx-cols:%d;--gx-visible:%d;--gx-gap:%dpx;--gx-aspect:%s;--gx-fit:%s;--gx-item-size:%dpx;',
		$a['columns'],
		max( 1, $visible ),
		$a['gap'],
		'auto' === $a['aspect'] ? 'auto' : str_replace( '/', ' / ', $a['aspect'] ),
		$a['fit'],
		$a['itemSize']
	);

	$data = array(
		'data-layout'         => $a['layout'],
		'data-interaction'    => $a['interaction'],
		'data-max-active'     => (string) $a['maxActive'],
		'data-speed'          => (string) $a['speed'],
		'data-direction'      => $a['direction'],
		'data-visible'        => (string) max( 1, $visible ),
		'data-interval'       => (string) $a['interval'],
		'data-tilt'           => $a['tilt'] ? '1' : '0',
		'data-pause'          => $a['pauseOnHover'] ? '1' : '0',
		'data-creator'        => $addresses[0],
		'data-provider-label' => $provider['page_label'],
	);
	if ( $collect ) {
		$data['data-collect']      = '1';
		$data['data-provider']     = $provider['slug'];
		$data['data-listing-url']  = rest_url( 'cyberdeck/v1/listing' );
		$data['data-rpc']          = cyberdeck_collect_rpc();
		$data['data-collect-js']   = get_theme_file_uri( 'assets/gallery/collect.js' );
		$data['data-wallet-js']    = get_theme_file_uri( 'assets/vendor/wallet/cyberdeck-wallet.min.js' );
		$data['data-site-name']    = wp_strip_all_tags( get_bloginfo( 'name' ) );
	}
	if ( $has_model ) {
		$data['data-viewer'] = get_theme_file_uri( 'assets/vendor/model-viewer/model-viewer.min.js' );
	}

	$wrapper = get_block_wrapper_attributes( array_merge( array( 'class' => implode( ' ', array_filter( $classes ) ), 'style' => $style ), $data ) );

	$html  = '<div ' . $wrapper . '>';
	$html .= $a['showProfile'] ? cyberdeck_gallery_render_profile( $a, $provider, $addresses ) : '';
	if ( ! empty( $provider['extra'] ) ) {
		$html .= (string) call_user_func( $provider['extra'], $a );
	}
	$html .= '<div class="gx-viewport"><div class="gx-items"' . ( in_array( $a['layout'], array( 'carousel', 'marquee' ), true ) ? ' tabindex="0" role="region" aria-label="Artworks"' : '' ) . '>';
	foreach ( $items as $item ) {
		$html .= cyberdeck_gallery_render_item( $item, $a, $provider );
	}
	$html .= '</div></div>';

	if ( 'carousel' === $a['layout'] ) {
		$html .= '<div class="gx-nav" hidden>'
			. '<button type="button" class="gx-prev" aria-label="Previous">&#8249;</button>'
			. '<button type="button" class="gx-next" aria-label="Next">&#8250;</button>'
			. '</div>';
	}
	if ( $a['worksInvalid'] && current_user_can( 'edit_posts' ) ) {
		$html .= '<p class="gx-notice">' . esc_html( sprintf( '%d line(s) in "Specific works" could not be read: %s', count( $a['worksInvalid'] ), implode( ' | ', array_slice( $a['worksInvalid'], 0, 3 ) ) ) ) . '</p>';
	}
	if ( ! empty( $result['stale'] ) && current_user_can( 'edit_posts' ) ) {
		$html .= '<p class="gx-notice">Showing the last saved copy; the data source could not be reached.</p>';
	}
	$html .= '</div>';

	return $html;
}

/**
 * Resolve one cached token into display data.
 *
 * @param array $t Normalized token.
 * @param array $a Clean attributes.
 * @return array|null
 */
function cyberdeck_gallery_prepare_item( array $t, array $a ) {
	$kind     = cyberdeck_gallery_kind( $t['mime'] );
	$artifact = cyberdeck_gallery_resolve_uri( $t['artifact'], $a['gateway'] );
	$display  = cyberdeck_gallery_resolve_uri( $t['display'], $a['gateway'] );
	$thumb    = cyberdeck_gallery_resolve_uri( $t['thumbnail'], $a['gateway'] );

	// Posters are always still images. Only a real still image may fall back to its own artifact.
	if ( 'image' === $kind ) {
		$poster = $display ? $display : ( $artifact ? $artifact : $thumb );
	} else {
		$poster = $display ? $display : $thumb;
	}

	// A poster that is just the artifact itself (common for GIFs, sometimes video) would play
	// on its own, which defeats click-to-play. Use the thumbnail, or a placeholder, instead.
	if ( 'image' !== $kind && '' !== $poster && $poster === $artifact ) {
		$poster = ( '' !== $thumb && $thumb !== $artifact ) ? $thumb : '';
	}

	if ( '' === $poster && '' === $artifact ) {
		return null;
	}

	return array(
		'kind'        => $kind,
		'mime'        => $t['mime'],
		'src'         => $artifact,
		'poster'      => $poster,
		'title'       => '' !== $t['title'] ? $t['title'] : 'Untitled #' . $t['token_id'],
		'description' => $t['description'],
		'page'        => $t['url'],
		'token_id'    => $t['token_id'],
		'fa2'         => $t['fa2'],
		'price'       => (string) ( $t['price'] ?? '' ),
		'editions'    => $t['editions'],
		'minted'      => substr( $t['minted_at'], 0, 10 ),
		'credit'      => (string) ( $t['credit'] ?? '' ),
	);
}

/**
 * Profile header (optional avatar, name, bio).
 */
function cyberdeck_gallery_render_profile( array $a, array $provider, array $addresses ) {
	// Hand-picked works have no account to look up: the header uses only the typed name and bio.
	$p = '' === (string) ( $addresses[0] ?? '' )
		? array( 'name' => '', 'bio' => '', 'avatar' => '', 'url' => '', 'label' => '' )
		: call_user_func( $provider['profile'], $a, $addresses );

	$name = '' !== $a['profileName'] ? $a['profileName'] : (string) ( $p['name'] ?? '' );
	$bio  = '' !== $a['profileBio'] ? $a['profileBio'] : (string) ( $p['bio'] ?? '' );
	$url  = (string) ( $p['url'] ?? '' );

	$out = '<header class="gx-profile">';

	$avatar = cyberdeck_gallery_resolve_uri( (string) ( $p['avatar'] ?? '' ), $a['gateway'] );
	if ( '' !== $avatar ) {
		$out .= '<img class="gx-avatar" src="' . esc_url( $avatar ) . '" alt="" width="64" height="64" loading="lazy" decoding="async" referrerpolicy="no-referrer">';
	}

	$out .= '<div class="gx-profile__text">';
	if ( ! empty( $p['label'] ) ) {
		$out .= '<p class="gx-profile__label">' . esc_html( $p['label'] ) . '</p>';
	}
	$name_html = esc_html( $name );
	if ( '' !== $url ) {
		$name_html = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . $name_html . '</a>';
	}
	$out .= '<h3 class="gx-profile__name">' . $name_html . '</h3>';
	if ( '' !== $bio ) {
		$out .= '<p class="gx-profile__bio">' . esc_html( wp_trim_words( $bio, 60, '…' ) ) . '</p>';
	}
	$out .= '</div></header>';

	return $out;
}

/**
 * One artwork.
 *
 * @param array $i        Prepared item.
 * @param array $a        Clean attributes.
 * @param array $provider Provider definition.
 * @return string
 */
function cyberdeck_gallery_render_item( array $i, array $a, array $provider ) {
	$labels = array(
		'gif'         => 'GIF',
		'video'       => 'Video',
		'audio'       => 'Audio',
		'model'       => '3D',
		'interactive' => 'Interactive',
		'other'       => 'File',
	);

	$is_still = ( 'image' === $i['kind'] );
	$href     = 'page' === $a['interaction'] || ! $i['src'] ? $i['page'] : $i['src'];
	$verb     = $is_still ? 'View' : 'Open';

	$attrs = array(
		'class'       => 'gx-item is-kind-' . $i['kind'],
		'data-kind'   => $i['kind'],
		'data-src'    => $i['src'],
		'data-mime'   => $i['mime'],
		'data-poster' => $i['poster'],
		'data-title'  => $i['title'],
		'data-page'   => $i['page'],
		'data-token'  => $i['token_id'],
		'data-fa2'    => $i['fa2'],
	);
	$attr_html = '';
	foreach ( $attrs as $name => $value ) {
		$attr_html .= ' ' . $name . '="' . esc_attr( $value ) . '"';
	}

	$link_attrs = 'page' === $a['interaction'] ? ' target="_blank" rel="noopener noreferrer"' : '';

	$out  = '<figure' . $attr_html . '>';
	$out .= '<div class="gx-stage">';
	$out .= '<a class="gx-open" href="' . esc_url( $href ) . '"' . $link_attrs . ' aria-label="' . esc_attr( sprintf( '%s “%s”', $verb, $i['title'] ) ) . '">';
	if ( $i['poster'] ) {
		$out .= '<img class="gx-poster" src="' . esc_url( $i['poster'] ) . '" alt="' . esc_attr( $i['title'] ) . '" loading="lazy" decoding="async" referrerpolicy="no-referrer">';
	} else {
		$out .= '<span class="gx-placeholder" aria-hidden="true"></span>';
	}
	if ( ! $is_still ) {
		$out .= '<span class="gx-play" aria-hidden="true"></span>';
		if ( $a['showBadges'] ) {
			$out .= '<span class="gx-badge">' . esc_html( $labels[ $i['kind'] ] ?? 'File' ) . '</span>';
		}
	}
	$out .= '</a></div>';

	$show_credit  = $a['showCredits'] && '' !== $i['credit'];
	$show_price   = $a['showPrice'] && '' !== $i['price'];
	$show_caption = $a['showTitle'] || $a['showLink'] || $a['showMeta'] || $show_credit || $show_price || in_array( $a['layout'], array( 'list', 'feed' ), true );
	if ( $show_caption ) {
		$out .= '<figcaption class="gx-caption">';
		if ( $a['showTitle'] ) {
			$out .= '<span class="gx-title">' . esc_html( $i['title'] ) . '</span>';
		}
		if ( in_array( $a['layout'], array( 'list', 'feed' ), true ) && '' !== $i['description'] ) {
			$out .= '<span class="gx-desc">' . esc_html( wp_trim_words( $i['description'], 40, '…' ) ) . '</span>';
		}
		if ( $a['showMeta'] ) {
			$meta = array_filter( array( '' !== $i['editions'] ? 'ed. ' . $i['editions'] : '', $i['minted'] ) );
			if ( $meta ) {
				$out .= '<span class="gx-meta">' . esc_html( implode( ' · ', $meta ) ) . '</span>';
			}
		}
		if ( $show_price ) {
			$out .= '<span class="gx-price"><span class="screen-reader-text">From </span>' . esc_html( $i['price'] ) . '</span>';
		}
		if ( $show_credit ) {
			$out .= '<span class="gx-credit">' . esc_html( $i['credit'] ) . '</span>';
		}
		if ( $a['showLink'] ) {
			$out .= '<a class="gx-link" href="' . esc_url( $i['page'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( strtolower( $provider['page_label'] ) ) . '<span class="screen-reader-text"> page for ' . esc_html( $i['title'] ) . '</span></a>';
		}
		$out .= '</figcaption>';
	}
	$out .= '</figure>';

	return $out;
}
