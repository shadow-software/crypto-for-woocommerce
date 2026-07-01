<?php
/**
 * Tests for exact wei/ETH big-integer math.
 *
 * @package ShadowEth
 */

namespace ShadowEth\Tests;

use PHPUnit\Framework\TestCase;
use ShadowEth\Money;

/**
 * Exercises the conversions and comparisons the verifier relies on. These must be
 * exact — a float here could accept an underpayment or reject a valid one.
 */
final class MoneyTest extends TestCase {

	/**
	 * ETH decimal strings convert to the right integer wei.
	 */
	public function test_eth_to_wei(): void {
		$this->assertSame( '1000000000000000000', Money::eth_to_wei( '1' ) );
		$this->assertSame( '14200000000000000', Money::eth_to_wei( '0.0142' ) );
		$this->assertSame( '1', Money::eth_to_wei( '0.000000000000000001' ) );
		$this->assertSame( '0', Money::eth_to_wei( '0' ) );
	}

	/**
	 * Excess fractional digits are truncated, never rounded up (we must never
	 * ask the buyer for more than quoted).
	 */
	public function test_eth_to_wei_truncates_extra_precision(): void {
		$this->assertSame( '1', Money::eth_to_wei( '0.0000000000000000019' ) );
	}

	/**
	 * Garbage input yields zero rather than a fatal.
	 */
	public function test_eth_to_wei_rejects_garbage(): void {
		$this->assertSame( '0', Money::eth_to_wei( 'not-a-number' ) );
		$this->assertSame( '0', Money::eth_to_wei( '0x10' ) );
	}

	/**
	 * Wei renders back to a trimmed ETH string.
	 */
	public function test_wei_to_eth(): void {
		$this->assertSame( '1', Money::wei_to_eth( '1000000000000000000' ) );
		$this->assertSame( '0.0142', Money::wei_to_eth( '14200000000000000' ) );
		$this->assertSame( '0.000000000000000001', Money::wei_to_eth( '1' ) );
		$this->assertSame( '0', Money::wei_to_eth( '0' ) );
	}

	/**
	 * Hex JSON-RPC quantities parse to exact decimal wei.
	 */
	public function test_hex_to_dec(): void {
		$this->assertSame( '0', Money::hex_to_dec( '0x0' ) );
		$this->assertSame( '255', Money::hex_to_dec( '0xff' ) );
		// 1 ETH in wei = 0xde0b6b3a7640000.
		$this->assertSame( '1000000000000000000', Money::hex_to_dec( '0xde0b6b3a7640000' ) );
	}

	/**
	 * Large-value comparisons are exact across the 64-bit boundary.
	 */
	public function test_compare(): void {
		$this->assertSame( 0, Money::compare( '1000000000000000000', '1000000000000000000' ) );
		$this->assertSame( -1, Money::compare( '999999999999999999', '1000000000000000000' ) );
		$this->assertSame( 1, Money::compare( '1000000000000000001', '1000000000000000000' ) );
	}

	/**
	 * The tolerance math: required * (10000 - bps) / 10000.
	 */
	public function test_mul_div_tolerance(): void {
		// 1 ETH, 1% tolerance => min 0.99 ETH.
		$this->assertSame( '990000000000000000', Money::mul_div( '1000000000000000000', 9900, 10000 ) );
		// 0 tolerance handled by caller, but denominator guard still works.
		$this->assertSame( '0', Money::mul_div( '1000000000000000000', 1, 0 ) );
	}

	/**
	 * Generic base-unit conversion works for token (6dp) and BTC (8dp) decimals.
	 */
	public function test_base_units_generic_decimals(): void {
		// 12.5 USDC (6dp) => 12500000 base units.
		$this->assertSame( '12500000', Money::to_base_units( '12.5', 6 ) );
		$this->assertSame( '12.5', Money::from_base_units( '12500000', 6 ) );
		// 0.001 BTC (8dp) => 100000 sats.
		$this->assertSame( '100000', Money::to_base_units( '0.001', 8 ) );
		$this->assertSame( '0.001', Money::from_base_units( '100000', 8 ) );
		// Truncation, never rounding up.
		$this->assertSame( '1', Money::to_base_units( '0.0000019', 6 ) );
	}

	/**
	 * Big-integer addition of base-unit strings is exact.
	 */
	public function test_add_units(): void {
		$this->assertSame( '3', Money::add_units( '1', '2' ) );
		$this->assertSame(
			'2000000000000000000',
			Money::add_units( '1000000000000000000', '1000000000000000000' )
		);
		$this->assertSame( '0', Money::add_units( '0', '0' ) );
	}

	/**
	 * The per-order salt makes amounts unique, only ever ADDS (never under-collects),
	 * and overpays by only a tiny bounded amount — across every asset's decimals.
	 * This is the regression guard for the decimals-unaware salt bug that mis-priced
	 * USDC/USDT (6dp) and BTC (8dp) orders.
	 *
	 * @dataProvider salt_cases
	 *
	 * @param string $amount Quoted amount in the asset's base units.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'salt_cases' )]
	public function test_apply_unique_salt_invariants( string $amount ): void {
		$a = Money::apply_unique_salt( $amount, 12345 );
		$b = Money::apply_unique_salt( $amount, 12346 );

		// 1. Never under-collect: the salted amount is >= the quoted amount.
		$this->assertGreaterThanOrEqual( 0, Money::compare( $a, $amount ), 'salted must be >= quoted' );

		// 2. Unique: two different orders get two different amounts.
		$this->assertNotSame( $a, $b );

		// 3. Overpay is tiny: at most max(amount/10000, 256) base units.
		$delta      = Money::add_units( $a, '0' ); // normalise.
		$delta      = self::sub_str( $a, $amount );
		$max_over   = Money::compare( $amount, '2560000' ) > 0
			? Money::div_int( $amount, 10000 )
			: '256';
		$this->assertLessThanOrEqual( 0, Money::compare( $delta, self::add_str( $max_over, '1' ) ) );
	}

	/**
	 * Amount cases across 6-, 8-, and 18-decimal assets, plus dust.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function salt_cases(): array {
		return array(
			'$5 USDC (6dp)'     => array( '5000000' ),
			'$49.99 USDC (6dp)' => array( '49990000' ),
			'0.003 BTC (8dp)'   => array( '300000' ),
			'0.05 BTC (8dp)'    => array( '5000000' ),
			'1 ETH (18dp)'      => array( '1000000000000000000' ),
			'dust (1 unit)'     => array( '1' ),
		);
	}

	/**
	 * Zero stays zero (fail-closed handled by the caller).
	 */
	public function test_apply_unique_salt_zero(): void {
		$this->assertSame( '0', Money::apply_unique_salt( '0', 5 ) );
	}

	/**
	 * Test helper: subtract two non-negative integer decimal strings (a >= b).
	 *
	 * @param string $a Minuend.
	 * @param string $b Subtrahend.
	 */
	private static function sub_str( string $a, string $b ): string {
		return bcsub( $a, $b );
	}

	/**
	 * Test helper: add two non-negative integer decimal strings.
	 *
	 * @param string $a First.
	 * @param string $b Second.
	 */
	private static function add_str( string $a, string $b ): string {
		return bcadd( $a, $b );
	}
}
