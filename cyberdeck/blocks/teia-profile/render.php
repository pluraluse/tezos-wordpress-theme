<?php
/**
 * Server render for cyberdeck/teia-profile.
 * All logic lives in inc/gallery/render.php and inc/teia/client.php.
 *
 * @var array $attributes Block attributes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the builder.
echo cyberdeck_gallery_render( is_array( $attributes ) ? $attributes : array(), cyberdeck_teia_provider() );
