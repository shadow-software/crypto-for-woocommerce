<?php
/**
 * A tiny, dependency-free Ethereum JSON-RPC client.
 *
 * Talks to a free public node (or the merchant's own endpoint) over
 * wp_remote_post — no cURL requirement, no Composer package, nothing to sign.
 * It only exposes the handful of read-only methods the verifier needs:
 * eth_blockNumber, eth_getTransactionByHash, eth_getTransactionReceipt, and
 * eth_getBlockByNumber. Never sends a transaction and never sees a private key.
 *
 * @package ShadowEth
 */

namespace ShadowEth;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only JSON-RPC client with a primary + optional fallback endpoint.
 */
final class RpcClient {

	/**
	 * Primary RPC endpoint URL.
	 *
	 * @var string
	 */
	private string $primary;

	/**
	 * Optional fallback RPC endpoint URL, tried if the primary errors.
	 *
	 * @var string
	 */
	private string $fallback;

	/**
	 * Per-request timeout in seconds.
	 *
	 * @var int
	 */
	private int $timeout;

	/**
	 * The network slug this client is expected to be talking to, or '' if unknown.
	 *
	 * @var string
	 */
	private string $network_slug;

	/**
	 * Construct a client for a pair of endpoints.
	 *
	 * @param string $primary      Primary endpoint URL (https).
	 * @param string $fallback     Optional fallback endpoint URL, or '' for none.
	 * @param int    $timeout      Per-request timeout in seconds.
	 * @param string $network_slug The network this client should be on, for the
	 *                             chain-id assertion. '' disables the check.
	 */
	public function __construct( string $primary, string $fallback = '', int $timeout = 12, string $network_slug = '' ) {
		$this->primary      = $primary;
		$this->fallback     = $fallback;
		$this->timeout      = max( 3, $timeout );
		$this->network_slug = $network_slug;
	}

	/**
	 * Build a client for a given network slug from the gateway configuration.
	 *
	 * @param string  $network_slug Network slug from Networks.
	 * @param Gateway $gateway      The configured gateway (for RPC overrides).
	 */
	public static function for_network( string $network_slug, Gateway $gateway ): self {
		$network = Networks::get( $network_slug );

		$primary  = $gateway->get_rpc_url( $network_slug );
		$fallback = '';

		if ( '' === $primary && null !== $network ) {
			$primary = $network['default_rpc'];
		}

		return new self( $primary, $fallback, 12, $network_slug );
	}

	/**
	 * Assert the connected node really is on the expected chain, by comparing its
	 * eth_chainId to the chain id in the Networks registry. This stops a
	 * misconfigured or malicious RPC endpoint (e.g. pointed at a testnet or an
	 * attacker's node) from having forged or wrong-chain transactions accepted as
	 * real payments. The verified result is cached per endpoint so it costs at
	 * most one extra call occasionally.
	 *
	 * @throws RpcException When the chain id cannot be read or does not match.
	 * @return void
	 */
	public function assert_chain(): void {
		if ( '' === $this->network_slug ) {
			return;
		}

		$network = Networks::get( $this->network_slug );

		if ( null === $network ) {
			return;
		}

		$expected  = (int) $network['chain_id'];
		$cache_key = 'shadow_eth_chainok_' . md5( $this->primary . '|' . $expected );

		if ( 'ok' === get_transient( $cache_key ) ) {
			return;
		}

		$result = $this->call( 'eth_chainId', array() );

		if ( ! is_string( $result ) || 1 !== preg_match( '/^0x[0-9a-fA-F]+$/', $result ) ) {
			throw new RpcException( esc_html__( 'Could not verify the network of the RPC endpoint.', 'shadowchain-crypto-for-woocommerce' ) );
		}

		$actual_hex = ltrim( substr( $result, 2 ), '0' );
		$actual     = '' === $actual_hex ? 0 : (int) hexdec( $actual_hex );

		if ( $actual !== $expected ) {
			throw new RpcException(
				esc_html(
					sprintf(
						/* translators: 1: expected chain id, 2: actual chain id. */
						__( 'RPC endpoint is on the wrong network (expected chain %1$d, got %2$d).', 'shadowchain-crypto-for-woocommerce' ),
						$expected,
						$actual
					)
				)
			);
		}

		// Cache the good result for an hour to avoid an extra call every poll.
		set_transient( $cache_key, 'ok', HOUR_IN_SECONDS );
	}

	/**
	 * Current chain head block number as an integer.
	 *
	 * @throws RpcException When the call fails on all endpoints.
	 */
	public function block_number(): int {
		$result = $this->call( 'eth_blockNumber', array() );

		// A well-behaved node returns a 0x hex string; anything else is bogus.
		if ( ! is_string( $result ) || 1 !== preg_match( '/^0x[0-9a-fA-F]+$/', $result ) ) {
			throw new RpcException( esc_html__( 'Invalid block number from RPC endpoint.', 'shadowchain-crypto-for-woocommerce' ) );
		}

		$hex = ltrim( substr( $result, 2 ), '0' );

		// Reject an absurd height that would overflow int into a lossy float.
		if ( strlen( $hex ) > 15 ) {
			throw new RpcException( esc_html__( 'Out-of-range block number from RPC endpoint.', 'shadowchain-crypto-for-woocommerce' ) );
		}

		return '' === $hex ? 0 : (int) hexdec( $hex );
	}

	/**
	 * Fetch a transaction by hash. Returns the decoded object or null if the node
	 * does not (yet) know the hash.
	 *
	 * @param string $tx_hash 0x-prefixed 32-byte transaction hash.
	 * @return array<string,mixed>|null
	 * @throws RpcException When the call fails on all endpoints.
	 */
	public function get_transaction( string $tx_hash ): ?array {
		$result = $this->call( 'eth_getTransactionByHash', array( $tx_hash ) );

		return is_array( $result ) ? $result : null;
	}

	/**
	 * Fetch a transaction receipt by hash. Null until the tx is mined; the receipt
	 * carries the authoritative success/failure status and block number.
	 *
	 * @param string $tx_hash 0x-prefixed 32-byte transaction hash.
	 * @return array<string,mixed>|null
	 * @throws RpcException When the call fails on all endpoints.
	 */
	public function get_transaction_receipt( string $tx_hash ): ?array {
		$result = $this->call( 'eth_getTransactionReceipt', array( $tx_hash ) );

		return is_array( $result ) ? $result : null;
	}

	/**
	 * Fetch a block by number, optionally with full transaction objects.
	 *
	 * @param int  $block_number       Block number.
	 * @param bool $full_transactions  Whether to include full tx objects.
	 * @return array<string,mixed>|null
	 * @throws RpcException When the call fails on all endpoints.
	 */
	public function get_block_by_number( int $block_number, bool $full_transactions = true ): ?array {
		$result = $this->call(
			'eth_getBlockByNumber',
			array( '0x' . dechex( max( 0, $block_number ) ), $full_transactions )
		);

		return is_array( $result ) ? $result : null;
	}

	/**
	 * Query event logs (eth_getLogs) over a block range, filtered by contract
	 * address and topics. Used to find ERC-20 Transfer events efficiently instead
	 * of walking every transaction in every block.
	 *
	 * @param int                $from_block Inclusive from block.
	 * @param int                $to_block   Inclusive to block.
	 * @param string             $address    Contract address to filter on.
	 * @param array<int,?string> $topics Topic filter (null = wildcard per slot).
	 * @return array<int,array<string,mixed>>
	 * @throws RpcException When the call fails on all endpoints.
	 */
	public function get_logs( int $from_block, int $to_block, string $address, array $topics ): array {
		$result = $this->call(
			'eth_getLogs',
			array(
				array(
					'fromBlock' => '0x' . dechex( max( 0, $from_block ) ),
					'toBlock'   => '0x' . dechex( max( 0, $to_block ) ),
					'address'   => $address,
					'topics'    => $topics,
				),
			)
		);

		if ( ! is_array( $result ) ) {
			return array();
		}

		// Keep only well-formed log entries (associative arrays).
		return array_values(
			array_filter(
				$result,
				static function ( $log ) {
					return is_array( $log );
				}
			)
		);
	}

	/**
	 * Perform a JSON-RPC call, trying the primary then the fallback endpoint.
	 *
	 * @param string       $method JSON-RPC method name.
	 * @param array<mixed> $params Positional parameters.
	 * @return mixed The decoded "result" value.
	 * @throws RpcException When both endpoints fail.
	 */
	private function call( string $method, array $params ) {
		$endpoints = array_values( array_filter( array( $this->primary, $this->fallback ) ) );

		if ( empty( $endpoints ) ) {
			throw new RpcException( 'No RPC endpoint configured.' );
		}

		$last_error = 'RPC call failed.';

		foreach ( $endpoints as $endpoint ) {
			try {
				return $this->request( $endpoint, $method, $params );
			} catch ( RpcException $e ) {
				$last_error = $e->getMessage();
				Logger::warn( sprintf( 'RPC %s via %s failed: %s', $method, $endpoint, $e->getMessage() ) );
			}
		}

		throw new RpcException( esc_html( $last_error ) );
	}

	/**
	 * Perform a single JSON-RPC request against one endpoint.
	 *
	 * @param string       $endpoint Endpoint URL.
	 * @param string       $method   JSON-RPC method.
	 * @param array<mixed> $params   Positional parameters.
	 * @return mixed The decoded "result" value.
	 * @throws RpcException On transport error or a JSON-RPC error object.
	 */
	private function request( string $endpoint, string $method, array $params ) {
		$body = wp_json_encode(
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => $method,
				'params'  => $params,
			)
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout'            => $this->timeout,
				'redirection'        => 0,
				'reject_unsafe_urls' => true,
				'headers'            => array( 'Content-Type' => 'application/json' ),
				'body'               => $body,
				'user-agent'         => 'shadowchain-crypto-for-woocommerce/' . SHADOW_ETH_VERSION . '; ' . home_url(),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new RpcException( esc_html( $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			throw new RpcException( esc_html( sprintf( 'HTTP %d from RPC endpoint.', $code ) ) );
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) ) {
			throw new RpcException( 'Malformed RPC response.' );
		}

		if ( isset( $decoded['error'] ) ) {
			$message = is_array( $decoded['error'] ) && isset( $decoded['error']['message'] )
				? (string) $decoded['error']['message']
				: 'RPC error.';

			throw new RpcException( esc_html( $message ) );
		}

		// A successful JSON-RPC response has a "result" key; a null result (e.g. an
		// unknown tx hash) is legitimate and returned as-is.
		if ( ! array_key_exists( 'result', $decoded ) ) {
			throw new RpcException( 'RPC response missing result.' );
		}

		return $decoded['result'];
	}
}
