<?php
/**
 * On-chain Bitcoin payment verification via free Esplora explorers.
 *
 * The Bitcoin analogue of the EVM Verifier. Given an order's locked requirement
 * (pay-to address, minimum satoshis, confirmations) and the buyer's claim (a
 * txid and their sending address), it finds the matching payment and reports
 * whether it has enough confirmations.
 *
 * A Bitcoin transaction has no single "from"; the sending address is taken from
 * the transaction's inputs (their previous outputs' addresses). We require the
 * buyer's stated address to appear among the inputs so a payment is bound to
 * this buyer, mirroring the EVM sender check.
 *
 * @package ShadowEth
 */

namespace ShadowEth;

defined( 'ABSPATH' ) || exit;

/**
 * Bitcoin payment verifier.
 */
final class BtcVerifier {

	/**
	 * The pseudo-network slug used for Bitcoin in the replay registry and meta.
	 */
	public const NETWORK = 'bitcoin';

	/**
	 * The order being verified.
	 *
	 * @var \WC_Order
	 */
	private \WC_Order $order;

	/**
	 * The gateway (for confirmations + explorer override).
	 *
	 * @var Gateway
	 */
	private Gateway $gateway;

	/**
	 * The explorer client.
	 *
	 * @var BtcExplorer
	 */
	private BtcExplorer $explorer;

	/**
	 * Build a verifier for an order.
	 *
	 * @param \WC_Order $order   The order.
	 * @param Gateway   $gateway The configured gateway.
	 */
	public function __construct( \WC_Order $order, Gateway $gateway ) {
		$this->order    = $order;
		$this->gateway  = $gateway;
		$this->explorer = BtcExplorer::create( $gateway->get_btc_explorer_url() );
	}

	/**
	 * Run one verification attempt.
	 */
	public function verify(): VerificationResult {
		$pay_address = OrderMeta::get_string( $this->order, OrderMeta::PAY_ADDRESS );
		$min_sats    = $this->minimum_required_sats();
		$txid        = OrderMeta::get_string( $this->order, OrderMeta::TX_HASH );
		$sender      = OrderMeta::get_string( $this->order, OrderMeta::SENDER );

		if ( '' === $pay_address || ! BtcAddress::is_valid( $pay_address ) ) {
			return VerificationResult::failed( __( 'This payment is missing its Bitcoin address.', 'shadowpay-crypto-for-woocommerce' ) );
		}

		if ( '0' === $min_sats ) {
			Logger::error( 'BTC order ' . $this->order->get_id() . ' has no locked amount; refusing to verify.' );

			return VerificationResult::failed( __( 'This order is missing its payment amount. Please contact us to complete it.', 'shadowpay-crypto-for-woocommerce' ) );
		}

		if ( '' === $sender ) {
			return VerificationResult::failed( __( 'We need the wallet address you paid from to confirm this payment.', 'shadowpay-crypto-for-woocommerce' ) );
		}

		try {
			if ( '' !== $txid ) {
				return $this->verify_by_txid( $txid, $pay_address, $sender, $min_sats );
			}

			return $this->verify_by_sender_scan( $sender, $pay_address, $min_sats );
		} catch ( RpcException $e ) {
			Logger::warn( 'BTC verify error for order ' . $this->order->get_id() . ': ' . $e->getMessage() );

			return VerificationResult::pending( __( 'Checking the Bitcoin network… we could not reach an explorer just now and will retry shortly.', 'shadowpay-crypto-for-woocommerce' ) );
		}
	}

	/**
	 * Verify a specific transaction by id.
	 *
	 * @param string $txid        Transaction id.
	 * @param string $pay_address Store address.
	 * @param string $sender      Buyer's address (must be an input).
	 * @param string $min_sats    Minimum satoshis required.
	 * @throws RpcException On explorer failure.
	 */
	private function verify_by_txid( string $txid, string $pay_address, string $sender, string $min_sats ): VerificationResult {
		if ( ! TxRegistry::is_available_for( self::NETWORK, $txid, $this->order->get_id() ) ) {
			return VerificationResult::failed( __( 'That transaction has already been used to pay another order. Please make a new payment for this order.', 'shadowpay-crypto-for-woocommerce' ) );
		}

		$tx = $this->explorer->get_tx( $txid );

		if ( null === $tx ) {
			return VerificationResult::pending( __( 'Your transaction has not appeared on the network yet. This can take a moment after you send it.', 'shadowpay-crypto-for-woocommerce' ) );
		}

		return $this->evaluate_tx( $tx, $txid, $pay_address, $sender, $min_sats );
	}

	/**
	 * Scan the store address's recent transactions for a matching payment from the
	 * buyer's address.
	 *
	 * @param string $sender      Buyer's address.
	 * @param string $pay_address Store address.
	 * @param string $min_sats    Minimum satoshis required.
	 * @throws RpcException On explorer failure.
	 */
	private function verify_by_sender_scan( string $sender, string $pay_address, string $min_sats ): VerificationResult {
		$txs = $this->explorer->get_address_txs( $pay_address );

		foreach ( $txs as $tx ) {
			$txid = isset( $tx['txid'] ) && is_string( $tx['txid'] ) ? strtolower( $tx['txid'] ) : '';

			if ( '' === $txid || ! TxRegistry::is_available_for( self::NETWORK, $txid, $this->order->get_id() ) ) {
				continue;
			}

			$result = $this->evaluate_tx( $tx, $txid, $pay_address, $sender, $min_sats );

			// A confirmed or still-confirming match ends the scan; a definitive
			// mismatch (wrong sender/amount) just moves on to the next candidate.
			if ( $result->is_confirmed() || $result->is_pending() ) {
				if ( '' === OrderMeta::get_string( $this->order, OrderMeta::TX_HASH ) ) {
					$this->order->update_meta_data( OrderMeta::TX_HASH, $txid );
					$this->order->save();
				}

				return $result;
			}
		}

		return VerificationResult::pending( __( 'Looking for your payment on the Bitcoin network. If you have paid, this usually clears within a few minutes.', 'shadowpay-crypto-for-woocommerce' ) );
	}

	/**
	 * Evaluate a decoded transaction: it must pay the store address at least the
	 * required amount, include the buyer's address among its inputs, and have
	 * enough confirmations.
	 *
	 * @param array<string,mixed> $tx          Decoded Esplora transaction.
	 * @param string              $txid        Transaction id.
	 * @param string              $pay_address Store address.
	 * @param string              $sender      Buyer's address.
	 * @param string              $min_sats    Minimum satoshis.
	 * @throws RpcException On explorer failure.
	 */
	private function evaluate_tx( array $tx, string $txid, string $pay_address, string $sender, string $min_sats ): VerificationResult {
		// Sum outputs paying the store address.
		$paid_sats = '0';
		$vout      = isset( $tx['vout'] ) && is_array( $tx['vout'] ) ? $tx['vout'] : array();

		foreach ( $vout as $out ) {
			if ( ! is_array( $out ) ) {
				continue;
			}

			$addr = isset( $out['scriptpubkey_address'] ) && is_string( $out['scriptpubkey_address'] ) ? $out['scriptpubkey_address'] : '';

			if ( BtcAddress::equals( $addr, $pay_address ) ) {
				$value     = isset( $out['value'] ) ? (string) $out['value'] : '0';
				$paid_sats = Money::add_units( $paid_sats, $value );
			}
		}

		if ( '0' === $paid_sats ) {
			return VerificationResult::failed( __( 'That transaction did not pay the store address. Please check and try again.', 'shadowpay-crypto-for-woocommerce' ) );
		}

		// The buyer's address must appear among the inputs (binds it to this buyer).
		if ( ! $this->inputs_include( $tx, $sender ) ) {
			return VerificationResult::failed( __( 'That transaction was not sent from the wallet you entered. Please check and try again.', 'shadowpay-crypto-for-woocommerce' ) );
		}

		if ( Money::compare( $paid_sats, $min_sats ) < 0 ) {
			return VerificationResult::failed(
				sprintf(
					/* translators: 1: amount paid in BTC, 2: amount required in BTC. */
					__( 'That payment was for %1$s BTC but %2$s BTC is required. If you underpaid, please send the difference in a new transaction.', 'shadowpay-crypto-for-woocommerce' ),
					Money::from_base_units( $paid_sats, 8 ),
					Money::from_base_units( $min_sats, 8 )
				)
			);
		}

		// Confirmations.
		$status    = isset( $tx['status'] ) && is_array( $tx['status'] ) ? $tx['status'] : array();
		$confirmed = isset( $status['confirmed'] ) && true === $status['confirmed'];

		if ( ! $confirmed ) {
			return VerificationResult::pending( __( 'Payment found — waiting for it to be included in a block.', 'shadowpay-crypto-for-woocommerce' ) );
		}

		$block_height = isset( $status['block_height'] ) ? (int) $status['block_height'] : 0;

		if ( $block_height <= 0 ) {
			return VerificationResult::pending( __( 'Payment found — confirming. Please wait a moment.', 'shadowpay-crypto-for-woocommerce' ) );
		}

		// Reject a payment that was already confirmed BEFORE this order existed. A
		// Bitcoin "sender" (an input address) is public data, so without this an
		// attacker could point at an old third-party payment to the store that
		// happens to include some address as an input. The start height (captured
		// at submission, a few blocks back for safety) bounds matches to payments
		// made for THIS order. Unconfirmed/newer payments are unaffected.
		$start_height = OrderMeta::get_int( $this->order, OrderMeta::START_BLOCK );

		if ( $start_height > 0 && $block_height < $start_height ) {
			return VerificationResult::failed( __( 'That transaction was confirmed before this order was placed, so it cannot pay for it. Please make a new payment.', 'shadowpay-crypto-for-woocommerce' ) );
		}

		$tip      = $this->explorer->tip_height();
		$confs    = max( 0, $tip - $block_height + 1 );
		$required = $this->gateway->get_btc_confirmations();

		if ( $confs < $required ) {
			return VerificationResult::pending(
				sprintf(
					/* translators: 1: current confirmations, 2: required confirmations. */
					__( 'Payment found — waiting for network confirmations (%1$d of %2$d).', 'shadowpay-crypto-for-woocommerce' ),
					$confs,
					$required
				),
				$txid,
				$confs
			);
		}

		return VerificationResult::confirmed( $txid, $confs );
	}

	/**
	 * Whether the buyer's address appears among a transaction's inputs.
	 *
	 * @param array<string,mixed> $tx     Decoded transaction.
	 * @param string              $sender Buyer's address.
	 */
	private function inputs_include( array $tx, string $sender ): bool {
		$vin = isset( $tx['vin'] ) && is_array( $tx['vin'] ) ? $tx['vin'] : array();

		foreach ( $vin as $in ) {
			if ( ! is_array( $in ) || ! isset( $in['prevout'] ) || ! is_array( $in['prevout'] ) ) {
				continue;
			}

			$addr = isset( $in['prevout']['scriptpubkey_address'] ) && is_string( $in['prevout']['scriptpubkey_address'] )
				? $in['prevout']['scriptpubkey_address']
				: '';

			if ( BtcAddress::equals( $addr, $sender ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The minimum acceptable amount in satoshis after applying the underpayment
	 * tolerance to the locked required amount.
	 */
	private function minimum_required_sats(): string {
		$required  = OrderMeta::get_string( $this->order, OrderMeta::AMOUNT_WEI );
		$tolerance = $this->gateway->get_tolerance_bps();

		if ( '' === $required || '0' === $required ) {
			return '0';
		}

		if ( $tolerance <= 0 ) {
			return $required;
		}

		return Money::mul_div( $required, 10000 - $tolerance, 10000 );
	}

	/**
	 * Capture the current Bitcoin tip height as the order's start height, so a
	 * payment confirmed before this point cannot be claimed for the order. Started
	 * a few blocks back to tolerate a buyer who paid moments before submitting.
	 *
	 * @param \WC_Order $order   The order.
	 * @param Gateway   $gateway The gateway (for the explorer override).
	 * @return void
	 */
	public static function capture_start_height( \WC_Order $order, Gateway $gateway ): void {
		try {
			$explorer = BtcExplorer::create( $gateway->get_btc_explorer_url() );
			$tip      = $explorer->tip_height();

			$order->update_meta_data( OrderMeta::START_BLOCK, (string) max( 0, $tip - 3 ) );
		} catch ( RpcException $e ) {
			Logger::warn( 'Could not capture BTC start height for order ' . $order->get_id() . ': ' . $e->getMessage() );
		}
	}
}
