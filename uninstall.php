<?php
/**
 * Uninstall cleanup for Accept Ethereum for WooCommerce.
 *
 * Runs only on plugin deletion (not deactivation). Removes the gateway settings
 * option and the cached exchange-rate transients. Order meta (the locked amount,
 * network, and confirmed transaction hash) is intentionally LEFT in place so
 * historical orders keep their on-chain audit trail.
 *
 * @package ShadowEth
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove plugin options + transients on uninstall. Prefixed so nothing leaks
 * into the WordPress scope during deletion.
 *
 * @return void
 */
function shadow_eth_uninstall_cleanup() {
	delete_option( 'woocommerce_shadow_eth_settings' );
	delete_option( 'shadow_eth_consumed_txs' );
	delete_option( 'shadow_eth_consumed_txs_lock' );

	// Clear cached rate transients (best effort; all of them also expire on their
	// own within a couple of minutes). Keys are shadow_eth_rate_{coin}_{currency}.
	$coins      = array( 'ethereum', 'bitcoin', 'usd-coin', 'tether' );
	$currencies = array(
		'usd',
		'eur',
		'gbp',
		'aud',
		'cad',
		'chf',
		'cny',
		'jpy',
		'inr',
		'brl',
		'nzd',
		'sgd',
		'hkd',
		'zar',
		'sek',
		'nok',
		'dkk',
		'pln',
		'mxn',
		'try',
	);

	foreach ( $coins as $coin ) {
		foreach ( $currencies as $currency ) {
			delete_transient( 'shadow_eth_rate_' . $coin . '_' . $currency );
		}
	}

	// The chain-id verification transients (shadow_eth_chainok_*) are keyed by an
	// endpoint hash and expire on their own within an hour; nothing to enumerate.
}

/**
 * Run cleanup for the current site, and for every site on multisite.
 */
if ( is_multisite() ) {
	$shadow_eth_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( (array) $shadow_eth_site_ids as $shadow_eth_site_id ) {
		switch_to_blog( (int) $shadow_eth_site_id );
		shadow_eth_uninstall_cleanup();
		restore_current_blog();
	}
} else {
	shadow_eth_uninstall_cleanup();
}
