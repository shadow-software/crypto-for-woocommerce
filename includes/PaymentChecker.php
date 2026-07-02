<?php
/**
 * Background poller that confirms submitted payments on-chain.
 *
 * When a buyer submits their payment, an order is queued for checking. This
 * class runs the Verifier on a schedule and drives the order's state machine:
 * complete it once confirmed, fail it on a definitive problem or when the
 * payment window elapses, and otherwise re-queue another check.
 *
 * Scheduling prefers WooCommerce's bundled Action Scheduler (reliable, DB-backed)
 * and falls back to a single-event WP-Cron chain if it is unavailable.
 *
 * @package ShadowEth
 */

namespace ShadowEth;

defined( 'ABSPATH' ) || exit;

/**
 * Payment polling orchestrator.
 */
final class PaymentChecker {

	/**
	 * Hook name for a single scheduled check of one order.
	 */
	public const CHECK_HOOK = 'shadow_eth_check_payment';

	/**
	 * Seconds between checks.
	 */
	private const INTERVAL = 60;

	/**
	 * Register the check callback.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( self::CHECK_HOOK, array( $this, 'run_check' ), 10, 1 );
	}

	/**
	 * Queue the first check for an order after the buyer submits their payment.
	 *
	 * @param \WC_Order $order The order.
	 * @return void
	 */
	public function schedule( \WC_Order $order ): void {
		$this->enqueue( $order->get_id(), self::INTERVAL );
	}

	/**
	 * Schedule a single check of one order after $delay seconds.
	 *
	 * @param int $order_id Order id.
	 * @param int $delay    Delay in seconds.
	 * @return void
	 */
	private function enqueue( int $order_id, int $delay ): void {
		$args = array( $order_id );

		if ( function_exists( 'as_schedule_single_action' ) && function_exists( 'as_has_scheduled_action' ) ) {
			if ( ! as_has_scheduled_action( self::CHECK_HOOK, $args, 'shadow-eth' ) ) {
				as_schedule_single_action( time() + $delay, self::CHECK_HOOK, $args, 'shadow-eth' );
			}

			return;
		}

		if ( ! wp_next_scheduled( self::CHECK_HOOK, $args ) ) {
			wp_schedule_single_event( time() + $delay, self::CHECK_HOOK, $args );
		}
	}

	/**
	 * Run one verification pass for an order and advance its state.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	public function run_check( $order_id ): void {
		$order_id = (int) $order_id;
		$order    = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// Only orders actively verifying are worth polling.
		if ( OrderMeta::STATE_VERIFYING !== OrderMeta::get_state( $order ) ) {
			return;
		}

		// Already paid/handled by another path? Stop.
		if ( $order->is_paid() || $order->has_status( array( 'completed', 'processing', 'cancelled', 'refunded' ) ) ) {
			OrderMeta::set_state( $order, OrderMeta::STATE_CONFIRMED );
			$order->save();

			return;
		}

		$gateway = Plugin::instance()->get_gateway();

		if ( ! $gateway instanceof Gateway ) {
			return;
		}

		// Record the attempt + timestamp.
		$attempts = OrderMeta::get_int( $order, OrderMeta::ATTEMPTS ) + 1;
		$order->update_meta_data( OrderMeta::ATTEMPTS, (string) $attempts );
		$order->update_meta_data( OrderMeta::LAST_CHECK, (string) time() );

		// Dispatch to the right verifier by asset family: Bitcoin via the explorer
		// verifier, everything EVM (native ETH + ERC-20 tokens) via the RPC verifier.
		$asset  = Assets::get( OrderMeta::get_string( $order, OrderMeta::ASSET ) );
		$is_btc = null !== $asset && Assets::KIND_BTC === $asset['kind'];

		$result = $is_btc
			? ( new BtcVerifier( $order, $gateway ) )->verify()
			: ( new Verifier( $order, $gateway ) )->verify();

		if ( $result->is_confirmed() ) {
			$this->complete_order( $order, $result );

			return;
		}

		if ( $result->is_failed() ) {
			$this->fail_order( $order, $result->message() );

			return;
		}

		// Still pending. Give up if the payment window has elapsed.
		if ( $this->window_elapsed( $order, $gateway ) ) {
			$this->fail_order( $order, __( 'We did not detect a matching payment within the payment window.', 'shadowpay-crypto-for-woocommerce' ) );

			return;
		}

		$order->save();
		$this->enqueue( $order_id, self::INTERVAL );
	}

	/**
	 * Mark the order paid once its payment is confirmed on-chain.
	 *
	 * @param \WC_Order          $order  The order.
	 * @param VerificationResult $result The confirmed result.
	 * @return void
	 */
	private function complete_order( \WC_Order $order, VerificationResult $result ): void {
		$network = OrderMeta::get_string( $order, OrderMeta::NETWORK );

		// Authoritative, race-safe replay guard: atomically claim this transaction
		// for this order. If another order already owns it (e.g. two orders raced
		// to confirm the same payment), refuse to complete rather than credit one
		// payment twice.
		if ( ! TxRegistry::claim( $network, $result->tx_hash(), $order->get_id() ) ) {
			$owner = TxRegistry::claimed_by( $network, $result->tx_hash() );
			Logger::warn(
				sprintf(
					'Order %d not completed: transaction %s is already credited to order %d.',
					$order->get_id(),
					$result->tx_hash(),
					$owner
				)
			);
			$this->fail_order( $order, __( 'This transaction has already been used to pay another order.', 'shadowpay-crypto-for-woocommerce' ) );

			return;
		}

		OrderMeta::set_state( $order, OrderMeta::STATE_CONFIRMED );
		$order->update_meta_data( OrderMeta::CONFIRMED_TX, $result->tx_hash() );

		$asset    = Assets::get( OrderMeta::get_string( $order, OrderMeta::ASSET ) );
		$symbol   = null !== $asset ? $asset['symbol'] : 'ETH';
		$explorer = ( null !== $asset && Assets::KIND_BTC === $asset['kind'] )
			? 'https://mempool.space/tx/' . $result->tx_hash()
			: Networks::explorer_tx_url( $network, $result->tx_hash() );

		$network_obj = Networks::get( $network );
		$net_name    = null !== $network_obj ? $network_obj['name'] : ( 'bitcoin' === $network ? __( 'Bitcoin', 'shadowpay-crypto-for-woocommerce' ) : $network );

		$note = sprintf(
			/* translators: 1: asset symbol, 2: network name, 3: confirmations, 4: transaction hash. */
			__( 'Accept Crypto: %1$s payment confirmed on %2$s with %3$d confirmations. Tx: %4$s', 'shadowpay-crypto-for-woocommerce' ),
			$symbol,
			$net_name,
			$result->confirmations(),
			$result->tx_hash()
		);

		if ( '' !== $explorer ) {
			$note .= ' (' . $explorer . ')';
		}

		$order->add_order_note( $note );

		// payment_complete() sets the transaction id, moves the order to
		// processing/completed per WooCommerce rules, and reduces stock.
		$order->payment_complete( $result->tx_hash() );
		$order->save();

		Logger::info( sprintf( 'Order %d completed by on-chain payment %s.', $order->get_id(), $result->tx_hash() ) );
	}

	/**
	 * Fail the order and tell the buyer why.
	 *
	 * @param \WC_Order $order   The order.
	 * @param string    $message Customer-safe reason.
	 * @return void
	 */
	private function fail_order( \WC_Order $order, string $message ): void {
		OrderMeta::set_state( $order, OrderMeta::STATE_FAILED );
		$order->update_status(
			'failed',
			sprintf(
				/* translators: %s: reason the payment failed. */
				__( 'Accept Crypto: %s', 'shadowpay-crypto-for-woocommerce' ),
				$message
			)
		);
		$order->save();
	}

	/**
	 * Whether the configured payment window has elapsed since the buyer submitted.
	 *
	 * @param \WC_Order $order   The order.
	 * @param Gateway   $gateway The gateway.
	 */
	private function window_elapsed( \WC_Order $order, Gateway $gateway ): bool {
		$submitted = OrderMeta::get_int( $order, OrderMeta::SUBMITTED );

		if ( $submitted <= 0 ) {
			return false;
		}

		$deadline = $submitted + ( $gateway->get_window_minutes() * MINUTE_IN_SECONDS );

		return time() > $deadline;
	}
}
