<?php
/**
 * Plugin bootstrap: wires the gateway, pay page, poller, Blocks support, and the
 * i18n/asset plumbing together.
 *
 * @package ShadowEth
 */

namespace ShadowEth;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton container for the plugin's moving parts.
 */
final class Plugin {

	/**
	 * The one instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * The payment checker.
	 *
	 * @var PaymentChecker|null
	 */
	private ?PaymentChecker $checker = null;

	/**
	 * Cached gateway instance.
	 *
	 * @var Gateway|null
	 */
	private ?Gateway $gateway = null;

	/**
	 * Private constructor — use instance().
	 */
	private function __construct() {}

	/**
	 * The singleton accessor.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register all hooks. Called once WooCommerce is confirmed active.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'woocommerce_payment_gateways', array( $this, 'register_gateway' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SHADOW_ETH_FILE ), array( $this, 'plugin_action_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2 );

		$this->checker = new PaymentChecker();
		$this->checker->register();

		// Free a transaction's replay claim if its order is cancelled or refunded,
		// so the same buyer can legitimately reuse a wallet later without the hash
		// being permanently burned.
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'release_tx_claim' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'release_tx_claim' ) );

		// The pay page + submission handler need the configured gateway. Building
		// it lazily on init keeps a single source of settings.
		add_action( 'init', array( $this, 'boot_pay_page' ) );

		// Blocks (Cart & Checkout) integration.
		add_action( 'woocommerce_blocks_loaded', array( $this, 'register_blocks_support' ) );
	}

	/**
	 * Register the gateway with WooCommerce.
	 *
	 * @param array<int,string> $gateways Registered gateway class names.
	 * @return array<int,string>
	 */
	public function register_gateway( array $gateways ): array {
		$gateways[] = Gateway::class;

		return $gateways;
	}

	/**
	 * Build the gateway + pay page once WordPress is initialised.
	 *
	 * @return void
	 */
	public function boot_pay_page(): void {
		$gateway = $this->get_gateway();

		if ( ! $gateway instanceof Gateway || ! $this->checker instanceof PaymentChecker ) {
			return;
		}

		( new PayPage( $gateway, $this->checker ) )->register();
	}

	/**
	 * The configured gateway instance (cached).
	 */
	public function get_gateway(): ?Gateway {
		if ( $this->gateway instanceof Gateway ) {
			return $this->gateway;
		}

		if ( ! class_exists( 'WC_Payment_Gateways' ) ) {
			return null;
		}

		$gateways = \WC_Payment_Gateways::instance()->payment_gateways();

		if ( isset( $gateways[ SHADOW_ETH_GATEWAY_ID ] ) && $gateways[ SHADOW_ETH_GATEWAY_ID ] instanceof Gateway ) {
			$this->gateway = $gateways[ SHADOW_ETH_GATEWAY_ID ];

			return $this->gateway;
		}

		// Fallback: instantiate directly (e.g. during cron before the registry is
		// populated). Settings are loaded from the same option either way.
		$this->gateway = new Gateway();

		return $this->gateway;
	}

	/**
	 * Register the WooCommerce Blocks checkout integration.
	 *
	 * @return void
	 */
	public function register_blocks_support(): void {
		if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
			return;
		}

		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( $registry ) {
				$registry->register( new Blocks\BlocksSupport() );
			}
		);
	}

	/**
	 * Add a "Settings" link on the Plugins screen.
	 *
	 * @param array<int,string> $links Existing action links.
	 * @return array<int,string>
	 */
	public function plugin_action_links( array $links ): array {
		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . SHADOW_ETH_GATEWAY_ID );

		$plugin_links = array(
			'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'shadow-software-crypto-for-woocommerce' ) . '</a>',
		);

		return array_merge( $plugin_links, $links );
	}

	/**
	 * Release an order's transaction replay-claim when it is cancelled/refunded.
	 *
	 * @param int $order_id The order id.
	 * @return void
	 */
	public function release_tx_claim( $order_id ): void {
		$order = wc_get_order( (int) $order_id );

		if ( $order instanceof \WC_Order && $order->get_payment_method() === SHADOW_ETH_GATEWAY_ID ) {
			TxRegistry::release_order( (int) $order_id );
		}
	}

	/**
	 * Add professional resource links to the plugin's row on the Plugins screen.
	 *
	 * @param array<int,string> $links Existing row meta links.
	 * @param string            $file  Plugin file being rendered.
	 * @return array<int,string>
	 */
	public function plugin_row_meta( array $links, string $file ): array {
		if ( plugin_basename( SHADOW_ETH_FILE ) !== $file ) {
			return $links;
		}

		$links[] = '<a href="' . esc_url( 'https://shadowsoftware.com/' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Documentation', 'shadow-software-crypto-for-woocommerce' ) . '</a>';
		$links[] = '<a href="' . esc_url( 'https://shadowsoftware.com/contact' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Support', 'shadow-software-crypto-for-woocommerce' ) . '</a>';

		return $links;
	}
}
