<?php
/**
 * Tests for ERC-20 Transfer log decoding.
 *
 * @package ShadowEth
 */

namespace ShadowEth\Tests;

use PHPUnit\Framework\TestCase;
use ShadowEth\Erc20;

/**
 * Verifies the Transfer topic, address-topic packing, and log decoding — the
 * primitives that confirm a token payment's recipient and amount.
 */
final class Erc20Test extends TestCase {

	/**
	 * The Transfer topic is the canonical keccak256 signature.
	 */
	public function test_transfer_topic(): void {
		$this->assertSame(
			'0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef',
			Erc20::TRANSFER_TOPIC
		);
	}

	/**
	 * An address packs into a left-zero-padded 32-byte topic.
	 */
	public function test_address_topic(): void {
		$topic = Erc20::address_topic( '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48' );
		$this->assertSame( '0x000000000000000000000000a0b86991c6218b36c1d19d4a2e9eb0ce3606eb48', $topic );
		$this->assertSame( '', Erc20::address_topic( 'not-an-address' ) );
	}

	/**
	 * A well-formed Transfer log decodes to the right from/to/value.
	 */
	public function test_decode_transfer(): void {
		$from = '0xeff6cb8b614999d130e537751ee99724d01aa167';
		$to   = '0x3f21e2f16da919846f4757e5c10998b91a79db31';
		$log  = array(
			'address'         => '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48',
			'transactionHash' => '0x5b3bbec32b8831c172d0c1b9f99103d22d75f36c7b70565e7b7dd6cc708dfa4b',
			'topics'          => array(
				Erc20::TRANSFER_TOPIC,
				'0x000000000000000000000000' . substr( $from, 2 ),
				'0x000000000000000000000000' . substr( $to, 2 ),
			),
			// 5268661669 in hex (= 0x13a0965a5).
			'data'            => '0x000000000000000000000000000000000000000000000000000000013a0965a5',
		);

		$decoded = Erc20::decode_transfer( $log );

		$this->assertNotNull( $decoded );
		$this->assertSame( $from, $decoded['from'] );
		$this->assertSame( $to, $decoded['to'] );
		$this->assertSame( '5268661669', $decoded['value'] );
		$this->assertSame( '0xa0b86991c6218b36c1d19d4a2e9eb0ce3606eb48', $decoded['contract'] );
	}

	/**
	 * A log that is not a Transfer (wrong topic0 or too few topics) decodes null.
	 */
	public function test_decode_rejects_non_transfer(): void {
		$this->assertNull(
			Erc20::decode_transfer(
				array(
					'topics' => array( '0x' . str_repeat( 'a', 64 ), '0x' . str_repeat( '0', 64 ), '0x' . str_repeat( '0', 64 ) ),
					'data'   => '0x1',
				)
			)
		);

		$this->assertNull(
			Erc20::decode_transfer(
				array(
					'topics' => array( Erc20::TRANSFER_TOPIC ),
					'data'   => '0x1',
				)
			)
		);
	}
}
