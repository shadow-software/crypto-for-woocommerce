<?php
/**
 * Plugin Name:       Shadow Software Crypto for WooCommerce
 * Plugin URI:        https://github.com/shadow-software/crypto-woocommerce
 * Description:       A simple, free, open-source plugin to confirm common blockchain transactions (USDT/USDC/BTC/ETH) and mark orders paid in Woocommerce. Enter your own receiving addresses; customers pay them directly and the payment is confirmed on-chain with free public nodes and explorers before the order is marked paid. No middleman, no fees, no keys on your server.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * Author:            Shadow Software LLC
 * Author URI:        https://shadowsoftware.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shadow-software-crypto-for-woocommerce
 * Domain Path:       /languages
 *
 * WC requires at least: 8.2
 * WC tested up to:      10.8
 *
 * @package ShadowEth
 */

defined( 'ABSPATH' ) || exit;

// Keep in lockstep with the "Version:" header above and readme.txt's
// "Stable tag:" + changelog.
define( 'SHADOW_ETH_VERSION', '1.0.0' );
define( 'SHADOW_ETH_FILE', __FILE__ );
define( 'SHADOW_ETH_PATH', plugin_dir_path( __FILE__ ) );
define( 'SHADOW_ETH_URL', plugin_dir_url( __FILE__ ) );
define( 'SHADOW_ETH_GATEWAY_ID', 'shadow_eth' );

/**
 * Minimal PSR-4-ish autoloader for the plugin's own classes. The plugin ships
 * no Composer dependencies in the distributed build so it stays drop-in and
 * wp.org-friendly.
 *
 * Hardened against path traversal: the namespace prefix is stripped, the
 * remaining class name is validated to contain only class-name characters, and
 * only files that resolve inside our own includes/ tree are ever required.
 *
 * @param string $classname Fully-qualified class name being autoloaded.
 * @return void
 */
spl_autoload_register(
	static function ( $classname ) {
		$prefix = 'ShadowEth\\';

		if ( 0 !== strpos( $classname, $prefix ) ) {
			return;
		}

		$relative = substr( $classname, strlen( $prefix ) );

		// A valid class name here is only [A-Za-z0-9_\]; anything else (a '.' from
		// ./ or ../, a separator) means it is not one of ours.
		if ( 1 !== preg_match( '/^[A-Za-z0-9_\\\\]+$/', $relative ) ) {
			return;
		}

		$relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative );
		$base     = SHADOW_ETH_PATH . 'includes' . DIRECTORY_SEPARATOR;
		$file     = $base . $relative . '.php';

		$real_base = realpath( $base );
		$real_file = realpath( $file );

		if ( false === $real_base || false === $real_file || 0 !== strpos( $real_file, $real_base ) ) {
			return;
		}

		require $real_file;
	}
);

/**
 * Declare compatibility with WooCommerce features (HPOS / custom order tables
 * and the cart/checkout blocks). Must run before WooCommerce initialises.
 *
 * @return void
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			SHADOW_ETH_FILE,
			true
		);
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'cart_checkout_blocks',
			SHADOW_ETH_FILE,
			true
		);
	}
);

/**
 * Boot the plugin once all plugins are loaded, but only when WooCommerce is
 * active. If WooCommerce is missing, show an admin notice and stand down so the
 * site never fatals.
 *
 * @return void
 */
add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'Shadow Software Crypto for WooCommerce requires WooCommerce to be installed and active.', 'shadow-software-crypto-for-woocommerce' );
					echo '</p></div>';
				}
			);

			return;
		}

		\ShadowEth\Plugin::instance()->init();
	}
);
