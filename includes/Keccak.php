<?php
/**
 * Pure-PHP Keccak-256 (the pre-standard SHA-3 variant Ethereum uses).
 *
 * Bundled so the plugin can validate EIP-55 mixed-case address checksums with no
 * Composer dependency and no network call. This is a compact, self-contained
 * implementation of the Keccak-f[1600] permutation for the single rate/capacity
 * Ethereum needs (256-bit output). It is used only for hashing short address
 * strings, never for anything performance-sensitive.
 *
 * Derived from the public-domain Keccak reference sponge construction (the
 * Keccak team dedicated the reference implementation to the public domain), and
 * re-implemented here from the algorithm specification. Public-domain code is
 * GPL-compatible; this file is distributed under this plugin's GPL-2.0-or-later
 * license.
 *
 * @package ShadowEth
 */

namespace ShadowEth;

defined( 'ABSPATH' ) || exit;

/**
 * Minimal Keccak-256 hasher.
 *
 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Round constants/state use short mathematical names by convention.
 */
final class Keccak {

	/**
	 * Keccak round constants (low, high 32-bit halves), 24 rounds.
	 *
	 * @var array<int,array{0:int,1:int}>|null
	 */
	private static $rc = null;

	/**
	 * Rotation offsets for the rho step.
	 *
	 * @var int[]
	 */
	private static $rot = array( 0, 1, 62, 28, 27, 36, 44, 6, 55, 20, 3, 10, 43, 25, 39, 41, 45, 15, 21, 8, 18, 2, 61, 56, 14 );

	/**
	 * Hash a binary string with Keccak-256 and return a 32-byte binary digest.
	 *
	 * @param string $input Raw binary input.
	 * @return string 32-byte binary hash.
	 */
	public static function hash256( string $input ): string {
		self::init_constants();

		$rate       = 1088; // 256-bit output => rate 1088 bits => 136 bytes.
		$rate_bytes = $rate / 8;

		// Pad (Keccak pad10*1 with the 0x01 domain byte Ethereum uses).
		$len                           = strlen( $input );
		$fill                          = $rate_bytes - ( $len % $rate_bytes );
		$input                        .= chr( 0x01 ) . str_repeat( "\0", $fill - 1 );
		$input[ strlen( $input ) - 1 ] = chr( ord( $input[ strlen( $input ) - 1 ] ) | 0x80 );

		// State: 25 lanes of 64 bits, held as [low32, high32] pairs.
		$state = array();
		for ( $i = 0; $i < 25; $i++ ) {
			$state[ $i ] = array( 0, 0 );
		}

		$blocks = str_split( $input, $rate_bytes );

		foreach ( $blocks as $block ) {
			for ( $i = 0; $i < $rate_bytes; $i += 8 ) {
				$lane = ( $i / 8 );
				$lo   = self::le32( $block, $i );
				$hi   = self::le32( $block, $i + 4 );

				$state[ $lane ][0] ^= $lo;
				$state[ $lane ][1] ^= $hi;
			}

			$state = self::keccak_f( $state );
		}

		// Squeeze the first 32 bytes (one rate block covers it).
		$out = '';
		for ( $i = 0; $i < 4; $i++ ) {
			$out .= self::to_le32( $state[ $i ][0] );
			$out .= self::to_le32( $state[ $i ][1] );
		}

		return substr( $out, 0, 32 );
	}

	/**
	 * The Keccak-f[1600] permutation over the 25-lane state.
	 *
	 * @param array<int,array{0:int,1:int}> $state Lane state as [lo,hi] pairs.
	 * @return array<int,array{0:int,1:int}>
	 */
	private static function keccak_f( array $state ): array {
		for ( $round = 0; $round < 24; $round++ ) {
			// Theta.
			$c = array();
			for ( $x = 0; $x < 5; $x++ ) {
				$c[ $x ] = array(
					$state[ $x ][0] ^ $state[ $x + 5 ][0] ^ $state[ $x + 10 ][0] ^ $state[ $x + 15 ][0] ^ $state[ $x + 20 ][0],
					$state[ $x ][1] ^ $state[ $x + 5 ][1] ^ $state[ $x + 10 ][1] ^ $state[ $x + 15 ][1] ^ $state[ $x + 20 ][1],
				);
			}

			$d = array();
			for ( $x = 0; $x < 5; $x++ ) {
				$rot     = self::rotl64( $c[ ( $x + 1 ) % 5 ], 1 );
				$d[ $x ] = array(
					$c[ ( $x + 4 ) % 5 ][0] ^ $rot[0],
					$c[ ( $x + 4 ) % 5 ][1] ^ $rot[1],
				);
			}

			for ( $x = 0; $x < 5; $x++ ) {
				for ( $y = 0; $y < 25; $y += 5 ) {
					$state[ $x + $y ][0] ^= $d[ $x ][0];
					$state[ $x + $y ][1] ^= $d[ $x ][1];
				}
			}

			// Rho + Pi.
			$b = array();
			for ( $i = 0; $i < 25; $i++ ) {
				$b[ $i ] = array( 0, 0 );
			}
			for ( $x = 0; $x < 5; $x++ ) {
				for ( $y = 0; $y < 5; $y++ ) {
					$idx             = $x + 5 * $y;
					$new_index       = $y + 5 * ( ( 2 * $x + 3 * $y ) % 5 );
					$b[ $new_index ] = self::rotl64( $state[ $idx ], self::$rot[ $idx ] );
				}
			}

			// Chi.
			for ( $y = 0; $y < 25; $y += 5 ) {
				$t = array();
				for ( $x = 0; $x < 5; $x++ ) {
					$t[ $x ] = $b[ $x + $y ];
				}
				for ( $x = 0; $x < 5; $x++ ) {
					$state[ $x + $y ] = array(
						$t[ $x ][0] ^ ( ( ~ $t[ ( $x + 1 ) % 5 ][0] ) & $t[ ( $x + 2 ) % 5 ][0] ),
						$t[ $x ][1] ^ ( ( ~ $t[ ( $x + 1 ) % 5 ][1] ) & $t[ ( $x + 2 ) % 5 ][1] ),
					);
				}
			}

			// Iota.
			$state[0][0] ^= self::$rc[ $round ][0];
			$state[0][1] ^= self::$rc[ $round ][1];

			// Mask to 32 bits per half after each round.
			for ( $i = 0; $i < 25; $i++ ) {
				$state[ $i ][0] &= 0xFFFFFFFF;
				$state[ $i ][1] &= 0xFFFFFFFF;
			}
		}

		return $state;
	}

	/**
	 * Rotate a 64-bit lane (as [lo,hi]) left by $n bits.
	 *
	 * @param array{0:int,1:int} $lane Lane as [lo, hi].
	 * @param int                $n    Rotation amount (0-63).
	 * @return array{0:int,1:int}
	 */
	private static function rotl64( array $lane, int $n ): array {
		$n &= 63;
		$lo = $lane[0] & 0xFFFFFFFF;
		$hi = $lane[1] & 0xFFFFFFFF;

		if ( 0 === $n ) {
			return array( $lo, $hi );
		}

		if ( 32 === $n ) {
			return array( $hi, $lo );
		}

		if ( $n < 32 ) {
			$new_lo = ( ( $lo << $n ) | ( $hi >> ( 32 - $n ) ) ) & 0xFFFFFFFF;
			$new_hi = ( ( $hi << $n ) | ( $lo >> ( 32 - $n ) ) ) & 0xFFFFFFFF;

			return array( $new_lo, $new_hi );
		}

		$n     -= 32;
		$new_lo = ( ( $hi << $n ) | ( $lo >> ( 32 - $n ) ) ) & 0xFFFFFFFF;
		$new_hi = ( ( $lo << $n ) | ( $hi >> ( 32 - $n ) ) ) & 0xFFFFFFFF;

		return array( $new_lo, $new_hi );
	}

	/**
	 * Read 4 little-endian bytes from $data at $offset as an unsigned 32-bit int.
	 *
	 * @param string $data   Binary string.
	 * @param int    $offset Byte offset.
	 */
	private static function le32( string $data, int $offset ): int {
		return ( ord( $data[ $offset ] )
			| ( ord( $data[ $offset + 1 ] ) << 8 )
			| ( ord( $data[ $offset + 2 ] ) << 16 )
			| ( ord( $data[ $offset + 3 ] ) << 24 ) ) & 0xFFFFFFFF;
	}

	/**
	 * Emit a 32-bit int as 4 little-endian bytes.
	 *
	 * @param int $value Unsigned 32-bit value.
	 */
	private static function to_le32( int $value ): string {
		return chr( $value & 0xFF )
			. chr( ( $value >> 8 ) & 0xFF )
			. chr( ( $value >> 16 ) & 0xFF )
			. chr( ( $value >> 24 ) & 0xFF );
	}

	/**
	 * Lazily build the 24 round constants as [lo, hi] 32-bit pairs.
	 *
	 * @return void
	 */
	private static function init_constants(): void {
		if ( null !== self::$rc ) {
			return;
		}

		$rc64 = array(
			'0000000000000001',
			'0000000000008082',
			'800000000000808a',
			'8000000080008000',
			'000000000000808b',
			'0000000080000001',
			'8000000080008081',
			'8000000000008009',
			'000000000000008a',
			'0000000000000088',
			'0000000080008009',
			'000000008000000a',
			'000000008000808b',
			'800000000000008b',
			'8000000000008089',
			'8000000000008003',
			'8000000000008002',
			'8000000000000080',
			'000000000000800a',
			'800000008000000a',
			'8000000080008081',
			'8000000000008080',
			'0000000080000001',
			'8000000080008008',
		);

		self::$rc = array();
		foreach ( $rc64 as $hex ) {
			$hi         = hexdec( substr( $hex, 0, 8 ) );
			$lo         = hexdec( substr( $hex, 8, 8 ) );
			self::$rc[] = array( (int) $lo, (int) $hi );
		}
	}
}
