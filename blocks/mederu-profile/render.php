<?php
/**
 * Server render for cyberdeck/mederu-profile.
 * All logic lives in inc/gallery/render.php and inc/mederu/client.php.
 *
 * @var array $attributes Block attributes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the builder.
echo cyberdeck_gallery_render( is_array( $attributes ) ? $attributes : array(), cyberdeck_mederu_provider() );
