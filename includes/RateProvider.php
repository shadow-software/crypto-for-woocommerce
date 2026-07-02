<?php
/**
 * ETH ⇄ fiat exchange-rate provider.
 *
 * Uses the free, keyless CoinGecko Simple Price endpoint and caches each result
 * in a short-lived transient so a burst of checkouts makes at most one call per
 * currency per cache window. If the rate is unavailable, checkout with this
 * gateway fails closed (the merchant would rather lose a sale than mis-price an
 * order).
 *
 * @package ShadowEth
 */

namespace ShadowEth;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches and caches the price of 1 ETH in a store currency.
 */
final class RateProvider {

	/**
	 * CoinGecko simple-price endpoint (free, no API key).
	 */
	private const ENDPOINT = 'https://api.coingecko.com/api/v3/simple/price';

	/**
	 * Transient TTL in seconds. Short enough to track the market, long enough to
	 * shield the free endpoint from rate limits under load.
	 */
	private const CACHE_TTL = 120;

	/**
	 * Lowest plausible unit price for any supported asset in any supported fiat.
	 * A ~$1 stablecoin in a strong currency is still well above this floor, so it
	 * only ever rejects garbage near-zero quotes.
	 */
	private const MIN_PLAUSIBLE_PRICE = 0.0001;

	/**
	 * Highest plausible unit price for any supported asset in any supported fiat.
	 * Generous headroom over six-figure BTC in weak-currency quotes (e.g. JPY) so
	 * real prices never trip it, but a 1e30 glitch is rejected.
	 */
	private const MAX_PLAUSIBLE_PRICE = 100000000000.0;

	/**
	 * Fiat currencies CoinGecko's simple endpoint supports as vs_currencies.
	 * WooCommerce stores in currencies outside this set cannot be auto-converted;
	 * the gateway hides itself for them.
	 *
	 * @var string[]
	 */
	private const SUPPORTED = array(
		'usd',
		'eur',
		'gbp',
		'aud',
		'cad',
		'chf',
		'cny',
		'jpy',
		'inr',
		'brl',
		'nzd',
		'sgd',
		'hkd',
		'zar',
		'sek',
		'nok',
		'dkk',
		'pln',
		'mxn',
		'try',
	);

	/**
	 * Whether a store currency can be converted to ETH by this provider.
	 *
	 * @param string $currency ISO 4217 currency code (any case).
	 */
	public static function supports( string $currency ): bool {
		return in_array( strtolower( $currency ), self::SUPPORTED, true );
	}

	/**
	 * The price of 1 ETH in the given currency (back-compat wrapper).
	 *
	 * @param string $currency ISO 4217 currency code.
	 * @return string|null Decimal price string or null.
	 */
	public static function eth_price( string $currency ): ?string {
		return self::asset_price( 'ethereum', $currency );
	}

	/**
	 * The price of one unit of an asset (identified by its CoinGecko id) in the
	 * given currency, as a decimal string, or null if it cannot be fetched. Cached
	 * in a transient for CACHE_TTL seconds.
	 *
	 * @param string $coingecko_id CoinGecko coin id (e.g. 'ethereum', 'bitcoin', 'usd-coin', 'tether').
	 * @param string $currency     ISO 4217 currency code.
	 * @return string|null Decimal price string (e.g. "3521.44") or null.
	 */
	public static function asset_price( string $coingecko_id, string $currency ): ?string {
		$currency     = strtolower( $currency );
		$coingecko_id = strtolower( trim( $coingecko_id ) );

		if ( ! self::supports( $currency ) || 1 !== preg_match( '/^[a-z0-9\-]+$/', $coingecko_id ) ) {
			return null;
		}

		$cache_key = 'shadow_eth_rate_' . $coingecko_id . '_' . $currency;
		$cached    = get_transient( $cache_key );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$url = add_query_arg(
			array(
				'ids'           => $coingecko_id,
				'vs_currencies' => $currency,
				'precision'     => 'full',
			),
			self::ENDPOINT
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout'            => 10,
				'reject_unsafe_urls' => true,
				'headers'            => array( 'Accept' => 'application/json' ),
				'user-agent'         => 'shadow-software-crypto-for-woocommerce/' . SHADOW_ETH_VERSION . '; ' . home_url(),
			)
		);

		if ( is_wp_error( $response ) ) {
			Logger::warn( 'Rate fetch failed: ' . $response->get_error_message() );

			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			Logger::warn( sprintf( 'Rate fetch HTTP %d.', $code ) );

			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) || ! isset( $data[ $coingecko_id ][ $currency ] ) ) {
			Logger::warn( 'Rate response malformed.' );

			return null;
		}

		$price = $data[ $coingecko_id ][ $currency ];

		// Normalise to a plain decimal string; reject non-positive/garbage.
		if ( ! is_numeric( $price ) || (float) $price <= 0 ) {
			return null;
		}

		// Sanity-bound the rate. A poisoned or glitched response quoting an absurd
		// price would badly mis-price orders (e.g. a near-zero price would let a
		// buyer pay almost nothing). Accept only a plausible price band wide enough
		// to cover any of our assets (a ~$1 stablecoin up to six-figure BTC) in any
		// supported fiat, but which still rejects a garbage 1e-9 or 1e30.
		$price_float = (float) $price;

		if ( $price_float < self::MIN_PLAUSIBLE_PRICE || $price_float > self::MAX_PLAUSIBLE_PRICE ) {
			Logger::warn( 'Rate out of plausible range; ignoring.' );

			return null;
		}

		$price = self::format_decimal( (string) $price );

		set_transient( $cache_key, $price, self::CACHE_TTL );

		return $price;
	}

	/**
	 * Convert a fiat amount to an integer count of an asset's smallest units,
	 * using the live price of that asset. Returns null if the rate is unavailable.
	 *
	 * @param string $fiat_amount  Decimal fiat amount (e.g. "49.99").
	 * @param string $currency     ISO 4217 currency code.
	 * @param string $coingecko_id CoinGecko id of the asset.
	 * @param int    $decimals     Decimals of the asset's smallest unit.
	 * @return string|null Integer base-units decimal string, or null.
	 */
	public static function fiat_to_base_units( string $fiat_amount, string $currency, string $coingecko_id, int $decimals ): ?string {
		$price = self::asset_price( $coingecko_id, $currency );

		if ( null === $price ) {
			return null;
		}

		if ( 1 !== preg_match( '/^\d+(\.\d+)?$/', trim( $fiat_amount ) ) ) {
			return null;
		}

		if ( function_exists( 'bcdiv' ) ) {
			// amount_in_asset = fiat / price, kept at full asset precision, then
			// scaled to integer base units.
			$in_asset = bcdiv( trim( $fiat_amount ), $price, max( 0, $decimals ) );

			return Money::to_base_units( $in_asset, $decimals );
		}

		$in_asset = (string) ( (float) $fiat_amount / (float) $price );

		return Money::to_base_units( $in_asset, $decimals );
	}

	/**
	 * Convert a fiat amount to a wei amount using the live ETH price.
	 * Returns the integer wei string, or null if the rate is unavailable.
	 *
	 * @param string $fiat_amount Decimal fiat amount (e.g. "49.99").
	 * @param string $currency    ISO 4217 currency code.
	 * @return string|null Integer wei decimal string, or null.
	 */
	public static function fiat_to_wei( string $fiat_amount, string $currency ): ?string {
		$price = self::eth_price( $currency );

		if ( null === $price ) {
			return null;
		}

		if ( 1 !== preg_match( '/^\d+(\.\d+)?$/', trim( $fiat_amount ) ) ) {
			return null;
		}

		// eth = fiat / price; wei = eth * 1e18. Compute with BC Math at 18 dp so
		// the division keeps full wei precision, then convert to integer wei.
		if ( function_exists( 'bcdiv' ) ) {
			$eth = bcdiv( trim( $fiat_amount ), $price, Money::WEI_DECIMALS );

			return Money::eth_to_wei( $eth );
		}

		// BC-less fallback: acceptable on hosts without the extension.
		$eth = (string) ( (float) $fiat_amount / (float) $price );

		return Money::eth_to_wei( $eth );
	}

	/**
	 * Trim a numeric string to a plain decimal (strip scientific notation that a
	 * JSON decoder may emit for very small/large numbers).
	 *
	 * @param string $value Numeric string.
	 */
	private static function format_decimal( string $value ): string {
		if ( false === stripos( $value, 'e' ) ) {
			return $value;
		}

		return rtrim( rtrim( sprintf( '%.18F', (float) $value ), '0' ), '.' );
	}
}
