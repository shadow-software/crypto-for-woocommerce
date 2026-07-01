<?php
/**
 * The EVM networks this plugin can accept native ETH on.
 *
 * All RPC endpoints are free, keyless public nodes. Merchants may override any
 * of them with their own endpoint on the settings screen. Confirmation counts
 * default to conservative, reorg-safe values per chain and are also merchant
 * tunable.
 *
 * @package ShadowEth
 */

namespace ShadowEth;

defined( 'ABSPATH' ) || exit;

/**
 * Static registry of supported EVM networks. Everything downstream (the RPC
 * client, the verifier, the settings screen, the buyer instructions) reads chain
 * facts from here so there is one source of truth.
 */
final class Networks {

	/**
	 * Return every supported network keyed by its slug.
	 *
	 * @return array<string,array{
	 *     slug:string,
	 *     name:string,
	 *     chain_id:int,
	 *     currency:string,
	 *     default_rpc:string,
	 *     default_confirmations:int,
	 *     block_time:int,
	 *     explorer_tx:string,
	 *     explorer_address:string
	 * }>
	 */
	public static function all(): array {
		return array(
			'ethereum' => array(
				'slug'                  => 'ethereum',
				'name'                  => __( 'Ethereum', 'crypto-woocommerce' ),
				'chain_id'              => 1,
				'currency'              => 'ETH',
				'default_rpc'           => 'https://ethereum-rpc.publicnode.com',
				'default_confirmations' => 3,
				'block_time'            => 12,
				'explorer_tx'           => 'https://etherscan.io/tx/',
				'explorer_address'      => 'https://etherscan.io/address/',
			),
			'base'     => array(
				'slug'                  => 'base',
				'name'                  => __( 'Base', 'crypto-woocommerce' ),
				'chain_id'              => 8453,
				'currency'              => 'ETH',
				'default_rpc'           => 'https://base-rpc.publicnode.com',
				'default_confirmations' => 5,
				'block_time'            => 2,
				'explorer_tx'           => 'https://basescan.org/tx/',
				'explorer_address'      => 'https://basescan.org/address/',
			),
			'arbitrum' => array(
				'slug'                  => 'arbitrum',
				'name'                  => __( 'Arbitrum One', 'crypto-woocommerce' ),
				'chain_id'              => 42161,
				'currency'              => 'ETH',
				'default_rpc'           => 'https://arbitrum-one-rpc.publicnode.com',
				'default_confirmations' => 5,
				'block_time'            => 1,
				'explorer_tx'           => 'https://arbiscan.io/tx/',
				'explorer_address'      => 'https://arbiscan.io/address/',
			),
			'optimism' => array(
				'slug'                  => 'optimism',
				'name'                  => __( 'OP Mainnet', 'crypto-woocommerce' ),
				'chain_id'              => 10,
				'currency'              => 'ETH',
				'default_rpc'           => 'https://optimism-rpc.publicnode.com',
				'default_confirmations' => 5,
				'block_time'            => 2,
				'explorer_tx'           => 'https://optimistic.etherscan.io/tx/',
				'explorer_address'      => 'https://optimistic.etherscan.io/address/',
			),
		);
	}

	/**
	 * Return a single network by slug, or null if the slug is unknown.
	 *
	 * @param string $slug Network slug.
	 * @return array{
	 *     slug:string,
	 *     name:string,
	 *     chain_id:int,
	 *     currency:string,
	 *     default_rpc:string,
	 *     default_confirmations:int,
	 *     block_time:int,
	 *     explorer_tx:string,
	 *     explorer_address:string
	 * }|null
	 */
	public static function get( string $slug ): ?array {
		$all = self::all();

		return $all[ $slug ] ?? null;
	}

	/**
	 * The slugs of every supported network.
	 *
	 * @return string[]
	 */
	public static function slugs(): array {
		return array_keys( self::all() );
	}

	/**
	 * Whether a slug is a supported network.
	 *
	 * @param string $slug Candidate network slug.
	 */
	public static function is_supported( string $slug ): bool {
		return null !== self::get( $slug );
	}

	/**
	 * A human explorer URL for a transaction hash on a given network, or '' when
	 * the network or hash is not usable.
	 *
	 * @param string $slug    Network slug.
	 * @param string $tx_hash 0x-prefixed transaction hash.
	 */
	public static function explorer_tx_url( string $slug, string $tx_hash ): string {
		$network = self::get( $slug );

		if ( null === $network || 1 !== preg_match( '/^0x[0-9a-fA-F]{64}$/', $tx_hash ) ) {
			return '';
		}

		return $network['explorer_tx'] . $tx_hash;
	}
}
