<?php
/**
 * Helpers for reading ERC-20 token transfers from EVM logs.
 *
 * A token payment does not move the transaction's native value; it emits a
 * Transfer(from, to, value) event from the token contract. To confirm a USDC or
 * USDT payment we therefore look at event logs, not the tx's value field, and we
 * only trust a log emitted by the exact configured token contract (so a
 * worthless look-alike token cannot satisfy an order).
 *
 * @package ShadowEth
 */

namespace ShadowEth;

defined( 'ABSPATH' ) || exit;

/**
 * ERC-20 Transfer log decoding.
 */
final class Erc20 {

	/**
	 * The topic0 of every ERC-20 Transfer event, i.e. keccak256 of
	 * "Transfer(address,address,uint256)". Precomputed constant (verified against
	 * the canonical value).
	 */
	public const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

	/**
	 * The 32-byte, left-zero-padded topic representation of an address, for use as
	 * an indexed Transfer topic filter. Returns '' if the address is malformed.
	 *
	 * @param string $address 0x 20-byte address.
	 */
	public static function address_topic( string $address ): string {
		$normal = Address::normalize( $address );

		if ( '' === $normal ) {
			return '';
		}

		return '0x' . str_pad( substr( $normal, 2 ), 64, '0', STR_PAD_LEFT );
	}

	/**
	 * Decode a Transfer log into from, to, and value (base units), or null if the
	 * log is not a well-formed Transfer event.
	 *
	 * @param array<string,mixed> $log A single log entry from a receipt or getLogs.
	 * @return array{from:string,to:string,value:string,tx_hash:string,contract:string}|null
	 */
	public static function decode_transfer( array $log ): ?array {
		$topics = isset( $log['topics'] ) && is_array( $log['topics'] ) ? $log['topics'] : array();

		// A Transfer has exactly topic0 (signature) + 2 indexed address topics.
		if ( count( $topics ) < 3 ) {
			return null;
		}

		$topic0 = isset( $topics[0] ) && is_string( $topics[0] ) ? strtolower( $topics[0] ) : '';

		if ( self::TRANSFER_TOPIC !== $topic0 ) {
			return null;
		}

		$from = self::topic_to_address( is_string( $topics[1] ) ? $topics[1] : '' );
		$to   = self::topic_to_address( is_string( $topics[2] ) ? $topics[2] : '' );

		if ( '' === $from || '' === $to ) {
			return null;
		}

		$data     = isset( $log['data'] ) && is_string( $log['data'] ) ? $log['data'] : '0x';
		$value    = Money::hex_to_dec( $data );
		$tx_hash  = isset( $log['transactionHash'] ) && is_string( $log['transactionHash'] ) ? strtolower( $log['transactionHash'] ) : '';
		$contract = isset( $log['address'] ) && is_string( $log['address'] ) ? strtolower( $log['address'] ) : '';

		return array(
			'from'     => $from,
			'to'       => $to,
			'value'    => $value,
			'tx_hash'  => $tx_hash,
			'contract' => $contract,
		);
	}

	/**
	 * Convert a 32-byte indexed address topic back to a 0x address (lower-case),
	 * or '' if malformed.
	 *
	 * @param string $topic 0x 32-byte topic.
	 */
	private static function topic_to_address( string $topic ): string {
		$topic = strtolower( trim( $topic ) );

		if ( 1 !== preg_match( '/^0x0{24}[0-9a-f]{40}$/', $topic ) ) {
			return '';
		}

		return '0x' . substr( $topic, 26 );
	}
}
