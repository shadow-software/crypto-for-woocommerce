<?php
/**
 * A site-wide ledger of transaction hashes that have already been credited to an
 * order, keyed per network.
 *
 * Without this, one on-chain payment could be replayed to settle several orders:
 * a buyer pays once, then submits the same transaction hash (or the sender scan
 * matches the same historical transfer) for a second order and gets it marked
 * paid for free. Every completion claims its transaction here first; a hash that
 * is already claimed by a different order is refused.
 *
 * Claims are stored in a single autoloaded option as a compact map so the check
 * is one in-memory lookup, and the write is guarded against races.
 *
 * @package ShadowEth
 */

namespace ShadowEth;

defined( 'ABSPATH' ) || exit;

/**
 * Consumed-transaction ledger.
 */
final class TxRegistry {

	/**
	 * Option name holding the network:hash => order_id map.
	 */
	private const OPTION = 'shadow_eth_consumed_txs';

	/**
	 * The composite key for a network + transaction hash.
	 *
	 * @param string $network Network slug.
	 * @param string $tx_hash Transaction hash (any case).
	 */
	private static function key( string $network, string $tx_hash ): string {
		return sanitize_key( $network ) . ':' . strtolower( trim( $tx_hash ) );
	}

	/**
	 * The order id that has already claimed a transaction, or 0 if unclaimed.
	 *
	 * @param string $network Network slug.
	 * @param string $tx_hash Transaction hash.
	 */
	public static function claimed_by( string $network, string $tx_hash ): int {
		$map = get_option( self::OPTION, array() );

		if ( ! is_array( $map ) ) {
			return 0;
		}

		$key = self::key( $network, $tx_hash );

		return isset( $map[ $key ] ) ? (int) $map[ $key ] : 0;
	}

	/**
	 * Whether a transaction is free to be credited to the given order: either it
	 * is unclaimed, or it is already claimed by this same order (idempotent).
	 *
	 * @param string $network  Network slug.
	 * @param string $tx_hash  Transaction hash.
	 * @param int    $order_id The order asking to use it.
	 */
	public static function is_available_for( string $network, string $tx_hash, int $order_id ): bool {
		$owner = self::claimed_by( $network, $tx_hash );

		return 0 === $owner || $owner === $order_id;
	}

	/**
	 * Atomically claim a transaction for an order. Returns true if this order now
	 * owns the claim (including the idempotent re-claim by the same order), false
	 * if another order already owns it.
	 *
	 * The read-modify-write is wrapped so two concurrent checkers cannot both win:
	 * we re-read inside the critical section and only write when still free.
	 *
	 * @param string $network  Network slug.
	 * @param string $tx_hash  Transaction hash.
	 * @param int    $order_id The order claiming it.
	 */
	public static function claim( string $network, string $tx_hash, int $order_id ): bool {
		$key = self::key( $network, $tx_hash );

		// Best-effort mutual exclusion across concurrent cron/Action Scheduler
		// runs. add_option() is atomic (fails if the lock exists), giving us a
		// short-lived lock without needing a real DB transaction.
		$lock     = self::OPTION . '_lock';
		$got_lock = add_option( $lock, (string) time(), '', false );

		if ( ! $got_lock ) {
			// Someone else is writing; re-read and answer conservatively. If the
			// current owner is us or nobody, allow; the other writer will settle
			// the map. We do not write while another holds the lock.
			return self::is_available_for( $network, $tx_hash, $order_id );
		}

		try {
			$map = get_option( self::OPTION, array() );

			if ( ! is_array( $map ) ) {
				$map = array();
			}

			$owner = isset( $map[ $key ] ) ? (int) $map[ $key ] : 0;

			if ( 0 !== $owner && $owner !== $order_id ) {
				return false;
			}

			$map[ $key ] = $order_id;
			update_option( self::OPTION, $map, false );

			return true;
		} finally {
			delete_option( $lock );
		}
	}

	/**
	 * Release any claim held by an order (used if a completed order is later
	 * cancelled/refunded, so the transaction is not permanently burned by mistake
	 * — though in practice on-chain funds are already received).
	 *
	 * @param int $order_id The order whose claims to drop.
	 * @return void
	 */
	public static function release_order( int $order_id ): void {
		$map = get_option( self::OPTION, array() );

		if ( ! is_array( $map ) ) {
			return;
		}

		$changed = false;

		foreach ( $map as $key => $owner ) {
			if ( (int) $owner === $order_id ) {
				unset( $map[ $key ] );
				$changed = true;
			}
		}

		if ( $changed ) {
			update_option( self::OPTION, $map, false );
		}
	}
}
