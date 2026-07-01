<?php
/**
 * A tiny, dependency-free Bitcoin block-explorer client.
 *
 * Reads the Bitcoin blockchain through free, keyless Esplora-compatible REST
 * APIs (mempool.space primary, blockstream.info fallback), the Bitcoin
 * equivalent of the EVM RPC client. It only performs read-only GETs — a
 * transaction lookup, an address's transactions, and the chain tip height — and
 * never broadcasts anything.
 *
 * @package ShadowEth
 */

namespace ShadowEth;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only Esplora client with a primary + fallback base URL.
 */
final class BtcExplorer {

	/**
	 * Ordered list of Esplora API base URLs (no trailing slash).
	 *
	 * @var string[]
	 */
	private array $bases;

	/**
	 * Per-request timeout in seconds.
	 *
	 * @var int
	 */
	private int $timeout;

	/**
	 * Construct with an ordered set of base URLs.
	 *
	 * @param string[] $bases   Esplora base URLs (primary first).
	 * @param int      $timeout Per-request timeout in seconds.
	 */
	public function __construct( array $bases, int $timeout = 12 ) {
		$this->bases   = array_values( array_filter( $bases ) );
		$this->timeout = max( 3, $timeout );
	}

	/**
	 * Build the default explorer (mempool.space + blockstream.info), honouring a
	 * merchant override base URL if one is configured.
	 *
	 * @param string $override Optional merchant Esplora base URL, or ''.
	 */
	public static function create( string $override = '' ): self {
		$bases = array();

		$override = trim( $override );

		// Only accept an HTTPS override: an http:// explorer could be tampered with
		// in transit to forge a confirmation. The free defaults are always HTTPS.
		if ( '' !== $override && wp_http_validate_url( $override ) && 0 === strpos( strtolower( $override ), 'https://' ) ) {
			$bases[] = untrailingslashit( $override );
		}

		$bases[] = 'https://mempool.space/api';
		$bases[] = 'https://blockstream.info/api';

		return new self( $bases );
	}

	/**
	 * The current chain tip height.
	 *
	 * @throws RpcException When no endpoint responds.
	 */
	public function tip_height(): int {
		$body = $this->get( '/blocks/tip/height' );

		if ( 1 !== preg_match( '/^\d+$/', trim( $body ) ) ) {
			throw new RpcException( esc_html__( 'Invalid tip height from the Bitcoin explorer.', 'accept-crypto-for-woocommerce' ) );
		}

		return (int) trim( $body );
	}

	/**
	 * Fetch a transaction by id, decoded, or null if not found.
	 *
	 * @param string $txid Transaction id (64 hex chars).
	 * @return array<string,mixed>|null
	 * @throws RpcException When no endpoint responds with a usable result.
	 */
	public function get_tx( string $txid ): ?array {
		if ( 1 !== preg_match( '/^[0-9a-fA-F]{64}$/', $txid ) ) {
			return null;
		}

		$path       = '/tx/' . strtolower( $txid );
		$last_error = 'Bitcoin explorer request failed.';
		$saw_404    = false;

		// Try each endpoint. A genuine 404 (unknown tx) => not found yet, return
		// null so the poller keeps waiting. A 5xx/429/transport error on ALL
		// endpoints is a real failure and is thrown so the caller logs + retries,
		// rather than being silently mistaken for "no such transaction".
		foreach ( $this->bases as $base ) {
			$response = $this->request( $base . $path );

			if ( is_wp_error( $response ) ) {
				$last_error = $response->get_error_message();
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( 404 === $code ) {
				$saw_404 = true;
				continue;
			}

			if ( $code < 200 || $code >= 300 ) {
				$last_error = sprintf( 'HTTP %d from Bitcoin explorer.', $code );
				continue;
			}

			$data = json_decode( wp_remote_retrieve_body( $response ), true );

			return is_array( $data ) ? $data : null;
		}

		if ( $saw_404 ) {
			return null;
		}

		throw new RpcException( esc_html( $last_error ) );
	}

	/**
	 * Fetch the confirmed transactions touching an address (most recent first).
	 *
	 * @param string $address Bitcoin address.
	 * @return array<int,array<string,mixed>>
	 * @throws RpcException When no endpoint responds.
	 */
	public function get_address_txs( string $address ): array {
		$address = BtcAddress::normalize( $address );

		if ( '' === $address ) {
			return array();
		}

		$body = $this->get( '/address/' . rawurlencode( $address ) . '/txs' );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return array();
		}

		return array_values(
			array_filter(
				$data,
				static function ( $tx ) {
					return is_array( $tx );
				}
			)
		);
	}

	/**
	 * GET a path from the first responding base URL.
	 *
	 * @param string $path Path beginning with '/'.
	 * @return string Response body.
	 * @throws RpcException When every endpoint fails.
	 */
	private function get( string $path ): string {
		$last_error = 'Bitcoin explorer request failed.';

		foreach ( $this->bases as $base ) {
			$response = $this->request( $base . $path );

			if ( is_wp_error( $response ) ) {
				$last_error = $response->get_error_message();
				continue;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( $code < 200 || $code >= 300 ) {
				$last_error = sprintf( 'HTTP %d from Bitcoin explorer.', $code );
				continue;
			}

			return (string) wp_remote_retrieve_body( $response );
		}

		throw new RpcException( esc_html( $last_error ) );
	}

	/**
	 * Perform one GET against a full URL.
	 *
	 * @param string $url The full request URL.
	 * @return array<string,mixed>|\WP_Error The wp_remote_get response.
	 */
	private function request( string $url ) {
		return wp_remote_get(
			$url,
			array(
				'timeout'            => $this->timeout,
				'redirection'        => 2,
				'reject_unsafe_urls' => true,
				'headers'            => array( 'Accept' => 'application/json' ),
				'user-agent'         => 'accept-crypto-for-woocommerce/' . SHADOW_ETH_VERSION . '; ' . home_url(),
			)
		);
	}
}
