<?php
/**
 * Tests for Ethereum address + transaction-hash validation.
 *
 * @package ShadowEth
 */

namespace ShadowEth\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShadowEth\Address;

/**
 * Covers format validation, EIP-55 checksum verification (the merchant typo
 * guard), normalisation, and equality — all of which gate real payments.
 */
final class AddressTest extends TestCase {

	/**
	 * EIP-55 spec vectors round-trip through to_checksum().
	 *
	 * @return array<int,array{0:string}>
	 */
	public static function checksum_vectors(): array {
		return array(
			array( '0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed' ),
			array( '0xfB6916095ca1df60bB79Ce92cE3Ea74c37c5d359' ),
			array( '0xdbF03B407c01E7cD3CBea99509d93f8DDDC8C6FB' ),
			array( '0xD1220A0cf47c7B9Be7A2E6BA89F429762e7b9aDb' ),
			array( '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48' ),
		);
	}

	/**
	 * A checksummed address is unchanged by to_checksum() and passes is_valid().
	 *
	 * @param string $address Checksummed address.
	 */
	#[DataProvider( 'checksum_vectors' )]
	public function test_valid_checksum_addresses( string $address ): void {
		$this->assertSame( $address, Address::to_checksum( $address ) );
		$this->assertTrue( Address::is_valid( $address ) );
	}

	/**
	 * A mixed-case address with a wrong checksum is rejected (typo guard).
	 */
	public function test_bad_checksum_is_rejected(): void {
		// One nibble's case flipped from a valid vector.
		$bad = '0x5AAeb6053F3E94C9b9A09f33669435E7Ef1BeAed';
		$this->assertFalse( Address::is_valid_checksum( $bad ) );
		$this->assertFalse( Address::is_valid( $bad ) );
	}

	/**
	 * All-lower and all-upper addresses are checksum-agnostic and accepted.
	 */
	public function test_case_agnostic_addresses_pass(): void {
		$this->assertTrue( Address::is_valid( '0xa0b86991c6218b36c1d19d4a2e9eb0ce3606eb48' ) );
		$this->assertTrue( Address::is_valid( '0xA0B86991C6218B36C1D19D4A2E9EB0CE3606EB48' ) );
	}

	/**
	 * Malformed strings fail format validation.
	 */
	public function test_format_rejects_malformed(): void {
		$this->assertFalse( Address::is_valid_format( '0x123' ) );
		$this->assertFalse( Address::is_valid_format( 'A0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48' ) );
		$this->assertFalse( Address::is_valid_format( '0xZZAeb6053F3E94C9b9A09f33669435E7Ef1BeAed' ) );
	}

	/**
	 * Equality is case-insensitive and rejects mismatches/garbage.
	 */
	public function test_equals(): void {
		$this->assertTrue(
			Address::equals(
				'0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48',
				'0xa0b86991c6218b36c1d19d4a2e9eb0ce3606eb48'
			)
		);
		$this->assertFalse(
			Address::equals(
				'0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48',
				'0x5aAeb6053F3E94C9b9A09f33669435E7Ef1BeAed'
			)
		);
		$this->assertFalse( Address::equals( 'nope', 'nope' ) );
	}

	/**
	 * Transaction hashes are validated by length + hex, normalised to lower case.
	 */
	public function test_tx_hash(): void {
		$hash = '0x' . str_repeat( 'aB', 32 );
		$this->assertTrue( Address::is_valid_tx_hash( $hash ) );
		$this->assertSame( strtolower( $hash ), Address::normalize_tx_hash( $hash ) );
		$this->assertFalse( Address::is_valid_tx_hash( '0xabc' ) );
		$this->assertSame( '', Address::normalize_tx_hash( '0xabc' ) );
	}
}
