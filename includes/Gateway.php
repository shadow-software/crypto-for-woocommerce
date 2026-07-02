<?php
/**
 * The WooCommerce payment gateway for self-custodial Ethereum payments.
 *
 * The merchant enters their own ETH receiving address and picks which EVM
 * networks to accept (Ethereum, Base, Arbitrum, OP Mainnet). At checkout the
 * order total is converted to ETH at the live rate and locked onto the order.
 * The buyer is then sent to the order-pay page, pays the address directly from
 * their own wallet, and tells us how — either by pasting the transaction hash or
 * just their sending wallet address. A background poller confirms the payment
 * on-chain with free public RPCs before the order is completed.
 *
 * No custody, no middleman, no fees, and no private keys ever touch the site.
 *
 * @package ShadowEth
 */

namespace ShadowEth;

defined( 'ABSPATH' ) || exit;

/**
 * Classic WooCommerce gateway. Also the settings store the Blocks integration,
 * the pay page, and the verifier read from.
 */
final class Gateway extends \WC_Payment_Gateway {

	/**
	 * Shadow Software author URL (for the settings-screen author credit).
	 */
	private const BRAND_URL = 'https://shadowsoftware.com';

	/**
	 * Documentation / support page.
	 */
	private const SUPPORT_URL = 'https://shadowsoftware.com/contact';

	/**
	 * Set up the gateway: id, fields, settings, hooks.
	 */
	public function __construct() {
		$this->id                 = SHADOW_ETH_GATEWAY_ID;
		$this->method_title       = __( 'Crypto (self-custodial)', 'shadowpay-crypto-for-woocommerce' );
		$this->method_description = __( 'Confirm common blockchain transactions (USDT, USDC, BTC and ETH) and mark orders paid — straight to your own wallets. The order total is converted at the live rate; the buyer pays your address directly and the payment is confirmed on-chain with free public nodes and explorers before the order is marked paid. No custody, no fees, no keys on your server.', 'shadowpay-crypto-for-woocommerce' );
		$this->has_fields         = false;
		$this->supports           = array( 'products' );
		$this->icon               = SHADOW_ETH_URL . 'assets/img/crypto.svg';

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Pay with crypto (ETH, USDC, USDT, BTC)', 'shadowpay-crypto-for-woocommerce' ) );
		$this->description = $this->get_option( 'description', __( 'Pay in crypto from your own wallet. After you place the order we show the exact amount and address to pay.', 'shadowpay-crypto-for-woocommerce' ) );
		$this->enabled     = $this->get_option( 'enabled', 'no' );

		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			function (): void {
				$this->process_admin_options();
			}
		);
	}

	/**
	 * Admin settings fields.
	 *
	 * @return void
	 */
	public function init_form_fields(): void {
		$fields = array(
			'enabled'           => array(
				'title'   => __( 'Enable/Disable', 'shadowpay-crypto-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable crypto payments', 'shadowpay-crypto-for-woocommerce' ),
				'default' => 'no',
			),
			'title'             => array(
				'title'       => __( 'Title', 'shadowpay-crypto-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Payment method title shown to the customer at checkout.', 'shadowpay-crypto-for-woocommerce' ),
				'default'     => __( 'Pay with crypto (ETH, USDC, USDT, BTC)', 'shadowpay-crypto-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description'       => array(
				'title'       => __( 'Description', 'shadowpay-crypto-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Payment method description shown to the customer at checkout.', 'shadowpay-crypto-for-woocommerce' ),
				'default'     => __( 'Pay in crypto from your own wallet. After you place the order we show the exact amount and address to pay.', 'shadowpay-crypto-for-woocommerce' ),
			),
			'wallet'            => array(
				'title'       => __( 'Ethereum & tokens — receiving wallet', 'shadowpay-crypto-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'The 0x… address every EVM customer pays (ETH, USDC and USDT all arrive at this one address on whichever network the buyer chooses). Use an address you control. Double-check it — funds are sent by customers directly and cannot be recovered by this plugin.', 'shadowpay-crypto-for-woocommerce' ),
			),
			'receiving_address' => array(
				'title'             => __( 'EVM receiving address (0x…)', 'shadowpay-crypto-for-woocommerce' ),
				'type'              => 'text',
				'description'       => __( 'Your 0x… address. If you paste a checksummed (mixed-case) address it is verified; a typo will be rejected on save. Leave blank to disable ETH/USDC/USDT.', 'shadowpay-crypto-for-woocommerce' ),
				'default'           => '',
				'placeholder'       => '0xYourWalletAddress…',
				'desc_tip'          => false,
				'custom_attributes' => array(
					'autocomplete'   => 'off',
					'autocapitalize' => 'off',
					'spellcheck'     => 'false',
				),
			),
			'networks_title'    => array(
				'title'       => __( 'Networks & assets', 'shadowpay-crypto-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Choose which networks customers may pay on, and which assets (ETH / USDC / USDT) to accept on each. All use free public nodes by default; you can point any network at your own RPC endpoint below.', 'shadowpay-crypto-for-woocommerce' ),
			),
		);

		// Per-network: enable toggle, per-asset toggles, confirmations, RPC override.
		foreach ( Networks::all() as $slug => $network ) {
			$fields[ 'network_' . $slug . '_enabled' ] = array(
				'title'   => $network['name'],
				'type'    => 'checkbox',
				/* translators: %s: network name. */
				'label'   => sprintf( __( 'Accept payments on %s', 'shadowpay-crypto-for-woocommerce' ), $network['name'] ),
				'default' => 'ethereum' === $slug ? 'yes' : 'no',
			);

			// Per-asset toggles for this network (native ETH always available; the
			// tokens that exist on this chain get their own checkbox).
			$fields[ 'asset_eth:' . $slug . '_enabled' ] = array(
				/* translators: %s: network name. */
				'title'   => sprintf( __( '%s — assets', 'shadowpay-crypto-for-woocommerce' ), $network['name'] ),
				'type'    => 'checkbox',
				'label'   => __( 'ETH (native)', 'shadowpay-crypto-for-woocommerce' ),
				'default' => 'yes',
			);
			foreach ( Assets::all() as $asset ) {
				if ( $asset['network'] !== $slug || Assets::KIND_ERC20 !== $asset['kind'] ) {
					continue;
				}
				$fields[ 'asset_' . $asset['id'] . '_enabled' ] = array(
					'title'   => '',
					'type'    => 'checkbox',
					/* translators: %s: token symbol. */
					'label'   => sprintf( __( '%s (token)', 'shadowpay-crypto-for-woocommerce' ), $asset['symbol'] ),
					'default' => 'yes',
				);
			}

			$fields[ 'network_' . $slug . '_confirmations' ] = array(
				/* translators: %s: network name. */
				'title'             => sprintf( __( '%s — confirmations', 'shadowpay-crypto-for-woocommerce' ), $network['name'] ),
				'type'              => 'number',
				'description'       => __( 'Block confirmations required before the order is completed. Higher is safer but slower.', 'shadowpay-crypto-for-woocommerce' ),
				'default'           => (string) $network['default_confirmations'],
				'desc_tip'          => true,
				'custom_attributes' => array(
					'min'  => '1',
					'max'  => '200',
					'step' => '1',
				),
			);
			$fields[ 'network_' . $slug . '_rpc' ]           = array(
				/* translators: %s: network name. */
				'title'       => sprintf( __( '%s — custom RPC URL', 'shadowpay-crypto-for-woocommerce' ), $network['name'] ),
				'type'        => 'text',
				/* translators: %s: the default public RPC URL for the network. */
				'description' => sprintf( __( 'Optional. Leave blank to use the free public node (%s).', 'shadowpay-crypto-for-woocommerce' ), $network['default_rpc'] ),
				'default'     => '',
				'placeholder' => $network['default_rpc'],
				'desc_tip'    => true,
			);
		}

		// Bitcoin section.
		$fields['btc_title']         = array(
			'title'       => __( 'Bitcoin', 'shadowpay-crypto-for-woocommerce' ),
			'type'        => 'title',
			'description' => __( 'Accept native BTC to your own Bitcoin address, confirmed via free public block explorers. Leave the address blank to disable Bitcoin.', 'shadowpay-crypto-for-woocommerce' ),
		);
		$fields['btc_enabled']       = array(
			'title'   => __( 'Bitcoin', 'shadowpay-crypto-for-woocommerce' ),
			'type'    => 'checkbox',
			'label'   => __( 'Accept native Bitcoin (BTC)', 'shadowpay-crypto-for-woocommerce' ),
			'default' => 'no',
		);
		$fields['btc_address']       = array(
			'title'             => __( 'BTC receiving address', 'shadowpay-crypto-for-woocommerce' ),
			'type'              => 'text',
			'description'       => __( 'Your Bitcoin mainnet address (bc1…, 1… or 3…). The checksum is verified on save; a typo will be rejected.', 'shadowpay-crypto-for-woocommerce' ),
			'default'           => '',
			'placeholder'       => 'bc1…',
			'custom_attributes' => array(
				'autocomplete'   => 'off',
				'autocapitalize' => 'off',
				'spellcheck'     => 'false',
			),
		);
		$fields['btc_confirmations'] = array(
			'title'             => __( 'Bitcoin — confirmations', 'shadowpay-crypto-for-woocommerce' ),
			'type'              => 'number',
			'description'       => __( 'Confirmations required before a Bitcoin order is completed. 1–2 is common for small amounts; more is safer.', 'shadowpay-crypto-for-woocommerce' ),
			'default'           => '2',
			'desc_tip'          => true,
			'custom_attributes' => array(
				'min'  => '1',
				'max'  => '20',
				'step' => '1',
			),
		);
		$fields['btc_explorer']      = array(
			'title'       => __( 'Bitcoin — custom explorer URL', 'shadowpay-crypto-for-woocommerce' ),
			'type'        => 'text',
			'description' => __( 'Optional. An Esplora-compatible API base URL (e.g. your own mempool instance). Leave blank to use the free mempool.space and blockstream.info explorers.', 'shadowpay-crypto-for-woocommerce' ),
			'default'     => '',
			'placeholder' => 'https://mempool.space/api',
			'desc_tip'    => true,
		);

		$fields['pricing_title']  = array(
			'title'       => __( 'Pricing & tolerance', 'shadowpay-crypto-for-woocommerce' ),
			'type'        => 'title',
			'description' => __( 'The order total is converted from your store currency to the chosen crypto at the live market rate when the buyer picks how to pay, and that amount is locked for them.', 'shadowpay-crypto-for-woocommerce' ),
		);
		$fields['tolerance_bps']  = array(
			'title'             => __( 'Underpayment tolerance (%)', 'shadowpay-crypto-for-woocommerce' ),
			'type'              => 'number',
			'description'       => __( 'Accept a payment this many percent below the quoted amount, to absorb rate drift and rounding. 0 requires the exact amount or more.', 'shadowpay-crypto-for-woocommerce' ),
			'default'           => '1',
			'desc_tip'          => true,
			'custom_attributes' => array(
				'min'  => '0',
				'max'  => '10',
				'step' => '0.1',
			),
		);
		$fields['window_minutes'] = array(
			'title'             => __( 'Payment window (minutes)', 'shadowpay-crypto-for-woocommerce' ),
			'type'              => 'number',
			'description'       => __( 'How long to keep polling the chain for a submitted payment before the order is marked failed.', 'shadowpay-crypto-for-woocommerce' ),
			'default'           => '60',
			'desc_tip'          => true,
			'custom_attributes' => array(
				'min'  => '10',
				'max'  => '1440',
				'step' => '5',
			),
		);

		$this->form_fields = $fields;
	}

	/**
	 * Validate the receiving address on save: reject anything that is not a valid
	 * address, and reject a mixed-case address whose checksum is wrong (a typo).
	 * Store the checksummed form for display.
	 *
	 * @param string $key   Field key.
	 * @param string $value Raw submitted value.
	 * @return string The value to persist.
	 */
	public function validate_receiving_address_field( $key, $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		if ( ! Address::is_valid_format( $value ) ) {
			\WC_Admin_Settings::add_error( __( 'The EVM receiving address is not a valid 0x… address. It was not saved.', 'shadowpay-crypto-for-woocommerce' ) );

			return (string) $this->get_option( $key, '' );
		}

		if ( ! Address::is_valid_checksum( $value ) ) {
			\WC_Admin_Settings::add_error( __( 'The EVM receiving address has an invalid checksum (likely a typo). It was not saved.', 'shadowpay-crypto-for-woocommerce' ) );

			return (string) $this->get_option( $key, '' );
		}

		return Address::to_checksum( $value );
	}

	/**
	 * Validate the Bitcoin receiving address on save: reject anything that is not
	 * a valid mainnet address (checksum verified). Store the normalised form.
	 *
	 * @param string $key   Field key.
	 * @param string $value Raw submitted value.
	 * @return string The value to persist.
	 */
	public function validate_btc_address_field( $key, $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		if ( ! BtcAddress::is_valid( $value ) ) {
			\WC_Admin_Settings::add_error( __( 'The Bitcoin receiving address is not a valid mainnet address (bc1…, 1… or 3…). It was not saved.', 'shadowpay-crypto-for-woocommerce' ) );

			return (string) $this->get_option( $key, '' );
		}

		return BtcAddress::normalize( $value );
	}

	/**
	 * Render the branded settings screen.
	 *
	 * @return void
	 */
	public function admin_options(): void {
		wp_enqueue_style(
			'shadow-eth-admin',
			SHADOW_ETH_URL . 'assets/css/admin.css',
			array(),
			SHADOW_ETH_VERSION
		);

		$this->render_brand_header();

		parent::admin_options();
	}

	/**
	 * Output the Shadow Software branded header for the settings screen. Scoped
	 * under .shadow-eth-admin so it cannot affect the rest of wp-admin.
	 *
	 * @return void
	 */
	private function render_brand_header(): void {
		$mark_url = SHADOW_ETH_URL . 'assets/img/shadow-mark.svg';
		$services = self::BRAND_URL . '/';

		?>
		<div class="shadow-eth-admin">
			<div class="shadow-eth-admin__head">
				<img class="shadow-eth-admin__mark" src="<?php echo esc_url( $mark_url ); ?>" alt="" aria-hidden="true" width="40" height="44">
				<div class="shadow-eth-admin__titles">
					<p class="shadow-eth-admin__wordmark">Accept <span>Crypto</span></p>
					<p class="shadow-eth-admin__tagline">
						<?php esc_html_e( 'Self-custodial crypto checkout — ETH, USDC, USDT & BTC — free and open, by Shadow Software LLC.', 'shadowpay-crypto-for-woocommerce' ); ?>
					</p>
				</div>
			</div>
			<div class="shadow-eth-admin__pills">
				<span class="shadow-eth-admin__pill"><?php esc_html_e( 'Your wallet, your keys', 'shadowpay-crypto-for-woocommerce' ); ?></span>
				<span class="shadow-eth-admin__pill"><?php esc_html_e( 'On-chain verified', 'shadowpay-crypto-for-woocommerce' ); ?></span>
				<span class="shadow-eth-admin__pill shadow-eth-admin__pill--neutral"><?php esc_html_e( 'Free public nodes', 'shadowpay-crypto-for-woocommerce' ); ?></span>
				<span class="shadow-eth-admin__pill shadow-eth-admin__pill--neutral"><?php esc_html_e( 'No fees · No middleman', 'shadowpay-crypto-for-woocommerce' ); ?></span>
			</div>
			<p class="shadow-eth-admin__notice">
				<?php esc_html_e( 'Payments are sent by customers directly to your wallet and settle on a public blockchain. On-chain payments are irreversible and cannot be refunded or recovered by this plugin — treat a confirmed order like cash, and always confirm your receiving address is correct.', 'shadowpay-crypto-for-woocommerce' ); ?>
			</p>
			<div class="shadow-eth-admin__meta">
				<span class="shadow-eth-admin__version">
					<?php
					/* translators: %s: the installed plugin version. */
					echo esc_html( sprintf( __( 'Version %s', 'shadowpay-crypto-for-woocommerce' ), SHADOW_ETH_VERSION ) );
					?>
				</span>
				<span class="sh-sep" aria-hidden="true">·</span>
				<a href="<?php echo esc_url( self::SUPPORT_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Documentation', 'shadowpay-crypto-for-woocommerce' ); ?></a>
				<a href="<?php echo esc_url( self::SUPPORT_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Support', 'shadowpay-crypto-for-woocommerce' ); ?></a>
				<span class="sh-sep" aria-hidden="true">·</span>
				<span class="shadow-eth-admin__by">
					<?php
					printf(
						/* translators: %s: the plugin author, linked. */
						esc_html__( 'by %s', 'shadowpay-crypto-for-woocommerce' ),
						'<a href="' . esc_url( $services ) . '" target="_blank" rel="noopener noreferrer">Shadow Software</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- URL escaped inline; author name is a fixed literal.
					);
					?>
				</span>
			</div>
		</div>
		<?php
	}

	/**
	 * The merchant's checksummed EVM receiving address, or '' if unset/invalid.
	 */
	public function get_receiving_address(): string {
		$address = trim( (string) $this->get_option( 'receiving_address', '' ) );

		return Address::is_valid( $address ) ? $address : '';
	}

	/**
	 * The merchant's Bitcoin receiving address, or '' if unset/invalid.
	 */
	public function get_btc_address(): string {
		$address = trim( (string) $this->get_option( 'btc_address', '' ) );

		return BtcAddress::is_valid( $address ) ? $address : '';
	}

	/**
	 * The Bitcoin confirmations required (clamped 1-20).
	 */
	public function get_btc_confirmations(): int {
		$value = (int) $this->get_option( 'btc_confirmations', '2' );

		return max( 1, min( 20, $value ) );
	}

	/**
	 * The merchant's custom Esplora explorer base URL, or '' for the free defaults.
	 */
	public function get_btc_explorer_url(): string {
		$url = trim( (string) $this->get_option( 'btc_explorer', '' ) );

		return ( '' !== $url && wp_http_validate_url( $url ) ) ? untrailingslashit( $url ) : '';
	}

	/**
	 * Whether Bitcoin is enabled AND a valid BTC address is configured.
	 */
	public function is_btc_enabled(): bool {
		return 'yes' === $this->get_option( 'btc_enabled', 'no' ) && '' !== $this->get_btc_address();
	}

	/**
	 * The receiving address for a given asset: the EVM address for ETH/tokens, the
	 * BTC address for Bitcoin. '' if not configured.
	 *
	 * @param array{kind:string} $asset Asset record.
	 */
	public function get_asset_receiving_address( array $asset ): string {
		if ( Assets::KIND_BTC === $asset['kind'] ) {
			return $this->get_btc_address();
		}

		return $this->get_receiving_address();
	}

	/**
	 * Whether a specific asset (by id) is enabled and fully payable right now.
	 *
	 * @param string $asset_id Asset id.
	 */
	public function is_asset_enabled( string $asset_id ): bool {
		$asset = Assets::get( $asset_id );

		if ( null === $asset ) {
			return false;
		}

		if ( Assets::KIND_BTC === $asset['kind'] ) {
			return $this->is_btc_enabled();
		}

		// EVM: the network must be enabled, the per-asset toggle on, and the EVM
		// receiving address configured.
		if ( ! $this->is_network_enabled( $asset['network'] ) || '' === $this->get_receiving_address() ) {
			return false;
		}

		return 'yes' === $this->get_option( 'asset_' . $asset_id . '_enabled', 'yes' );
	}

	/**
	 * All currently-payable asset ids.
	 *
	 * @return string[]
	 */
	public function get_enabled_assets(): array {
		$out = array();

		foreach ( Assets::ids() as $id ) {
			if ( $this->is_asset_enabled( $id ) ) {
				$out[] = $id;
			}
		}

		return $out;
	}

	/**
	 * The slugs of the networks the merchant has enabled.
	 *
	 * @return string[]
	 */
	public function get_enabled_networks(): array {
		$enabled = array();

		foreach ( Networks::slugs() as $slug ) {
			if ( 'yes' === $this->get_option( 'network_' . $slug . '_enabled', 'no' ) ) {
				$enabled[] = $slug;
			}
		}

		return $enabled;
	}

	/**
	 * Whether a specific network is enabled for payment.
	 *
	 * @param string $slug Network slug.
	 */
	public function is_network_enabled( string $slug ): bool {
		return Networks::is_supported( $slug )
			&& 'yes' === $this->get_option( 'network_' . $slug . '_enabled', 'no' );
	}

	/**
	 * The RPC URL to use for a network: the merchant override if set, else the
	 * free public default.
	 *
	 * @param string $slug Network slug.
	 */
	public function get_rpc_url( string $slug ): string {
		$network = Networks::get( $slug );

		if ( null === $network ) {
			return '';
		}

		$override = trim( (string) $this->get_option( 'network_' . $slug . '_rpc', '' ) );

		if ( '' !== $override && wp_http_validate_url( $override ) ) {
			return $override;
		}

		return $network['default_rpc'];
	}

	/**
	 * Required confirmations for a network (merchant-tunable, clamped 1-200).
	 *
	 * @param string $slug Network slug.
	 */
	public function get_confirmations( string $slug ): int {
		$network = Networks::get( $slug );
		$default = null !== $network ? $network['default_confirmations'] : 3;
		$value   = (int) $this->get_option( 'network_' . $slug . '_confirmations', (string) $default );

		return max( 1, min( 200, $value ) );
	}

	/**
	 * The underpayment tolerance in basis points (percent × 100), clamped 0-1000.
	 */
	public function get_tolerance_bps(): int {
		$percent = (float) $this->get_option( 'tolerance_bps', '1' );
		$bps     = (int) round( $percent * 100 );

		return max( 0, min( 1000, $bps ) );
	}

	/**
	 * The payment window in minutes, clamped 10-1440.
	 */
	public function get_window_minutes(): int {
		$value = (int) $this->get_option( 'window_minutes', '60' );

		return max( 10, min( 1440, $value ) );
	}

	/**
	 * Only offer the method when it is fully usable: enabled, at least one asset
	 * payable (a configured EVM address with an enabled network/asset, or Bitcoin
	 * enabled with a valid address), and the store currency can be converted.
	 */
	public function is_available(): bool {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}

		if ( empty( $this->get_enabled_assets() ) ) {
			return false;
		}

		if ( ! RateProvider::supports( get_woocommerce_currency() ) ) {
			return false;
		}

		return parent::is_available();
	}

	/**
	 * Place the order into "awaiting payment", lock the ETH amount at the live
	 * rate, and send the buyer to the order-pay page where they pay the address
	 * and submit their transaction.
	 *
	 * @param int $order_id The WooCommerce order id.
	 * @return array{result:string,redirect?:string,messages?:string}
	 */
	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return $this->fail( __( 'Order not found.', 'shadowpay-crypto-for-woocommerce' ) );
		}

		if ( empty( $this->get_enabled_assets() ) ) {
			return $this->fail( __( 'This store has not finished setting up crypto payments. Please choose another method.', 'shadowpay-crypto-for-woocommerce' ) );
		}

		// The buyer chooses which asset (and network) to pay in on the order-pay
		// page; the exact amount is locked at that point (see PayPage). Here we
		// simply move the order to pending and hand off.
		OrderMeta::set_state( $order, OrderMeta::STATE_AWAITING_PAYMENT );

		if ( $order->has_status( array( 'pending', 'failed' ) ) ) {
			$order->update_status(
				'pending',
				__( 'Awaiting crypto payment. Buyer will choose an asset and pay on the order page.', 'shadowpay-crypto-for-woocommerce' )
			);
		}

		$order->save();

		// Empty the cart now; the buyer completes payment on the order-pay page.
		if ( function_exists( 'WC' ) && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_payment_url( true ),
		);
	}

	/**
	 * Lock an order's payment requirement for a chosen asset: convert the fiat
	 * total to the asset's base units at the live rate, add the per-order
	 * uniqueness salt, and store the asset, network, pay address, and amount on
	 * the order. Returns '' on success or a customer-safe error string.
	 *
	 * @param \WC_Order $order    The order.
	 * @param string    $asset_id The chosen asset id.
	 * @return string '' on success, or an error message.
	 */
	public function lock_asset_amount( \WC_Order $order, string $asset_id ): string {
		$asset = Assets::get( $asset_id );

		if ( null === $asset || ! $this->is_asset_enabled( $asset_id ) ) {
			return __( 'Please choose a valid payment option.', 'shadowpay-crypto-for-woocommerce' );
		}

		$address = $this->get_asset_receiving_address( $asset );

		if ( '' === $address ) {
			return __( 'This payment option is not available right now. Please choose another.', 'shadowpay-crypto-for-woocommerce' );
		}

		$currency = $order->get_currency();
		$units    = RateProvider::fiat_to_base_units( (string) $order->get_total(), $currency, $asset['coingecko_id'], $asset['decimals'] );

		if ( null === $units || '0' === $units ) {
			$order->add_order_note( __( 'Accept Crypto: could not fetch the live exchange rate to price this order.', 'shadowpay-crypto-for-woocommerce' ) );

			return __( 'We could not fetch the live exchange rate. Please try again in a moment or choose another option.', 'shadowpay-crypto-for-woocommerce' );
		}

		$rate   = RateProvider::asset_price( $asset['coingecko_id'], $currency );
		$quoted = $units;
		$units  = Money::apply_unique_salt( $units, (int) $order->get_id() );

		// Fail closed: the unique-amount salt must only ever ADD to the quoted
		// amount. If for any reason the salted amount is below the quoted amount,
		// refuse to lock rather than under-price the order.
		if ( Money::compare( $units, $quoted ) < 0 ) {
			Logger::error( 'Salted amount fell below the quoted amount for order ' . $order->get_id() . '; refusing to lock.' );

			return __( 'We could not price this order correctly. Please try again or choose another option.', 'shadowpay-crypto-for-woocommerce' );
		}

		$order->update_meta_data( OrderMeta::ASSET, $asset_id );
		$order->update_meta_data( OrderMeta::NETWORK, $asset['network'] );
		$order->update_meta_data( OrderMeta::PAY_ADDRESS, $address );
		$order->update_meta_data( OrderMeta::AMOUNT_WEI, $units );
		$order->update_meta_data( OrderMeta::RATE, (string) $rate );
		$order->update_meta_data( OrderMeta::RATE_CURRENCY, $currency );
		$order->save();

		return '';
	}

	/**
	 * Standard WooCommerce failure return + a customer notice.
	 *
	 * @param string $message Customer-safe message.
	 * @return array{result:string,messages:string}
	 */
	private function fail( string $message ): array {
		wc_add_notice( $message, 'error' );

		return array(
			'result'   => 'failure',
			'messages' => $message,
		);
	}
}
