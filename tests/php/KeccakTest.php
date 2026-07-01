<?php
/**
 * Tests for the bundled Keccak-256 implementation.
 *
 * @package ShadowEth
 */

namespace ShadowEth\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShadowEth\Keccak;

/**
 * Verifies Keccak-256 against published test vectors. Correctness here underpins
 * EIP-55 checksum validation, which protects the merchant's receiving address.
 */
final class KeccakTest extends TestCase {

	/**
	 * Known Keccak-256 digests (not SHA3-256 — the pre-standard variant).
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function vectors(): array {
		return array(
			'empty'   => array( '', 'c5d2460186f7233c927e7db2dcc703c0e500b653ca82273b7bfad8045d85a470' ),
			'abc'     => array( 'abc', '4e03657aea45a94fc7d47ba826c8d667c0d1e6e33a64a036ec44f58fa12d6c45' ),
			'testing' => array( 'testing', '5f16f4c7f149ac4f9510d9cf8cf384038ad348b3bcdc01915f95de12df9d1b02' ),
		);
	}

	/**
	 * Each vector hashes to its expected digest.
	 *
	 * @param string $input  Input string.
	 * @param string $expect Expected hex digest.
	 */
	#[DataProvider( 'vectors' )]
	public function test_hash_matches_vector( string $input, string $expect ): void {
		$this->assertSame( $expect, bin2hex( Keccak::hash256( $input ) ) );
	}
}
