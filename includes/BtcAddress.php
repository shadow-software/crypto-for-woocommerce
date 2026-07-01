<?php
/**
 * Bitcoin mainnet address validation.
 *
 * Validating the merchant's receiving address matters just as much as for
 * Ethereum: a typo would send customer funds nowhere. This validates the three
 * mainnet address forms — P2PKH ("1…") and P2SH ("3…") via Base58Check, and
 * native SegWit ("bc1…") via Bech32/Bech32m — with their checksums, entirely
 * offline and with no dependency.
 *
 * @package ShadowEth
 */

namespace ShadowEth;

defined( 'ABSPATH' ) || exit;

/**
 * Bitcoin address validator (mainnet).
 */
final class BtcAddress {

	/**
	 * Base58 alphabet.
	 */
	private const B58 = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

	/**
	 * Bech32 charset.
	 */
	private const BECH32 = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

	/**
	 * Whether a string is a valid Bitcoin mainnet address.
	 *
	 * @param string $address Candidate address.
	 */
	public static function is_valid( string $address ): bool {
		$address = trim( $address );

		if ( '' === $address ) {
			return false;
		}

		// Native SegWit / Taproot: bc1...
		if ( 0 === strpos( strtolower( $address ), 'bc1' ) ) {
			return self::is_valid_bech32( strtolower( $address ) );
		}

		// Legacy pay-to-public-key-hash or pay-to-script-hash (leading 1 or 3).
		if ( 1 === preg_match( '/^[13][1-9A-HJ-NP-Za-km-z]{25,34}$/', $address ) ) {
			return self::is_valid_base58check( $address );
		}

		return false;
	}

	/**
	 * Canonical comparison form. Bech32 addresses are lower-cased; Base58 ones are
	 * case-sensitive and returned as-is. Returns '' if invalid.
	 *
	 * @param string $address Candidate address.
	 */
	public static function normalize( string $address ): string {
		$address = trim( $address );

		if ( ! self::is_valid( $address ) ) {
			return '';
		}

		return 0 === strpos( strtolower( $address ), 'bc1' ) ? strtolower( $address ) : $address;
	}

	/**
	 * Case-insensitive-for-bech32 equality of two Bitcoin addresses.
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
	 * Validate a Base58Check address (P2PKH / P2SH): decode, split the 4-byte
	 * double-SHA256 checksum, and confirm it, and require a mainnet version byte
	 * (0x00 for P2PKH, 0x05 for P2SH).
	 *
	 * @param string $address Candidate address.
	 */
	private static function is_valid_base58check( string $address ): bool {
		$decoded = self::base58_decode( $address );

		if ( null === $decoded || strlen( $decoded ) !== 25 ) {
			return false;
		}

		$payload  = substr( $decoded, 0, 21 );
		$checksum = substr( $decoded, 21, 4 );
		$hash     = hash( 'sha256', hash( 'sha256', $payload, true ), true );

		if ( substr( $hash, 0, 4 ) !== $checksum ) {
			return false;
		}

		$version = ord( $payload[0] );

		return 0x00 === $version || 0x05 === $version;
	}

	/**
	 * Decode a Base58 string to raw bytes, preserving leading-zero bytes. Returns
	 * null on an invalid character.
	 *
	 * @param string $input Base58 string.
	 * @return string|null Raw bytes, or null.
	 */
	private static function base58_decode( string $input ): ?string {
		$num    = '0';
		$length = strlen( $input );

		for ( $i = 0; $i < $length; $i++ ) {
			$pos = strpos( self::B58, $input[ $i ] );

			if ( false === $pos ) {
				return null;
			}

			if ( function_exists( 'bcadd' ) ) {
				$num = bcadd( bcmul( $num, '58' ), (string) $pos );
			} else {
				return null; // Base58 needs big-int; BC Math is ubiquitous.
			}
		}

		// Convert the big decimal to bytes.
		$bytes = '';
		while ( bccomp( $num, '0' ) > 0 ) {
			$rem   = (int) bcmod( $num, '256' );
			$bytes = chr( $rem ) . $bytes;
			$num   = bcdiv( $num, '256', 0 );
		}

		// Restore leading zero bytes (each leading '1' is a 0x00 byte).
		for ( $i = 0; $i < $length && '1' === $input[ $i ]; $i++ ) {
			$bytes = "\x00" . $bytes;
		}

		return $bytes;
	}

	/**
	 * Validate a Bech32/Bech32m SegWit address (mainnet 'bc' HRP).
	 *
	 * @param string $address Lower-case candidate address.
	 */
	private static function is_valid_bech32( string $address ): bool {
		$pos = strrpos( $address, '1' );

		if ( false === $pos || $pos < 1 ) {
			return false;
		}

		$hrp  = substr( $address, 0, $pos );
		$data = substr( $address, $pos + 1 );

		if ( 'bc' !== $hrp || strlen( $data ) < 6 ) {
			return false;
		}

		$values = array();

		for ( $i = 0, $len = strlen( $data ); $i < $len; $i++ ) {
			$d = strpos( self::BECH32, $data[ $i ] );

			if ( false === $d ) {
				return false;
			}

			$values[] = $d;
		}

		// The witness version is the first data value; 0 => Bech32, 1-16 => Bech32m.
		$witness_version = $values[0];
		$constant        = 0 === $witness_version ? 1 : 0x2bc830a3;

		return self::bech32_polymod( $hrp, $values ) === $constant;
	}

	/**
	 * Compute the Bech32 checksum polymod over the HRP-expanded data.
	 *
	 * @param string $hrp    Human-readable part.
	 * @param int[]  $values Data values (including checksum).
	 */
	private static function bech32_polymod( string $hrp, array $values ): int {
		$generators = array( 0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3 );

		$combined = array();

		// HRP high bits, separator, HRP low bits, then data.
		for ( $i = 0, $len = strlen( $hrp ); $i < $len; $i++ ) {
			$combined[] = ord( $hrp[ $i ] ) >> 5;
		}
		$combined[] = 0;
		for ( $i = 0, $len = strlen( $hrp ); $i < $len; $i++ ) {
			$combined[] = ord( $hrp[ $i ] ) & 31;
		}
		foreach ( $values as $v ) {
			$combined[] = $v;
		}

		$chk = 1;

		foreach ( $combined as $value ) {
			$top = $chk >> 25;
			$chk = ( ( $chk & 0x1ffffff ) << 5 ) ^ $value;

			for ( $i = 0; $i < 5; $i++ ) {
				if ( ( $top >> $i ) & 1 ) {
					$chk ^= $generators[ $i ];
				}
			}
		}

		return $chk;
	}
}
