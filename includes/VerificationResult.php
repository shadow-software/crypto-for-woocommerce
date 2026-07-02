<?php
/**
 * The immutable outcome of one on-chain verification attempt.
 *
 * @package ShadowEth
 */

namespace ShadowEth;

defined( 'ABSPATH' ) || exit;

/**
 * Value object returned by Verifier. Not "confirmed" ≠ "failed": a pending
 * result means keep polling; only is_failed() means give up.
 */
final class VerificationResult {

	public const STATUS_CONFIRMED = 'confirmed';
	public const STATUS_PENDING   = 'pending';
	public const STATUS_FAILED    = 'failed';

	/**
	 * One of the STATUS_* constants.
	 *
	 * @var string
	 */
	private string $status;

	/**
	 * Human, customer-safe explanation of the current state.
	 *
	 * @var string
	 */
	private string $message;

	/**
	 * The matched transaction hash once found, or '' otherwise.
	 *
	 * @var string
	 */
	private string $tx_hash;

	/**
	 * Confirmations observed for the matched tx, or 0.
	 *
	 * @var int
	 */
	private int $confirmations;

	/**
	 * Construct a result.
	 *
	 * @param string $status        STATUS_* constant.
	 * @param string $message       Customer-safe message.
	 * @param string $tx_hash       Matched tx hash, or ''.
	 * @param int    $confirmations Confirmations observed.
	 */
	private function __construct( string $status, string $message, string $tx_hash = '', int $confirmations = 0 ) {
		$this->status        = $status;
		$this->message       = $message;
		$this->tx_hash       = $tx_hash;
		$this->confirmations = $confirmations;
	}

	/**
	 * A confirmed payment.
	 *
	 * @param string $tx_hash       The confirmed transaction hash.
	 * @param int    $confirmations Confirmations observed.
	 */
	public static function confirmed( string $tx_hash, int $confirmations ): self {
		return new self(
			self::STATUS_CONFIRMED,
			__( 'Payment confirmed on-chain.', 'shadow-software-crypto-for-woocommerce' ),
			$tx_hash,
			$confirmations
		);
	}

	/**
	 * Still waiting (not found yet, or not enough confirmations). Keep polling.
	 *
	 * @param string $message       Customer-safe message.
	 * @param string $tx_hash       Matched tx hash if any, else ''.
	 * @param int    $confirmations Confirmations observed if any.
	 */
	public static function pending( string $message, string $tx_hash = '', int $confirmations = 0 ): self {
		return new self( self::STATUS_PENDING, $message, $tx_hash, $confirmations );
	}

	/**
	 * A definitive failure (wrong amount, reverted tx, wrong recipient). Stop.
	 *
	 * @param string $message Customer-safe message.
	 */
	public static function failed( string $message ): self {
		return new self( self::STATUS_FAILED, $message );
	}

	/**
	 * Whether the payment is confirmed.
	 */
	public function is_confirmed(): bool {
		return self::STATUS_CONFIRMED === $this->status;
	}

	/**
	 * Whether verification has definitively failed.
	 */
	public function is_failed(): bool {
		return self::STATUS_FAILED === $this->status;
	}

	/**
	 * Whether we should keep polling.
	 */
	public function is_pending(): bool {
		return self::STATUS_PENDING === $this->status;
	}

	/**
	 * The status string.
	 */
	public function status(): string {
		return $this->status;
	}

	/**
	 * The customer-safe message.
	 */
	public function message(): string {
		return $this->message;
	}

	/**
	 * The matched tx hash, or ''.
	 */
	public function tx_hash(): string {
		return $this->tx_hash;
	}

	/**
	 * Confirmations observed.
	 */
	public function confirmations(): int {
		return $this->confirmations;
	}
}
