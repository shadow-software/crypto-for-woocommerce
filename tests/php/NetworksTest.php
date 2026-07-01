<?php
/**
 * Tests for the supported-networks registry.
 *
 * @package ShadowEth
 */

namespace ShadowEth\Tests;

use PHPUnit\Framework\TestCase;
use ShadowEth\Networks;

/**
 * Sanity checks on the network table and the explorer-URL helper.
 */
final class NetworksTest extends TestCase {

	/**
	 * The four expected networks are present with the right chain ids.
	 */
	public function test_expected_networks_present(): void {
		$all = Networks::all();

		$this->assertArrayHasKey( 'ethereum', $all );
		$this->assertArrayHasKey( 'base', $all );
		$this->assertArrayHasKey( 'arbitrum', $all );
		$this->assertArrayHasKey( 'optimism', $all );

		$this->assertSame( 1, $all['ethereum']['chain_id'] );
		$this->assertSame( 8453, $all['base']['chain_id'] );
		$this->assertSame( 42161, $all['arbitrum']['chain_id'] );
		$this->assertSame( 10, $all['optimism']['chain_id'] );
	}

	/**
	 * Every default RPC endpoint is an https URL.
	 */
	public function test_default_rpcs_are_https(): void {
		foreach ( Networks::all() as $network ) {
			$this->assertStringStartsWith( 'https://', $network['default_rpc'] );
		}
	}

	/**
	 * is_supported and get behave for known/unknown slugs.
	 */
	public function test_support_checks(): void {
		$this->assertTrue( Networks::is_supported( 'ethereum' ) );
		$this->assertFalse( Networks::is_supported( 'dogechain' ) );
		$this->assertNull( Networks::get( 'dogechain' ) );
	}

	/**
	 * Explorer URL is built for a valid hash and empty for a bad one.
	 */
	public function test_explorer_tx_url(): void {
		$hash = '0x' . str_repeat( 'a', 64 );
		$this->assertSame( 'https://etherscan.io/tx/' . $hash, Networks::explorer_tx_url( 'ethereum', $hash ) );
		$this->assertSame( '', Networks::explorer_tx_url( 'ethereum', '0xbad' ) );
		$this->assertSame( '', Networks::explorer_tx_url( 'nope', $hash ) );
	}
}
