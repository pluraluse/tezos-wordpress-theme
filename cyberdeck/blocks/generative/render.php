<?php
/**
 * Server render for cyberdeck/generative. Logic lives in inc/generative.php.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner blocks.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the builder; $content is already-rendered blocks.
echo cyberdeck_generative_render( is_array( $attributes ) ? $attributes : array(), isset( $content ) ? (string) $content : '' );
