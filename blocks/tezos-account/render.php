<?php
/**
 * Server render for cyberdeck/tezos-account. Logic lives in inc/account/.
 *
 * @var array $attributes Block attributes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside the builder.
echo cyberdeck_account_render( is_array( $attributes ) ? $attributes : array() );
