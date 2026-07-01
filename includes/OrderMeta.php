<?php
/**
 * Typed accessors for the order meta this plugin stores.
 *
 * Centralising the meta keys and the payment "state" here keeps the gateway, the
 * buyer submission handler, and the on-chain verifier reading/writing the same
 * fields with the same names — and keeps the keys underscore-prefixed so they
 * stay out of the generic custom-fields UI. HPOS-safe: everything goes through
 * WC_Order's meta API.
 *
 * @package ShadowEth
 */

namespace ShadowEth;

defined( 'ABSPATH' ) || exit;

/**
 * Order-meta gateway. All keys are prefixed `_shadow_eth_`.
 */
final class OrderMeta {

	// Locked-in payment requirement (set at process_payment time).
	public const ASSET         = '_shadow_eth_asset';
	public const NETWORK       = '_shadow_eth_network';
	public const PAY_ADDRESS   = '_shadow_eth_pay_address';
	public const AMOUNT_WEI    = '_shadow_eth_amount_wei';
	public const RATE          = '_shadow_eth_rate';
	public const RATE_CURRENCY = '_shadow_eth_rate_currency';
	public const START_BLOCK   = '_shadow_eth_start_block';

	// Buyer-submitted claim.
	public const SENDER    = '_shadow_eth_sender';
	public const TX_HASH   = '_shadow_eth_tx_hash';
	public const SUBMITTED = '_shadow_eth_submitted_at';

	// Verification progress.
	public const STATE        = '_shadow_eth_state';
	public const ATTEMPTS     = '_shadow_eth_attempts';
	public const LAST_CHECK   = '_shadow_eth_last_check';
	public const CONFIRMED_TX = '_shadow_eth_confirmed_tx';

	// State values.
	public const STATE_AWAITING_PAYMENT = 'awaiting_payment'; // order placed, buyer not yet submitted.
	public const STATE_VERIFYING        = 'verifying';        // buyer submitted, polling on-chain.
	public const STATE_CONFIRMED        = 'confirmed';        // payment confirmed, order completed.
	public const STATE_FAILED           = 'failed';           // gave up (timeout / wrong payment).

	/**
	 * Read a string meta value.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $key   Meta key.
	 */
	public static function get_string( \WC_Order $order, string $key ): string {
		$value = $order->get_meta( $key );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Read an integer meta value.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $key   Meta key.
	 */
	public static function get_int( \WC_Order $order, string $key ): int {
		return (int) $order->get_meta( $key );
	}

	/**
	 * The current payment state, defaulting to awaiting_payment.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function get_state( \WC_Order $order ): string {
		$state = self::get_string( $order, self::STATE );

		return '' !== $state ? $state : self::STATE_AWAITING_PAYMENT;
	}

	/**
	 * Set the payment state.
	 *
	 * @param \WC_Order $order Order.
	 * @param string    $state One of the STATE_* constants.
	 * @return void
	 */
	public static function set_state( \WC_Order $order, string $state ): void {
		$order->update_meta_data( self::STATE, $state );
	}
}
