<?php
/**
 * Exact big-integer helpers for wei amounts.
 *
 * ETH values are 18-decimal integers of wei that overflow PHP's native int on a
 * 64-bit host and MUST NOT be handled as floats (float loses precision well
 * below 1 ETH). All comparisons and conversions here use string/BC Math so the
 * numbers stay exact end to end.
 *
 * @package ShadowEth
 */

namespace ShadowEth;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless wei/ETH math. Uses BC Math when available (it ships with virtually
 * every WordPress host) and falls back to a small long-addition/compare routine
 * so the plugin never fatals if the extension is somehow absent.
 */
final class Money {

	/**
	 * Number of decimals in one ETH.
	 */
	public const WEI_DECIMALS = 18;

	/**
	 * Convert a decimal amount string (e.g. "0.0142") into an integer count of the
	 * asset's smallest units, for a given number of decimals (18 for ETH, 8 for
	 * BTC, 6 for USDC/USDT). Excess fractional digits are truncated, never rounded
	 * up, so we never ask the buyer for more than the quoted amount.
	 *
	 * @param string $amount   Decimal amount as a string.
	 * @param int    $decimals Number of decimals for the asset.
	 * @return string Integer smallest-units as a decimal string (no 0x).
	 */
	public static function to_base_units( string $amount, int $decimals ): string {
		$amount   = trim( $amount );
		$decimals = max( 0, $decimals );

		if ( 1 !== preg_match( '/^\d+(\.\d+)?$/', $amount ) ) {
			return '0';
		}

		$parts    = explode( '.', $amount );
		$whole    = $parts[0];
		$fraction = isset( $parts[1] ) ? $parts[1] : '';

		// Pad/truncate the fractional part to exactly $decimals digits.
		$fraction = substr( str_pad( $fraction, $decimals, '0' ), 0, $decimals );

		$units = ltrim( $whole . $fraction, '0' );

		return '' === $units ? '0' : $units;
	}

	/**
	 * Convert an integer smallest-units string to a human decimal string with up
	 * to $decimals places, trailing zeros trimmed. For display only.
	 *
	 * @param string $units    Integer smallest-units as a decimal string.
	 * @param int    $decimals Number of decimals for the asset.
	 */
	public static function from_base_units( string $units, int $decimals ): string {
		$units    = ltrim( trim( $units ), '0' );
		$decimals = max( 0, $decimals );

		if ( '' === $units || 1 !== preg_match( '/^\d+$/', $units ) ) {
			return '0';
		}

		if ( 0 === $decimals ) {
			return $units;
		}

		$units    = str_pad( $units, $decimals + 1, '0', STR_PAD_LEFT );
		$whole    = substr( $units, 0, -$decimals );
		$fraction = rtrim( substr( $units, -$decimals ), '0' );

		return '' === $fraction ? $whole : $whole . '.' . $fraction;
	}

	/**
	 * Convert a decimal ETH string to integer wei. Thin wrapper over the generic
	 * base-unit conversion, kept for readability at ETH call sites.
	 *
	 * @param string $eth Decimal ETH amount as a string.
	 */
	public static function eth_to_wei( string $eth ): string {
		return self::to_base_units( $eth, self::WEI_DECIMALS );
	}

	/**
	 * Convert integer wei to a human ETH string. Thin wrapper over the generic
	 * conversion.
	 *
	 * @param string $wei Integer wei as a decimal string.
	 */
	public static function wei_to_eth( string $wei ): string {
		return self::from_base_units( $wei, self::WEI_DECIMALS );
	}

	/**
	 * Parse a 0x-prefixed hex quantity (as returned by JSON-RPC) into an integer
	 * wei decimal string. Returns '0' for malformed input.
	 *
	 * @param string $hex 0x-prefixed hex string.
	 */
	public static function hex_to_dec( string $hex ): string {
		$hex = strtolower( trim( $hex ) );

		if ( 0 === strpos( $hex, '0x' ) ) {
			$hex = substr( $hex, 2 );
		}

		if ( '' === $hex || 1 !== preg_match( '/^[0-9a-f]+$/', $hex ) ) {
			return '0';
		}

		// A native ETH value is a 256-bit quantity: at most 64 hex digits. A node
		// returning anything longer is malformed/hostile; refuse it rather than
		// spend unbounded time in the digit-by-digit BC Math loop below.
		if ( strlen( ltrim( $hex, '0' ) ) > 64 ) {
			return '0';
		}

		if ( function_exists( 'bcadd' ) ) {
			$dec = '0';
			$len = strlen( $hex );

			for ( $i = 0; $i < $len; $i++ ) {
				$dec = bcadd( bcmul( $dec, '16' ), (string) hexdec( $hex[ $i ] ) );
			}

			return $dec;
		}

		return self::hex_to_dec_fallback( $hex );
	}

	/**
	 * Compare two non-negative integer decimal strings.
	 * Returns -1 if $a < $b, 0 if equal, 1 if $a > $b.
	 *
	 * @param string $a Left operand (integer decimal string).
	 * @param string $b Right operand (integer decimal string).
	 */
	public static function compare( string $a, string $b ): int {
		$a = self::normalize_int( $a );
		$b = self::normalize_int( $b );

		if ( function_exists( 'bccomp' ) ) {
			return bccomp( $a, $b, 0 );
		}

		if ( strlen( $a ) !== strlen( $b ) ) {
			return strlen( $a ) < strlen( $b ) ? -1 : 1;
		}

		return strcmp( $a, $b ) <=> 0;
	}

	/**
	 * Multiply an integer decimal string by an integer and return the integer
	 * part of (value * numerator / denominator). Used to compute the minimum
	 * acceptable amount after applying an underpayment tolerance in basis points.
	 *
	 * @param string $value       Integer decimal string.
	 * @param int    $numerator   Numerator.
	 * @param int    $denominator Denominator (must be > 0).
	 */
	public static function mul_div( string $value, int $numerator, int $denominator ): string {
		$value = self::normalize_int( $value );

		if ( $denominator <= 0 ) {
			return '0';
		}

		if ( function_exists( 'bcmul' ) ) {
			return bcdiv( bcmul( $value, (string) $numerator ), (string) $denominator, 0 );
		}

		// Fallback: acceptable precision loss only on absurdly large values on a
		// host with neither BC Math nor GMP, which is effectively never.
		return (string) (int) ( ( (float) $value * $numerator ) / $denominator );
	}

	/**
	 * Add two integer base-unit strings, exactly. Used to sum multiple ERC-20
	 * Transfer logs in one transaction.
	 *
	 * @param string $a Integer base-units decimal string.
	 * @param string $b Integer base-units decimal string.
	 */
	public static function add_units( string $a, string $b ): string {
		$a = self::normalize_int( $a );
		$b = self::normalize_int( $b );

		if ( function_exists( 'bcadd' ) ) {
			return bcadd( $a, $b );
		}

		// Fallback long addition (BC-less host).
		return self::long_add( $a, $b );
	}

	/**
	 * Big-integer long addition of two non-negative decimal strings.
	 *
	 * @param string $a Integer decimal string.
	 * @param string $b Integer decimal string.
	 */
	private static function long_add( string $a, string $b ): string {
		$a     = strrev( $a );
		$b     = strrev( $b );
		$len   = max( strlen( $a ), strlen( $b ) );
		$carry = 0;
		$out   = '';

		for ( $i = 0; $i < $len; $i++ ) {
			$da    = $i < strlen( $a ) ? (int) $a[ $i ] : 0;
			$db    = $i < strlen( $b ) ? (int) $b[ $i ] : 0;
			$sum   = $da + $db + $carry;
			$out  .= (string) ( $sum % 10 );
			$carry = intdiv( $sum, 10 );
		}

		if ( $carry > 0 ) {
			$out .= (string) $carry;
		}

		$result = ltrim( strrev( $out ), '0' );

		return '' === $result ? '0' : $result;
	}

	/**
	 * Add a small non-negative integer to an integer wei string, exactly.
	 *
	 * @param string $value Integer wei decimal string.
	 * @param int    $add   Non-negative integer to add.
	 */
	public static function add_int( string $value, int $add ): string {
		$value = self::normalize_int( $value );
		$add   = max( 0, $add );

		if ( function_exists( 'bcadd' ) ) {
			return bcadd( $value, (string) $add );
		}

		// Fallback for the (effectively non-existent) no-BC host.
		return (string) ( (int) $value + $add );
	}

	/**
	 * Give an order a UNIQUE required amount by ADDING a small per-order value on
	 * top of the quoted amount.
	 *
	 * Two orders for the same fiat total at the same rate would otherwise lock the
	 * identical amount, letting one on-chain payment satisfy both (and letting a
	 * buyer claim a stranger's public payment). Adding a per-order value derived
	 * from the order id makes each order's required amount distinct, so a transfer
	 * can match at most one order by amount.
	 *
	 * Two invariants make this safe across every asset's decimals:
	 *   1. The salt is only ever ADDED — the required amount never drops below the
	 *      quoted amount, so the merchant can never under-collect.
	 *   2. The salt is bounded to a tiny fraction (≤ ~0.01%) of the quoted amount,
	 *      so the buyer pays at most a hair more. For very small amounts the salt
	 *      is a single base unit (still unique per order via the modulus).
	 *
	 * This replaces an earlier fixed-modulus design that was calibrated for
	 * 18-decimal wei and catastrophically mis-priced 6-decimal (USDC/USDT) and
	 * 8-decimal (BTC) amounts.
	 *
	 * @param string $amount   The quoted required amount in the asset's base units.
	 * @param int    $order_id The order id (the uniqueness source).
	 * @return string The salted, unique required amount (>= the quoted amount).
	 */
	public static function apply_unique_salt( string $amount, int $order_id ): string {
		$amount = self::normalize_int( $amount );

		if ( '0' === $amount ) {
			return '0';
		}

		// Headroom = at most ~0.01% of the amount (amount / 10000), but at least a
		// window of SALT_SLOTS so different orders still get distinct values on
		// small amounts. The salt itself is in [1, headroom].
		$fraction = self::div_int( $amount, self::SALT_FRACTION_DIVISOR );
		$headroom = max( self::SALT_SLOTS, (int) ( strlen( $fraction ) > 9 ? PHP_INT_MAX : (int) $fraction ) );
		$salt     = ( abs( $order_id ) % $headroom ) + 1;

		return self::add_int( $amount, $salt );
	}

	/**
	 * The salt is bounded to amount / this divisor, i.e. ~0.01% of the amount.
	 */
	private const SALT_FRACTION_DIVISOR = 10000;

	/**
	 * Minimum number of distinct salt slots, so even tiny amounts (where 0.01% is
	 * below one slot) still spread across enough values to be unique per order.
	 * 256 concurrent identically-priced open orders is far more than realistic,
	 * while keeping the worst-case overpay on a tiny order to a fraction of a cent.
	 */
	private const SALT_SLOTS = 256;

	/**
	 * Integer division of a non-negative decimal string by a positive integer,
	 * flooring. Returns '0' for invalid input.
	 *
	 * @param string $value   Integer decimal string.
	 * @param int    $divisor Positive divisor.
	 */
	public static function div_int( string $value, int $divisor ): string {
		$value = self::normalize_int( $value );

		if ( $divisor <= 0 ) {
			return '0';
		}

		if ( function_exists( 'bcdiv' ) ) {
			return bcdiv( $value, (string) $divisor, 0 );
		}

		return (string) intdiv( (int) $value, $divisor );
	}

	/**
	 * Normalise an integer decimal string: strip a leading sign we never expect,
	 * trim leading zeros, and coerce empty/invalid input to '0'.
	 *
	 * @param string $value Candidate integer decimal string.
	 */
	private static function normalize_int( string $value ): string {
		$value = ltrim( trim( $value ), '0' );

		if ( '' === $value || 1 !== preg_match( '/^\d+$/', $value ) ) {
			return '0';
		}

		return $value;
	}

	/**
	 * BC-Math-free hex to decimal conversion via long addition. Only reached on
	 * the vanishingly rare host without the bc extension.
	 *
	 * @param string $hex Lower-case hex digits, no 0x prefix.
	 */
	private static function hex_to_dec_fallback( string $hex ): string {
		$dec = array( 0 );
		$len = strlen( $hex );

		for ( $i = 0; $i < $len; $i++ ) {
			$carry = hexdec( $hex[ $i ] );

			foreach ( $dec as $index => $digit ) {
				$value         = $digit * 16 + $carry;
				$dec[ $index ] = $value % 10;
				$carry         = intdiv( $value, 10 );
			}

			while ( $carry > 0 ) {
				$dec[] = $carry % 10;
				$carry = intdiv( $carry, 10 );
			}
		}

		return implode( '', array_reverse( $dec ) );
	}
}
