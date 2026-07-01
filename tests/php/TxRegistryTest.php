<?php
/**
 * Tests for the transaction replay-guard registry.
 *
 * @package ShadowEth
 */

namespace ShadowEth\Tests;

use PHPUnit\Framework\TestCase;
use ShadowEth\TxRegistry;

/**
 * Verifies the replay guard: one on-chain transaction can settle at most one
 * order, is idempotent for its owner, is per-network, and is released on refund.
 */
final class TxRegistryTest extends TestCase {

	private const TX  = '0xAABBccddeeff00112233445566778899aabbccddeeff00112233445566778899';
	private const NET = 'ethereum';

	/**
	 * Reset the in-memory options store before each test.
	 */
	protected function setUp(): void {
		shadow_eth_reset_options();
	}

	/**
	 * A fresh transaction is available and unclaimed.
	 */
	public function test_fresh_transaction_available(): void {
		$this->assertTrue( TxRegistry::is_available_for( self::NET, self::TX, 100 ) );
		$this->assertSame( 0, TxRegistry::claimed_by( self::NET, self::TX ) );
	}

	/**
	 * Claiming binds the tx to that order and blocks a different order (replay).
	 */
	public function test_replay_blocked(): void {
		$this->assertTrue( TxRegistry::claim( self::NET, self::TX, 100 ) );
		$this->assertSame( 100, TxRegistry::claimed_by( self::NET, self::TX ) );

		// Another order cannot use the same transaction.
		$this->assertFalse( TxRegistry::is_available_for( self::NET, self::TX, 200 ) );
		$this->assertFalse( TxRegistry::claim( self::NET, self::TX, 200 ) );
		$this->assertSame( 100, TxRegistry::claimed_by( self::NET, self::TX ) );
	}

	/**
	 * Re-claiming by the same order is idempotent, and case is ignored.
	 */
	public function test_idempotent_and_case_insensitive(): void {
		$this->assertTrue( TxRegistry::claim( self::NET, self::TX, 100 ) );
		$this->assertTrue( TxRegistry::claim( self::NET, self::TX, 100 ) );
		$this->assertTrue( TxRegistry::is_available_for( self::NET, strtolower( self::TX ), 100 ) );
		$this->assertFalse( TxRegistry::is_available_for( self::NET, strtoupper( self::TX ), 200 ) );
	}

	/**
	 * The same hash on a different network is an independent claim.
	 */
	public function test_per_network(): void {
		$this->assertTrue( TxRegistry::claim( self::NET, self::TX, 100 ) );
		$this->assertTrue( TxRegistry::is_available_for( 'base', self::TX, 200 ) );
		$this->assertTrue( TxRegistry::claim( 'base', self::TX, 200 ) );
		$this->assertSame( 100, TxRegistry::claimed_by( self::NET, self::TX ) );
		$this->assertSame( 200, TxRegistry::claimed_by( 'base', self::TX ) );
	}

	/**
	 * Releasing an order frees its claims but leaves others intact.
	 */
	public function test_release(): void {
		TxRegistry::claim( self::NET, self::TX, 100 );
		TxRegistry::claim( 'base', self::TX, 200 );

		TxRegistry::release_order( 100 );

		$this->assertSame( 0, TxRegistry::claimed_by( self::NET, self::TX ) );
		$this->assertTrue( TxRegistry::is_available_for( self::NET, self::TX, 300 ) );
		$this->assertSame( 200, TxRegistry::claimed_by( 'base', self::TX ) );
	}
}
