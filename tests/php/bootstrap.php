<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads Composer's autoloader (Brain Monkey + polyfills) and the plugin classes
 * under test. The pure-logic classes (Money, Address, Keccak, Networks) are
 * exercised without a running WordPress; the few WP functions they touch are
 * stubbed here or mocked per-test with Brain Monkey.
 *
 * @package ShadowEth
 */

define( 'SHADOW_ETH_TESTING', true );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/../../vendor/autoload.php';

// A couple of i18n functions the pure-logic classes call at load/return time.
if ( ! function_exists( '__' ) ) {
	/**
	 * Minimal translation stub returning the text unchanged.
	 *
	 * @param string $text   Text.
	 * @param string $domain Text domain (ignored).
	 * @return string
	 */
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

// In-memory options API so TxRegistry can be unit-tested without WordPress.
// Backed by a global array; tests reset it via shadow_eth_reset_options().
$GLOBALS['shadow_eth_test_options'] = array();

/**
 * Reset the in-memory options store between tests.
 *
 * @return void
 */
function shadow_eth_reset_options() {
	$GLOBALS['shadow_eth_test_options'] = array();
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param string $key     Option name.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['shadow_eth_test_options'] )
			? $GLOBALS['shadow_eth_test_options'][ $key ]
			: $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * @param string $key   Option name.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	function update_option( $key, $value ) {
		$GLOBALS['shadow_eth_test_options'][ $key ] = $value;

		return true;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	/**
	 * Atomic-ish add: fails if the key already exists (mirrors WP add_option).
	 *
	 * @param string $key   Option name.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	function add_option( $key, $value = '' ) {
		if ( array_key_exists( $key, $GLOBALS['shadow_eth_test_options'] ) ) {
			return false;
		}

		$GLOBALS['shadow_eth_test_options'][ $key ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * @param string $key Option name.
	 * @return bool
	 */
	function delete_option( $key ) {
		unset( $GLOBALS['shadow_eth_test_options'][ $key ] );

		return true;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * @param string $key Raw key.
	 * @return string
	 */
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

// Load the classes under test directly (mirrors the plugin's own autoloader
// without booting WordPress).
$shadow_eth_includes = __DIR__ . '/../../includes/';
require_once $shadow_eth_includes . 'Keccak.php';
require_once $shadow_eth_includes . 'Money.php';
require_once $shadow_eth_includes . 'Address.php';
require_once $shadow_eth_includes . 'Networks.php';
require_once $shadow_eth_includes . 'Assets.php';
require_once $shadow_eth_includes . 'Erc20.php';
require_once $shadow_eth_includes . 'BtcAddress.php';
require_once $shadow_eth_includes . 'TxRegistry.php';
