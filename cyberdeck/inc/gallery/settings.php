<?php
/**
 * Gallery blocks: site-wide settings (Settings > Tezos blocks).
 *
 * Endpoints are deliberately NOT block attributes, so editors can never point the
 * server at an arbitrary URL. Only administrators can change them here.
 *
 * @package Cyberdeck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const CYBERDECK_TEZOS_OPTION = 'cyberdeck_tezos';

/**
 * Read one setting. Falls back to the pre-0.3 "cyberdeck_teia" option so earlier saves keep working.
 *
 * @param string $key     Setting key.
 * @param mixed  $default Fallback.
 * @return mixed
 */
function cyberdeck_tezos_option( $key, $default = '' ) {
	$all = get_option( CYBERDECK_TEZOS_OPTION, array() );
	if ( is_array( $all ) && isset( $all[ $key ] ) && '' !== $all[ $key ] ) {
		return $all[ $key ];
	}

	$legacy_keys = array(
		'teia_endpoint'          => 'endpoint',
		'teia_endpoint_fallback' => 'endpoint_fallback',
		'custom_gateway'         => 'custom_gateway',
		'cache_minutes'          => 'cache_minutes',
	);
	if ( isset( $legacy_keys[ $key ] ) ) {
		$old = get_option( 'cyberdeck_teia', array() );
		if ( is_array( $old ) && isset( $old[ $legacy_keys[ $key ] ] ) && '' !== $old[ $legacy_keys[ $key ] ] ) {
			return $old[ $legacy_keys[ $key ] ];
		}
	}
	return $default;
}

function cyberdeck_tezos_register_settings() {
	register_setting(
		'cyberdeck_tezos',
		CYBERDECK_TEZOS_OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'cyberdeck_tezos_sanitize_settings',
			'default'           => array(),
		)
	);
}
add_action( 'admin_init', 'cyberdeck_tezos_register_settings' );

/**
 * Only https URLs are accepted for server-side requests and gateways.
 *
 * @param mixed $input Raw form input.
 * @return array
 */
function cyberdeck_tezos_sanitize_settings( $input ) {
	$input = is_array( $input ) ? $input : array();
	$out   = array();

	foreach ( array( 'teia_endpoint', 'teia_endpoint_fallback', 'objkt_endpoint', 'custom_gateway' ) as $key ) {
		$out[ $key ] = isset( $input[ $key ] ) ? esc_url_raw( trim( (string) $input[ $key ] ), array( 'https' ) ) : '';
	}

	// Contract lists: keep only valid KT1 addresses, one per line.
	foreach ( array( 'mederu_contracts', 'objkt_native_contracts' ) as $key ) {
		$out[ $key ] = isset( $input[ $key ] ) ? implode( "\n", cyberdeck_gallery_parse_contracts( (string) $input[ $key ] ) ) : '';
	}

	$out['collect_enabled'] = ! empty( $input['collect_enabled'] ) ? '1' : '';
	$out['collect_cap_xtz'] = isset( $input['collect_cap_xtz'] ) ? max( 0.1, min( 100000, (float) $input['collect_cap_xtz'] ) ) : 50;
	$out['rpc_url']         = isset( $input['rpc_url'] ) ? esc_url_raw( trim( (string) $input['rpc_url'] ), array( 'https' ) ) : '';

	$out['cache_minutes'] = isset( $input['cache_minutes'] ) ? max( 1, min( 1440, (int) $input['cache_minutes'] ) ) : 30;

	return $out;
}

function cyberdeck_tezos_settings_menu() {
	add_options_page( 'Tezos blocks', 'Tezos blocks', 'manage_options', 'cyberdeck-tezos', 'cyberdeck_tezos_settings_page' );
}
add_action( 'admin_menu', 'cyberdeck_tezos_settings_menu' );

/**
 * One text row of the settings table.
 */
function cyberdeck_tezos_settings_row( $key, $label, $placeholder, $help, $type = 'url' ) {
	$all   = get_option( CYBERDECK_TEZOS_OPTION, array() );
	$value = is_array( $all ) && isset( $all[ $key ] ) ? $all[ $key ] : '';
	?>
	<tr>
		<th scope="row"><label for="cd-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
		<td>
			<input id="cd-<?php echo esc_attr( $key ); ?>" class="regular-text code" type="<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( CYBERDECK_TEZOS_OPTION . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>">
			<p class="description"><?php echo esc_html( $help ); ?></p>
		</td>
	</tr>
	<?php
}

/**
 * One textarea row of the settings table.
 */
function cyberdeck_tezos_settings_textarea( $key, $label, $placeholder, $help ) {
	$all   = get_option( CYBERDECK_TEZOS_OPTION, array() );
	$value = is_array( $all ) && isset( $all[ $key ] ) ? $all[ $key ] : '';
	if ( 'mederu_contracts' === $key && '' === $value && ! empty( $all['mederu_studio_contract'] ) ) {
		$value = $all['mederu_studio_contract']; // carry over the old single setting.
	}
	?>
	<tr>
		<th scope="row"><label for="cd-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
		<td>
			<textarea id="cd-<?php echo esc_attr( $key ); ?>" class="large-text code" rows="3" name="<?php echo esc_attr( CYBERDECK_TEZOS_OPTION . '[' . $key . ']' ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
			<p class="description"><?php echo esc_html( $help ); ?></p>
		</td>
	</tr>
	<?php
}

function cyberdeck_tezos_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$all = get_option( CYBERDECK_TEZOS_OPTION, array() );
	?>
	<div class="wrap">
		<h1>Tezos blocks</h1>
		<?php if ( isset( $_GET['flushed'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
			<div class="notice notice-success is-dismissible"><p>Cached data cleared.</p></div>
		<?php endif; ?>
		<form method="post" action="options.php">
			<?php settings_fields( 'cyberdeck_tezos' ); ?>

			<h2>Teia</h2>
			<table class="form-table" role="presentation">
				<?php
				cyberdeck_tezos_settings_row( 'teia_endpoint', 'Indexer endpoint', defined( 'CYBERDECK_TEIA_DEFAULT_ENDPOINT' ) ? CYBERDECK_TEIA_DEFAULT_ENDPOINT : '', 'GraphQL (Hasura/TezTok) endpoint. Leave empty for Teia\'s public indexer.' );
				cyberdeck_tezos_settings_row( 'teia_endpoint_fallback', 'Fallback endpoint', 'optional', 'Tried only if the main endpoint fails.' );
				?>
			</table>

			<h2>objkt and Mederu</h2>
			<table class="form-table" role="presentation">
				<?php
				cyberdeck_tezos_settings_row( 'objkt_endpoint', 'objkt API endpoint', defined( 'CYBERDECK_OBJKT_DEFAULT_ENDPOINT' ) ? CYBERDECK_OBJKT_DEFAULT_ENDPOINT : '', 'The objkt and Mederu blocks (and price/listing lookups for every block) read objkt\'s public GraphQL API.' );
cyberdeck_tezos_settings_textarea( 'mederu_contracts', 'Mederu contracts', 'KT1…', 'Mederu\'s own FA2 contracts, one per line (Studio, and any gacha or free-mint contracts). The Mederu block\'s "native" scope shows only works minted on these. Find an address on any Mederu-minted token\'s objkt page.' );
				cyberdeck_tezos_settings_textarea( 'objkt_native_contracts', 'objkt native collections', 'KT1…', 'Optional. objkt is an open marketplace with no single native contract; list collections created through objkt if you want the objkt block\'s "native" scope to mean those.' );
				?>
			</table>

			<h2>Collecting from the blocks (beta)</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Wallet purchases</th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( CYBERDECK_TEZOS_OPTION ); ?>[collect_enabled]" value="1" <?php checked( ! empty( $all['collect_enabled'] ) ); ?>>
							Allow blocks that opt in to show a "Collect" button (Beacon wallet, fixed-price tez listings only)
						</label>
						<p class="description">Off by default. Real money moves when a visitor confirms in their own wallet. Test with a small purchase first.</p>
					</td>
				</tr>
				<?php
				cyberdeck_tezos_settings_row( 'rpc_url', 'Tezos RPC node', 'https://mainnet.api.tez.ie', 'Public mainnet RPC the visitor\'s browser talks to when preparing a purchase. Must be https and allow browser (CORS) requests.' );
				cyberdeck_tezos_settings_row( 'collect_cap_xtz', 'Largest purchase (ꜩ)', '50', 'Listings priced above this (price × editions) are never offered on-site; visitors are sent to the marketplace instead.', 'number' );
				?>
			</table>

			<h2>Shared</h2>
			<table class="form-table" role="presentation">
				<?php cyberdeck_tezos_settings_row( 'custom_gateway', 'Custom IPFS gateway', 'https://your-gateway.example/ipfs/', 'Selectable as "Custom" in each block. Must be https and end with /ipfs/.' ); ?>
				<tr>
					<th scope="row"><label for="cd-cache_minutes">Default cache (minutes)</label></th>
					<td>
						<input id="cd-cache_minutes" class="small-text" type="number" min="1" max="1440" name="<?php echo esc_attr( CYBERDECK_TEZOS_OPTION ); ?>[cache_minutes]" value="<?php echo esc_attr( $all['cache_minutes'] ?? 30 ); ?>">
						<p class="description">How long a work list is reused before asking the indexer again. If the indexer is down, the last good copy is shown for up to 7 days.</p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<hr>
		<h2>Cache</h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="cyberdeck_tezos_flush">
			<?php wp_nonce_field( 'cyberdeck_tezos_flush' ); ?>
			<?php submit_button( 'Clear cached data', 'secondary', 'submit', false ); ?>
		</form>
	</div>
	<?php
}

function cyberdeck_tezos_handle_flush() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed.' );
	}
	check_admin_referer( 'cyberdeck_tezos_flush' );
	cyberdeck_tezos_flush_cache();
	wp_safe_redirect( add_query_arg( array( 'page' => 'cyberdeck-tezos', 'flushed' => 1 ), admin_url( 'options-general.php' ) ) );
	exit;
}
add_action( 'admin_post_cyberdeck_tezos_flush', 'cyberdeck_tezos_handle_flush' );

/**
 * Delete every cached Teia / objkt / listing / Tezos Domains response.
 */
function cyberdeck_tezos_flush_cache() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE '\_transient\_cd\_teia\_%'
		    OR option_name LIKE '\_transient\_timeout\_cd\_teia\_%'
		    OR option_name LIKE '\_transient\_cd\_objkt\_%'
		    OR option_name LIKE '\_transient\_timeout\_cd\_objkt\_%'
		    OR option_name LIKE '\_transient\_cd\_listing\_%'
		    OR option_name LIKE '\_transient\_timeout\_cd\_listing\_%'"
	);
}
