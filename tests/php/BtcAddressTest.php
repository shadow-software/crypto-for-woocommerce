<?php
/**
 * Tests for Bitcoin mainnet address validation.
 *
 * @package ShadowEth
 */

namespace ShadowEth\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShadowEth\BtcAddress;

/**
 * Validates real P2PKH/P2SH/Bech32/Taproot addresses and rejects corrupted or
 * wrong-network ones — the merchant's receiving-address typo guard.
 */
final class BtcAddressTest extends TestCase {

	/**
	 * Known-valid mainnet addresses.
	 *
	 * @return array<int,array{0:string}>
	 */
	public static function valid_addresses(): array {
		return array(
			array( '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa' ),
			array( '3J98t1WpEZ73CNmQviecrnyiWrnqRhWNLy' ),
			array( 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4' ),
			array( 'bc1qar0srrr7xfkvy5l643lydnw9re59gtzzwf5mdq' ),
			array( 'bc1p0xlxvlhemja6c4dqv22uapctqupfhlxm9h8z3k2e72q4k9hcz7vqzk5jj0' ),
		);
	}

	/**
	 * Deliberately invalid addresses.
	 *
	 * @return array<int,array{0:string}>
	 */
	public static function invalid_addresses(): array {
		return array(
			array( '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNb' ), // bad Base58 checksum.
			array( 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t5' ), // bad Bech32 checksum.
			array( 'tb1qw508d6qejxtdg4y5r3zarvary0c5xw7kxpjzsx' ), // testnet HRP.
			array( '2N1SP7r92ZZJvYKG2oNtzPwYnzw62up7mTo' ), // testnet P2SH.
			array( '0x1234' ),
			array( 'notanaddress' ),
			array( '' ),
		);
	}

	/**
	 * Valid addresses pass.
	 *
	 * @param string $address Address.
	 */
	#[DataProvider( 'valid_addresses' )]
	public function test_valid( string $address ): void {
		$this->assertTrue( BtcAddress::is_valid( $address ) );
	}

	/**
	 * Invalid addresses are rejected.
	 *
	 * @param string $address Address.
	 */
	#[DataProvider( 'invalid_addresses' )]
	public function test_invalid( string $address ): void {
		$this->assertFalse( BtcAddress::is_valid( $address ) );
	}

	/**
	 * Equality lower-cases bech32 and rejects mismatches/garbage.
	 */
	public function test_equals(): void {
		$this->assertTrue(
			BtcAddress::equals(
				'BC1QAR0SRRR7XFKVY5L643LYDNW9RE59GTZZWF5MDQ',
				'bc1qar0srrr7xfkvy5l643lydnw9re59gtzzwf5mdq'
			)
		);
		$this->assertFalse(
			BtcAddress::equals(
				'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4',
				'bc1qar0srrr7xfkvy5l643lydnw9re59gtzzwf5mdq'
			)
		);
		$this->assertFalse( BtcAddress::equals( 'nope', 'nope' ) );
	}
}
