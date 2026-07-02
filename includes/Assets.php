<?php
/**
 * The catalogue of crypto assets this plugin can accept.
 *
 * An asset is a coin the buyer can pay in on a specific network: the native coin
 * of an EVM chain (ETH), an ERC-20 stablecoin on an EVM chain (USDC / USDT), or
 * native Bitcoin. Everything downstream — pricing, the pay page, and the
 * verifiers — reads asset facts (decimals, token contract, coingecko id) from
 * here so there is one authoritative table.
 *
 * Token contract addresses are the canonical issuer contracts for each chain and
 * are checksummed. An incorrect contract would let a worthless look-alike token
 * satisfy an order, so these are treated as security-critical constants.
 *
 * @package ShadowEth
 */

namespace ShadowEth;

defined( 'ABSPATH' ) || exit;

/**
 * Static registry of payable assets.
 */
final class Assets {

	/**
	 * EVM native ETH asset id prefix (per network the id is "eth:<network>").
	 */
	public const KIND_NATIVE_EVM = 'native_evm';

	/**
	 * ERC-20 token asset kind.
	 */
	public const KIND_ERC20 = 'erc20';

	/**
	 * Native Bitcoin asset kind.
	 */
	public const KIND_BTC = 'btc';

	/**
	 * Every asset, keyed by a stable asset id ("symbol:network").
	 *
	 * @return array<string,array{
	 *     id:string,
	 *     kind:string,
	 *     symbol:string,
	 *     label:string,
	 *     network:string,
	 *     decimals:int,
	 *     coingecko_id:string,
	 *     contract:string
	 * }>
	 */
	public static function all(): array {
		$assets = array();

		// EVM assets: native ETH + USDC + USDT on each EVM network.
		foreach ( Networks::all() as $slug => $network ) {
			$assets[ 'eth:' . $slug ] = array(
				'id'           => 'eth:' . $slug,
				'kind'         => self::KIND_NATIVE_EVM,
				'symbol'       => 'ETH',
				'label'        => __( 'Ether (ETH)', 'shadowledger-crypto-for-woocommerce' ),
				'network'      => $slug,
				'decimals'     => 18,
				'coingecko_id' => 'ethereum',
				'contract'     => '',
			);

			foreach ( self::evm_tokens( $slug ) as $token ) {
				$assets[ $token['id'] ] = $token;
			}
		}

		// Native Bitcoin.
		$assets['btc:bitcoin'] = array(
			'id'           => 'btc:bitcoin',
			'kind'         => self::KIND_BTC,
			'symbol'       => 'BTC',
			'label'        => __( 'Bitcoin (BTC)', 'shadowledger-crypto-for-woocommerce' ),
			'network'      => 'bitcoin',
			'decimals'     => 8,
			'coingecko_id' => 'bitcoin',
			'contract'     => '',
		);

		return $assets;
	}

	/**
	 * The ERC-20 stablecoin assets available on a given EVM network. Only chains
	 * with canonical, issuer-native USDC/USDT deployments are listed; a chain
	 * without a given token simply omits it.
	 *
	 * @param string $network EVM network slug.
	 * @return array<int,array{id:string,kind:string,symbol:string,label:string,network:string,decimals:int,coingecko_id:string,contract:string}>
	 */
	private static function evm_tokens( string $network ): array {
		// symbol => [ network => [contract, decimals] ]. Canonical, checksummed
		// issuer contracts. Decimals: USDC/USDT are 6 on every chain here.
		$map = array(
			'USDC' => array(
				'coingecko_id' => 'usd-coin',
				'label'        => __( 'USD Coin (USDC)', 'shadowledger-crypto-for-woocommerce' ),
				'decimals'     => 6,
				'contracts'    => array(
					'ethereum' => '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48',
					'base'     => '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
					'arbitrum' => '0xaf88d065e77c8cC2239327C5EDb3A432268e5831',
					'optimism' => '0x0b2C639c533813f4Aa9D7837CAf62653d097Ff85',
				),
			),
			'USDT' => array(
				'coingecko_id' => 'tether',
				'label'        => __( 'Tether (USDT)', 'shadowledger-crypto-for-woocommerce' ),
				'decimals'     => 6,
				'contracts'    => array(
					'ethereum' => '0xdAC17F958D2ee523a2206206994597C13D831ec7',
					'arbitrum' => '0xFd086bC7CD5C481DCC9C85ebE478A1C0b69FCbb9',
					'optimism' => '0x94b008aA00579c1307B0EF2c499aD98a8ce58e58',
				),
			),
		);

		$out = array();

		foreach ( $map as $symbol => $token ) {
			if ( ! isset( $token['contracts'][ $network ] ) ) {
				continue;
			}

			$id = strtolower( $symbol ) . ':' . $network;

			$out[] = array(
				'id'           => $id,
				'kind'         => self::KIND_ERC20,
				'symbol'       => $symbol,
				'label'        => $token['label'],
				'network'      => $network,
				'decimals'     => $token['decimals'],
				'coingecko_id' => $token['coingecko_id'],
				'contract'     => $token['contracts'][ $network ],
			);
		}

		return $out;
	}

	/**
	 * A single asset by id, or null if unknown.
	 *
	 * @param string $id Asset id ("symbol:network").
	 * @return array{id:string,kind:string,symbol:string,label:string,network:string,decimals:int,coingecko_id:string,contract:string}|null
	 */
	public static function get( string $id ): ?array {
		$all = self::all();

		return $all[ $id ] ?? null;
	}

	/**
	 * Whether an asset id is known.
	 *
	 * @param string $id Asset id.
	 */
	public static function is_supported( string $id ): bool {
		return null !== self::get( $id );
	}

	/**
	 * All asset ids.
	 *
	 * @return string[]
	 */
	public static function ids(): array {
		return array_keys( self::all() );
	}

	/**
	 * Whether an asset is on the EVM family (native ETH or ERC-20).
	 *
	 * @param array{kind:string} $asset Asset record.
	 */
	public static function is_evm( array $asset ): bool {
		return self::KIND_NATIVE_EVM === $asset['kind'] || self::KIND_ERC20 === $asset['kind'];
	}
}
