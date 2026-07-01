<?php
/**
 * Tests for the payable-asset registry.
 *
 * @package ShadowEth
 */

namespace ShadowEth\Tests;

use PHPUnit\Framework\TestCase;
use ShadowEth\Address;
use ShadowEth\Assets;

/**
 * Verifies the asset catalogue: native ETH per network, the token set, native
 * BTC, and that every token contract is a checksum-valid address.
 */
final class AssetsTest extends TestCase {

	/**
	 * Native ETH exists on every EVM network, plus native BTC.
	 */
	public function test_native_assets_present(): void {
		$this->assertNotNull( Assets::get( 'eth:ethereum' ) );
		$this->assertNotNull( Assets::get( 'eth:base' ) );
		$this->assertNotNull( Assets::get( 'eth:arbitrum' ) );
		$this->assertNotNull( Assets::get( 'eth:optimism' ) );
		$this->assertNotNull( Assets::get( 'btc:bitcoin' ) );
	}

	/**
	 * The expected stablecoin deployments are present.
	 */
	public function test_token_assets_present(): void {
		$this->assertNotNull( Assets::get( 'usdc:ethereum' ) );
		$this->assertNotNull( Assets::get( 'usdt:ethereum' ) );
		$this->assertNotNull( Assets::get( 'usdc:base' ) );
		$this->assertNotNull( Assets::get( 'usdc:arbitrum' ) );
		$this->assertNotNull( Assets::get( 'usdt:arbitrum' ) );
		$this->assertNotNull( Assets::get( 'usdc:optimism' ) );
		$this->assertNotNull( Assets::get( 'usdt:optimism' ) );

		// USDT is not (natively) on Base in the registry.
		$this->assertNull( Assets::get( 'usdt:base' ) );
	}

	/**
	 * Every token contract is a valid, checksummed address, and every stablecoin
	 * uses 6 decimals (a wrong decimals value would mis-scale amounts).
	 */
	public function test_token_contracts_valid(): void {
		foreach ( Assets::all() as $asset ) {
			if ( Assets::KIND_ERC20 !== $asset['kind'] ) {
				continue;
			}

			$this->assertTrue(
				Address::is_valid( $asset['contract'] ),
				'Token contract must be a checksum-valid address: ' . $asset['id']
			);
			$this->assertSame( 6, $asset['decimals'], 'Stablecoins are 6 decimals: ' . $asset['id'] );
		}
	}

	/**
	 * Native ETH is 18 decimals, BTC is 8.
	 */
	public function test_native_decimals(): void {
		$this->assertSame( 18, Assets::get( 'eth:ethereum' )['decimals'] );
		$this->assertSame( 8, Assets::get( 'btc:bitcoin' )['decimals'] );
	}

	/**
	 * Unknown ids are unsupported.
	 */
	public function test_unknown_asset(): void {
		$this->assertFalse( Assets::is_supported( 'doge:bitcoin' ) );
		$this->assertNull( Assets::get( 'nope' ) );
	}
}
