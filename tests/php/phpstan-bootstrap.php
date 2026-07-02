<?php
/**
 * Constant definitions for static analysis.
 *
 * PHPStan needs the plugin's bootstrap constants defined so references to them in
 * the includes resolve. These mirror the define()s in the main plugin file.
 *
 * @package ShadowEth
 */

define( 'SHADOW_ETH_VERSION', '1.0.0' );
define( 'SHADOW_ETH_FILE', __DIR__ . '/../../shadowledger-crypto-for-woocommerce.php' );
define( 'SHADOW_ETH_PATH', __DIR__ . '/../../' );
define( 'SHADOW_ETH_URL', 'https://example.test/wp-content/plugins/shadowledger-crypto-for-woocommerce/' );
define( 'SHADOW_ETH_GATEWAY_ID', 'shadow_eth' );
