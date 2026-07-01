<?php
/**
 * On-chain payment verification against free public RPC nodes.
 *
 * Given an order's locked requirement (network, pay-to address, minimum wei) and
 * the buyer's claim (a transaction hash and/or their sending wallet), this finds
 * and validates the matching native-ETH transfer and reports whether it has
 * enough confirmations.
 *
 * Two matching strategies:
 *
 *   1. Transaction hash (precise): fetch the exact tx + receipt and check the
 *      recipient, amount, sender (if given), success status, and confirmations.
 *      This is exact and cheap — one or two RPC calls.
 *
 *   2. Sender scan (fallback for buyers who can't find a tx hash): scan a bounded
 *      window of recent blocks from where the order started, looking for a
 *      native transfer to the pay-to address from the buyer's wallet for at
 *      least the required amount. Bounded per run so a free node is never
 *      hammered; the poller resumes from where it left off across runs.
 *
 * @package ShadowEth
 */

namespace ShadowEth;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless verifier. One instance per order verification.
 */
final class Verifier {

	/**
	 * Maximum blocks to scan per sender-scan run, to stay friendly to free nodes.
	 * The poller advances the cursor and resumes next run.
	 */
	private const MAX_BLOCKS_PER_SCAN = 40;

	/**
	 * The order being verified.
	 *
	 * @var \WC_Order
	 */
	private \WC_Order $order;

	/**
	 * The gateway (for RPC config + confirmation thresholds).
	 *
	 * @var Gateway
	 */
	private Gateway $gateway;

	/**
	 * The network slug the payment is on.
	 *
	 * @var string
	 */
	private string $network;

	/**
	 * The RPC client for the network.
	 *
	 * @var RpcClient
	 */
	private RpcClient $rpc;

	/**
	 * The asset being paid, or null if the order predates the asset field (then
	 * we treat it as native ETH for backward compatibility).
	 *
	 * @var array{id:string,kind:string,symbol:string,label:string,network:string,decimals:int,coingecko_id:string,contract:string}|null
	 */
	private ?array $asset;

	/**
	 * Build a verifier for an order.
	 *
	 * @param \WC_Order $order   The order.
	 * @param Gateway   $gateway The configured gateway.
	 */
	public function __construct( \WC_Order $order, Gateway $gateway ) {
		$this->order   = $order;
		$this->gateway = $gateway;
		$this->network = OrderMeta::get_string( $order, OrderMeta::NETWORK );
		$this->rpc     = RpcClient::for_network( $this->network, $gateway );

		$asset_id    = OrderMeta::get_string( $order, OrderMeta::ASSET );
		$this->asset = '' !== $asset_id ? Assets::get( $asset_id ) : null;
	}

	/**
	 * The number of decimals for the order's asset (defaults to ETH's 18).
	 */
	private function decimals(): int {
		return null !== $this->asset ? $this->asset['decimals'] : 18;
	}

	/**
	 * The asset's display symbol (defaults to ETH).
	 */
	private function symbol(): string {
		return null !== $this->asset ? $this->asset['symbol'] : 'ETH';
	}

	/**
	 * Whether the order is paid in an ERC-20 token (vs native ETH).
	 */
	private function is_erc20(): bool {
		return null !== $this->asset && Assets::KIND_ERC20 === $this->asset['kind'];
	}

	/**
	 * The token contract for an ERC-20 order (lower-case), or '' otherwise.
	 */
	private function token_contract(): string {
		return $this->is_erc20() ? strtolower( $this->asset['contract'] ) : '';
	}

	/**
	 * Run one verification attempt and return the result.
	 */
	public function verify(): VerificationResult {
		$pay_address = OrderMeta::get_string( $this->order, OrderMeta::PAY_ADDRESS );
		$min_wei     = $this->minimum_required_wei();
		$tx_hash     = OrderMeta::get_string( $this->order, OrderMeta::TX_HASH );
		$sender      = OrderMeta::get_string( $this->order, OrderMeta::SENDER );

		if ( ! Networks::is_supported( $this->network ) || '' === $pay_address ) {
			return VerificationResult::failed( __( 'This payment is missing its network configuration.', 'accept-crypto-for-woocommerce' ) );
		}

		// Fail closed: if the locked required amount is missing or zero, never
		// accept a payment (a zero minimum would pass any amount, including none).
		if ( '0' === $min_wei ) {
			Logger::error( 'Order ' . $this->order->get_id() . ' has no locked required amount; refusing to verify.' );

			return VerificationResult::failed( __( 'This order is missing its payment amount. Please contact us to complete it.', 'accept-crypto-for-woocommerce' ) );
		}

		try {
			// Prove the node is on the chain we think it is before trusting any tx
			// data from it. A wrong/forged-chain node is refused here.
			$this->rpc->assert_chain();

			if ( '' !== $tx_hash ) {
				return $this->verify_by_tx_hash( $tx_hash, $pay_address, $sender, $min_wei );
			}

			if ( '' !== $sender ) {
				return $this->verify_by_sender_scan( $sender, $pay_address, $min_wei );
			}
		} catch ( RpcException $e ) {
			Logger::warn( 'Verify RPC error for order ' . $this->order->get_id() . ': ' . $e->getMessage() );

			// A node hiccup is transient — stay pending so the poller retries.
			return VerificationResult::pending( __( 'Checking the network… we could not reach a node just now and will retry shortly.', 'accept-crypto-for-woocommerce' ) );
		}

		return VerificationResult::pending( __( 'Waiting for your payment details.', 'accept-crypto-for-woocommerce' ) );
	}

	/**
	 * Verify a specific transaction by hash.
	 *
	 * @param string $tx_hash     0x transaction hash.
	 * @param string $pay_address Required recipient.
	 * @param string $sender      Expected sender, or '' if not provided.
	 * @param string $min_wei     Minimum acceptable value in wei.
	 * @throws RpcException On node failure.
	 */
	private function verify_by_tx_hash( string $tx_hash, string $pay_address, string $sender, string $min_wei ): VerificationResult {
		// The sender is mandatory: it binds the payment to THIS buyer so nobody can
		// claim a stranger's on-chain payment by pasting its public hash. If it is
		// somehow absent, refuse rather than confirm on recipient + amount alone.
		if ( '' === $sender ) {
			return VerificationResult::failed( __( 'We need the wallet address you paid from to confirm this payment.', 'accept-crypto-for-woocommerce' ) );
		}

		$tx = $this->rpc->get_transaction( $tx_hash );

		if ( null === $tx ) {
			return VerificationResult::pending( __( 'Your transaction has not appeared on the network yet. This can take a moment after you send it.', 'accept-crypto-for-woocommerce' ) );
		}

		// Sender MUST match the wallet the buyer entered (native tx sender).
		$from = isset( $tx['from'] ) && is_string( $tx['from'] ) ? $tx['from'] : '';

		if ( ! Address::equals( $from, $sender ) ) {
			return VerificationResult::failed( __( 'That transaction was not sent from the wallet you entered. Please check and try again.', 'accept-crypto-for-woocommerce' ) );
		}

		if ( $this->is_erc20() ) {
			// For a token, the amount lives in a Transfer event in the receipt, not
			// in the tx's native value, and the tx's `to` is the token contract.
			// The receipt check (in finalise) validates recipient + amount from the
			// logs, so here we just confirm the tx is even to our token contract.
			$to = isset( $tx['to'] ) && is_string( $tx['to'] ) ? $tx['to'] : '';

			if ( ! Address::equals( $to, $this->token_contract() ) ) {
				return VerificationResult::failed( __( 'That transaction is not a payment of the requested token. Please check and try again.', 'accept-crypto-for-woocommerce' ) );
			}

			return $this->finalise_with_receipt( $tx_hash, $pay_address, $sender, $min_wei );
		}

		// Native ETH: recipient must be the store wallet, value must meet minimum.
		$to = isset( $tx['to'] ) && is_string( $tx['to'] ) ? $tx['to'] : '';

		if ( ! Address::equals( $to, $pay_address ) ) {
			return VerificationResult::failed( __( 'That transaction did not pay the store wallet. Please check the transaction and try again.', 'accept-crypto-for-woocommerce' ) );
		}

		$value_wei = isset( $tx['value'] ) && is_string( $tx['value'] ) ? Money::hex_to_dec( $tx['value'] ) : '0';

		if ( Money::compare( $value_wei, $min_wei ) < 0 ) {
			return $this->underpaid_result( $value_wei, $min_wei );
		}

		return $this->finalise_with_receipt( $tx_hash, $pay_address, $sender, $min_wei );
	}

	/**
	 * A standard "you underpaid" failure using the asset's symbol and decimals.
	 *
	 * @param string $paid_units     Amount actually paid, in base units.
	 * @param string $required_units Amount required, in base units.
	 */
	private function underpaid_result( string $paid_units, string $required_units ): VerificationResult {
		return VerificationResult::failed(
			sprintf(
				/* translators: 1: amount paid, 2: amount required, 3: asset symbol. */
				__( 'That payment was for %1$s %3$s but %2$s %3$s is required. If you underpaid, please send the difference in a new transaction.', 'accept-crypto-for-woocommerce' ),
				Money::from_base_units( $paid_units, $this->decimals() ),
				Money::from_base_units( $required_units, $this->decimals() ),
				$this->symbol()
			)
		);
	}

	/**
	 * Confirm a matched tx by reading its receipt and counting confirmations. For
	 * ERC-20 orders the recipient/amount are validated from the receipt's Transfer
	 * logs, which is why the pay address, sender, and minimum are passed through.
	 *
	 * @param string $tx_hash     0x transaction hash.
	 * @param string $pay_address The store wallet (ERC-20 log check).
	 * @param string $sender      The buyer's wallet (ERC-20 log check).
	 * @param string $min_wei     Minimum acceptable amount in base units (ERC-20).
	 * @throws RpcException On node failure.
	 */
	private function finalise_with_receipt( string $tx_hash, string $pay_address = '', string $sender = '', string $min_wei = '' ): VerificationResult {
		// Replay guard: a transaction already credited to a DIFFERENT order cannot
		// pay this one. This stops a buyer from settling multiple orders with a
		// single on-chain payment (via a re-submitted hash or a sender scan that
		// matches an older transfer). The authoritative, race-safe claim happens
		// at completion time; this is the early, friendly rejection.
		if ( ! TxRegistry::is_available_for( $this->network, $tx_hash, $this->order->get_id() ) ) {
			return VerificationResult::failed( __( 'That transaction has already been used to pay another order. Please make a new payment for this order.', 'accept-crypto-for-woocommerce' ) );
		}

		$receipt = $this->rpc->get_transaction_receipt( $tx_hash );

		if ( null === $receipt ) {
			return VerificationResult::pending( __( 'Your payment was found and is waiting to be mined into a block.', 'accept-crypto-for-woocommerce' ) );
		}

		// Require an EXPLICIT success status. On all supported (post-Byzantium)
		// chains a mined receipt carries status '0x1' (success) or '0x0'
		// (reverted). We only proceed on an explicit success; a reverted status
		// fails, and a missing/blank/unexpected status keeps us pending rather
		// than assuming success from a node that omitted it.
		$status_hex = isset( $receipt['status'] ) && is_string( $receipt['status'] ) ? strtolower( $receipt['status'] ) : '';
		$status     = self::hex_to_small_int( $status_hex );

		if ( 0 === $status ) {
			return VerificationResult::failed( __( 'That transaction failed on-chain (it was reverted), so no payment was received.', 'accept-crypto-for-woocommerce' ) );
		}

		if ( 1 !== $status ) {
			return VerificationResult::pending( __( 'Your payment is confirming. Please wait a moment.', 'accept-crypto-for-woocommerce' ) );
		}

		// For an ERC-20 token, the actual payment (recipient + amount) is proven by
		// a Transfer event in the receipt logs emitted by our token contract — the
		// native tx value is zero. Validate it here before counting confirmations.
		if ( $this->is_erc20() ) {
			$token_ok = $this->receipt_has_token_payment( $receipt, $pay_address, $sender, $min_wei );

			if ( $token_ok instanceof VerificationResult ) {
				return $token_ok;
			}
		}

		$block_hex = isset( $receipt['blockNumber'] ) && is_string( $receipt['blockNumber'] ) ? $receipt['blockNumber'] : '';
		$tx_block  = self::hex_to_small_int( $block_hex );

		if ( $tx_block <= 0 ) {
			return VerificationResult::pending( __( 'Your payment is confirming. Please wait a moment.', 'accept-crypto-for-woocommerce' ) );
		}

		$head     = $this->rpc->block_number();
		$confs    = max( 0, $head - $tx_block + 1 );
		$required = $this->gateway->get_confirmations( $this->network );

		if ( $confs < $required ) {
			return VerificationResult::pending(
				sprintf(
					/* translators: 1: current confirmations, 2: required confirmations. */
					__( 'Payment found — waiting for network confirmations (%1$d of %2$d).', 'accept-crypto-for-woocommerce' ),
					$confs,
					$required
				),
				$tx_hash,
				$confs
			);
		}

		return VerificationResult::confirmed( $tx_hash, $confs );
	}

	/**
	 * Validate that a receipt contains an ERC-20 Transfer of at least the required
	 * amount, emitted by OUR token contract, from the buyer's wallet to the store
	 * wallet. Returns null when a valid payment is present (proceed), or a failure
	 * VerificationResult when it is not.
	 *
	 * Matching on the token contract address (not the symbol) is what stops a
	 * worthless look-alike token from satisfying the order.
	 *
	 * @param array<string,mixed> $receipt     The transaction receipt.
	 * @param string              $pay_address The store wallet.
	 * @param string              $sender      The buyer's wallet.
	 * @param string              $min_units   Minimum acceptable amount in base units.
	 * @return VerificationResult|null
	 */
	private function receipt_has_token_payment( array $receipt, string $pay_address, string $sender, string $min_units ): ?VerificationResult {
		$logs     = isset( $receipt['logs'] ) && is_array( $receipt['logs'] ) ? $receipt['logs'] : array();
		$contract = $this->token_contract();
		$total    = '0';
		$found    = false;

		foreach ( $logs as $log ) {
			if ( ! is_array( $log ) ) {
				continue;
			}

			$transfer = Erc20::decode_transfer( $log );

			if ( null === $transfer ) {
				continue;
			}

			// Only a Transfer from OUR token contract, from the buyer, to the store.
			if ( ! Address::equals( $transfer['contract'], $contract )
				|| ! Address::equals( $transfer['from'], $sender )
				|| ! Address::equals( $transfer['to'], $pay_address ) ) {
				continue;
			}

			$total = Money::add_units( $total, $transfer['value'] );
			$found = true;
		}

		if ( ! $found ) {
			return VerificationResult::failed( __( 'That transaction did not include a token payment to the store from your wallet. Please check and try again.', 'accept-crypto-for-woocommerce' ) );
		}

		if ( Money::compare( $total, $min_units ) < 0 ) {
			return $this->underpaid_result( $total, $min_units );
		}

		return null;
	}

	/**
	 * Scan a bounded window of recent blocks for a matching native transfer from
	 * the buyer's wallet to the store wallet.
	 *
	 * @param string $sender      Buyer's sending wallet.
	 * @param string $pay_address Store wallet.
	 * @param string $min_wei     Minimum acceptable value in wei.
	 * @throws RpcException On node failure.
	 */
	private function verify_by_sender_scan( string $sender, string $pay_address, string $min_wei ): VerificationResult {
		$head   = $this->rpc->block_number();
		$cursor = OrderMeta::get_int( $this->order, OrderMeta::START_BLOCK );

		if ( $cursor <= 0 ) {
			$cursor = $head;
		}

		// If the node reports a head behind our cursor (a lagging or hostile node,
		// or a transient reorg), do NOT scan and do NOT move the cursor backward —
		// just wait and retry. The cursor only ever advances.
		if ( $head < $cursor ) {
			return VerificationResult::pending( __( 'Looking for your payment on the network. Please wait a moment.', 'accept-crypto-for-woocommerce' ) );
		}

		// Scan forward from the cursor, capped per run.
		$from = $cursor;
		$to   = min( $head, $from + self::MAX_BLOCKS_PER_SCAN - 1 );

		// ERC-20: use an indexed log query (efficient and exact) instead of walking
		// every transaction in every block.
		if ( $this->is_erc20() ) {
			return $this->scan_token_logs( $sender, $pay_address, $min_wei, $from, $to, $cursor );
		}

		for ( $number = $from; $number <= $to; $number++ ) {
			$block = $this->rpc->get_block_by_number( $number, true );

			if ( null === $block || ! isset( $block['transactions'] ) || ! is_array( $block['transactions'] ) ) {
				continue;
			}

			foreach ( $block['transactions'] as $tx ) {
				if ( ! is_array( $tx ) ) {
					continue;
				}

				$to_addr   = isset( $tx['to'] ) && is_string( $tx['to'] ) ? $tx['to'] : '';
				$from_addr = isset( $tx['from'] ) && is_string( $tx['from'] ) ? $tx['from'] : '';

				if ( ! Address::equals( $to_addr, $pay_address ) || ! Address::equals( $from_addr, $sender ) ) {
					continue;
				}

				$value_wei = isset( $tx['value'] ) && is_string( $tx['value'] ) ? Money::hex_to_dec( $tx['value'] ) : '0';

				if ( Money::compare( $value_wei, $min_wei ) < 0 ) {
					continue;
				}

				$hash = isset( $tx['hash'] ) && is_string( $tx['hash'] ) ? $tx['hash'] : '';

				if ( '' === $hash ) {
					continue;
				}

				// Skip a transfer already credited to another order so the scan
				// keeps looking for this buyer's own, fresh payment in this window
				// rather than latching onto (and then rejecting) a consumed one.
				if ( ! TxRegistry::is_available_for( $this->network, $hash, $this->order->get_id() ) ) {
					continue;
				}

				// Found a matching, unconsumed payment — record the hash and confirm it.
				$this->order->update_meta_data( OrderMeta::TX_HASH, Address::normalize_tx_hash( $hash ) );
				$this->order->save();

				return $this->finalise_with_receipt( $hash );
			}
		}

		// Advance the cursor so the next run resumes where we stopped. Guard it to
		// only ever move forward, so a bad head reading can never rewind progress.
		$next = max( $cursor, $to + 1 );
		$this->order->update_meta_data( OrderMeta::START_BLOCK, (string) $next );
		$this->order->save();

		return VerificationResult::pending( __( 'Looking for your payment on the network. If you have paid, this usually clears within a few minutes.', 'accept-crypto-for-woocommerce' ) );
	}

	/**
	 * Scan for an ERC-20 payment via an indexed Transfer-log query: our token
	 * contract, from the buyer, to the store, over the cursor's block window.
	 *
	 * @param string $sender      Buyer's wallet.
	 * @param string $pay_address Store wallet.
	 * @param string $min_units   Minimum acceptable amount in base units.
	 * @param int    $from        From block (inclusive).
	 * @param int    $to          To block (inclusive).
	 * @param int    $cursor      Current cursor (for forward-only advance).
	 * @throws RpcException On node failure.
	 */
	private function scan_token_logs( string $sender, string $pay_address, string $min_units, int $from, int $to, int $cursor ): VerificationResult {
		$from_topic = Erc20::address_topic( $sender );
		$to_topic   = Erc20::address_topic( $pay_address );

		if ( '' === $from_topic || '' === $to_topic ) {
			return VerificationResult::failed( __( 'This order is missing valid payment addresses.', 'accept-crypto-for-woocommerce' ) );
		}

		$logs = $this->rpc->get_logs(
			$from,
			$to,
			$this->token_contract(),
			array( Erc20::TRANSFER_TOPIC, $from_topic, $to_topic )
		);

		foreach ( $logs as $log ) {
			$transfer = Erc20::decode_transfer( $log );

			if ( null === $transfer || Money::compare( $transfer['value'], $min_units ) < 0 ) {
				continue;
			}

			$hash = $transfer['tx_hash'];

			if ( '' === $hash || ! TxRegistry::is_available_for( $this->network, $hash, $this->order->get_id() ) ) {
				continue;
			}

			$this->order->update_meta_data( OrderMeta::TX_HASH, Address::normalize_tx_hash( $hash ) );
			$this->order->save();

			return $this->finalise_with_receipt( $hash, $pay_address, $sender, $min_units );
		}

		// No match in this window — advance the cursor forward only.
		$next = max( $cursor, $to + 1 );
		$this->order->update_meta_data( OrderMeta::START_BLOCK, (string) $next );
		$this->order->save();

		return VerificationResult::pending( __( 'Looking for your payment on the network. If you have paid, this usually clears within a few minutes.', 'accept-crypto-for-woocommerce' ) );
	}

	/**
	 * The minimum acceptable amount in wei after applying the underpayment
	 * tolerance to the locked required amount.
	 */
	private function minimum_required_wei(): string {
		$required  = OrderMeta::get_string( $this->order, OrderMeta::AMOUNT_WEI );
		$tolerance = $this->gateway->get_tolerance_bps();

		if ( '' === $required || '0' === $required ) {
			return '0';
		}

		if ( $tolerance <= 0 ) {
			return $required;
		}

		// minimum = required * (10000 - tolerance_bps) / 10000.
		return Money::mul_div( $required, 10000 - $tolerance, 10000 );
	}

	/**
	 * Parse a small 0x-hex quantity (a block number or a receipt status) into an
	 * int, safely. Returns -1 for malformed input or a value large enough that it
	 * would overflow PHP's int into a lossy float — neither a real block number
	 * nor a status can legitimately reach that size, so -1 signals "unusable" and
	 * callers stay pending rather than trusting a bad value.
	 *
	 * @param string $hex 0x-prefixed hex string (any case).
	 * @return int Parsed value, or -1 if malformed/oversized.
	 */
	private static function hex_to_small_int( string $hex ): int {
		$hex = strtolower( trim( $hex ) );

		if ( 0 === strpos( $hex, '0x' ) ) {
			$hex = substr( $hex, 2 );
		}

		if ( '' === $hex || 1 !== preg_match( '/^[0-9a-f]+$/', $hex ) ) {
			return -1;
		}

		// 15 hex digits (60 bits) is far above any real block height yet safely
		// inside PHP_INT_MAX on a 64-bit host; reject anything longer as bogus.
		if ( strlen( ltrim( $hex, '0' ) ) > 15 ) {
			return -1;
		}

		return (int) hexdec( $hex );
	}

	/**
	 * Capture the current chain head as the scan start block for an order. Called
	 * when the buyer commits to a network, so the sender scan never has to walk
	 * the whole chain.
	 *
	 * @param \WC_Order $order   The order.
	 * @param Gateway   $gateway The gateway.
	 * @param string    $network Network slug.
	 * @return void
	 */
	public static function capture_start_block( \WC_Order $order, Gateway $gateway, string $network ): void {
		try {
			$rpc  = RpcClient::for_network( $network, $gateway );
			$head = $rpc->block_number();

			// Start a couple of blocks back to tolerate a buyer who paid moments
			// before committing to the network.
			$order->update_meta_data( OrderMeta::START_BLOCK, (string) max( 0, $head - 3 ) );
		} catch ( RpcException $e ) {
			Logger::warn( 'Could not capture start block for order ' . $order->get_id() . ': ' . $e->getMessage() );
		}
	}
}
