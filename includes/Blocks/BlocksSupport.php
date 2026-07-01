<?php
/**
 * WooCommerce Cart & Checkout Blocks integration for the Ethereum gateway.
 *
 * The gateway collects nothing at checkout (the buyer pays and confirms on the
 * order-pay page afterwards), so this integration is deliberately thin: it makes
 * the method appear in the block checkout with its title/description/icon and
 * hands control to the same classic process_payment(), which redirects to the
 * pay page.
 *
 * @package ShadowEth
 */

namespace ShadowEth\Blocks;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
use ShadowEth\Gateway;

defined( 'ABSPATH' ) || exit;

/**
 * Payment method type registered with the Blocks payment registry.
 */
final class BlocksSupport extends AbstractPaymentMethodType {

	/**
	 * Payment method name (matches the gateway id).
	 *
	 * @var string
	 */
	protected $name = SHADOW_ETH_GATEWAY_ID;

	/**
	 * Load the gateway settings into the inherited $settings property.
	 *
	 * @return void
	 */
	public function initialize(): void {
		$stored = get_option( 'woocommerce_' . SHADOW_ETH_GATEWAY_ID . '_settings', array() );

		$this->settings = is_array( $stored ) ? $stored : array();
	}

	/**
	 * Whether the method is active/available in the block checkout.
	 */
	public function is_active(): bool {
		$gateway = $this->get_gateway();

		return $gateway instanceof Gateway && $gateway->is_available();
	}

	/**
	 * Register and return the front-end script handle(s) for the block.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles(): array {
		$handle = 'shadow-eth-blocks';
		$asset  = SHADOW_ETH_PATH . 'assets/js/blocks.asset.php';

		$deps    = array( 'wc-blocks-registry', 'wp-element', 'wp-html-entities', 'wp-i18n' );
		$version = SHADOW_ETH_VERSION;

		if ( file_exists( $asset ) ) {
			$data    = require $asset;
			$deps    = isset( $data['dependencies'] ) && is_array( $data['dependencies'] ) ? $data['dependencies'] : $deps;
			$version = isset( $data['version'] ) ? (string) $data['version'] : $version;
		}

		wp_register_script(
			$handle,
			SHADOW_ETH_URL . 'assets/js/blocks.js',
			$deps,
			$version,
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( $handle, 'crypto-woocommerce' );
		}

		return array( $handle );
	}

	/**
	 * Data passed to the block front-end.
	 *
	 * @return array<string,mixed>
	 */
	public function get_payment_method_data(): array {
		$gateway = $this->get_gateway();

		return array(
			'title'       => $gateway instanceof Gateway ? $gateway->title : __( 'Pay with crypto (ETH, USDC, USDT, BTC)', 'crypto-woocommerce' ),
			'description' => $gateway instanceof Gateway ? $gateway->description : '',
			'icon'        => SHADOW_ETH_URL . 'assets/img/crypto.svg',
			'supports'    => array( 'products' ),
		);
	}

	/**
	 * The configured gateway instance.
	 */
	private function get_gateway(): ?Gateway {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();

		if ( isset( $gateways[ SHADOW_ETH_GATEWAY_ID ] ) && $gateways[ SHADOW_ETH_GATEWAY_ID ] instanceof Gateway ) {
			return $gateways[ SHADOW_ETH_GATEWAY_ID ];
		}

		return null;
	}
}
