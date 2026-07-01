<?php
/**
 * Ethereum address + transaction-hash validation and normalisation.
 *
 * Validating the merchant's receiving address (with an EIP-55 checksum check
 * when it is mixed-case) is a real safety feature: a mistyped address would send
 * every customer's payment into the void. Buyer-submitted sender addresses and
 * transaction hashes are validated the same way before they ever reach an RPC
 * call.
 *
 * @package ShadowEth
 */

namespace ShadowEth;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless address/hash helpers.
 */
final class Address {

	/**
	 * Whether a string is a syntactically valid 20-byte hex address (with or
	 * without a correct checksum). Case is not checked here.
	 *
	 * @param string $address Candidate address.
	 */
	public static function is_valid_format( string $address ): bool {
		return 1 === preg_match( '/^0x[0-9a-fA-F]{40}$/', trim( $address ) );
	}

	/**
	 * Whether a mixed-case address has a valid EIP-55 checksum. All-lower or
	 * all-upper addresses are considered checksum-agnostic and pass (a wallet
	 * that never applied a checksum is still a real address).
	 *
	 * @param string $address Candidate address.
	 */
	public static function is_valid_checksum( string $address ): bool {
		$address = trim( $address );

		if ( ! self::is_valid_format( $address ) ) {
			return false;
		}

		$hex = substr( $address, 2 );

		// No mixed case => nothing to verify.
		if ( strtolower( $hex ) === $hex || strtoupper( $hex ) === $hex ) {
			return true;
		}

		return self::to_checksum( $address ) === $address;
	}

	/**
	 * A fully valid address: correct format AND (when mixed-case) a valid
	 * checksum. This is the gate the merchant's receiving address must pass.
	 *
	 * @param string $address Candidate address.
	 */
	public static function is_valid( string $address ): bool {
		return self::is_valid_format( $address ) && self::is_valid_checksum( $address );
	}

	/**
	 * Convert an address to its EIP-55 checksummed form.
	 *
	 * @param string $address 0x-prefixed 20-byte hex address.
	 * @return string Checksummed address, or the trimmed input if malformed.
	 */
	public static function to_checksum( string $address ): string {
		$address = trim( $address );

		if ( ! self::is_valid_format( $address ) ) {
			return $address;
		}

		$hex  = strtolower( substr( $address, 2 ) );
		$hash = bin2hex( Keccak::hash256( $hex ) );
		$out  = '0x';

		for ( $i = 0; $i < 40; $i++ ) {
			$char = $hex[ $i ];

			if ( ctype_digit( $char ) ) {
				$out .= $char;
				continue;
			}

			// Upper-case the letter when the corresponding hash nibble >= 8.
			$out .= ( hexdec( $hash[ $i ] ) >= 8 ) ? strtoupper( $char ) : $char;
		}

		return $out;
	}

	/**
	 * Lower-case, 0x-prefixed canonical form for equality comparison. Addresses
	 * are compared case-insensitively on-chain, so we normalise before matching.
	 *
	 * @param string $address Candidate address.
	 * @return string Normalised address, or '' if malformed.
	 */
	public static function normalize( string $address ): string {
		$address = trim( $address );

		if ( ! self::is_valid_format( $address ) ) {
			return '';
		}

		return strtolower( $address );
	}

	/**
	 * Whether two addresses refer to the same account (case-insensitive).
	 *
	 * @param string $a First address.
	 * @param string $b Second address.
	 */
	public static function equals( string $a, string $b ): bool {
		$a = self::normalize( $a );
		$b = self::normalize( $b );

		return '' !== $a && $a === $b;
	}

	/**
	 * Whether a string is a valid 0x-prefixed 32-byte transaction hash.
	 *
	 * @param string $hash Candidate transaction hash.
	 */
	public static function is_valid_tx_hash( string $hash ): bool {
		return 1 === preg_match( '/^0x[0-9a-fA-F]{64}$/', trim( $hash ) );
	}

	/**
	 * Lower-case, 0x-prefixed canonical transaction hash, or '' if malformed.
	 *
	 * @param string $hash Candidate transaction hash.
	 */
	public static function normalize_tx_hash( string $hash ): string {
		$hash = trim( $hash );

		return self::is_valid_tx_hash( $hash ) ? strtolower( $hash ) : '';
	}
}
