<?php
/**
 * The buyer-facing "pay & confirm" screen and its submission handler.
 *
 * Rendered on WooCommerce's order-pay endpoint for orders placed with this
 * gateway. The buyer:
 *   1. chooses which crypto to pay in (ETH, USDC, USDT or BTC, from the assets
 *      and networks the merchant enabled),
 *   2. sees the exact amount and the store's address (with a QR code and a
 *      one-tap copy button),
 *   3. pays from their own wallet, then
 *   4. tells us how — with the wallet address they paid from and, optionally,
 *      the transaction id.
 *
 * Submission is a POST to admin-post.php, nonce-verified and bound to the order
 * key, which flips the order into "verifying" and kicks off the background
 * poller. A small polling script then updates the status live without a reload.
 *
 * This screen inherits the store theme; it is intentionally not Shadow-branded.
 *
 * @package ShadowEth
 */

namespace ShadowEth;

defined( 'ABSPATH' ) || exit;

/**
 * Order-pay page renderer + submission/status endpoints.
 */
final class PayPage {

	/**
	 * The admin-post action for a buyer submitting their payment.
	 */
	private const SUBMIT_ACTION = 'shadow_eth_submit_payment';

	/**
	 * The admin-post action for a buyer choosing which asset to pay in.
	 */
	private const CHOOSE_ACTION = 'shadow_eth_choose_asset';

	/**
	 * AJAX action for polling the live verification status.
	 */
	private const STATUS_ACTION = 'shadow_eth_payment_status';

	/**
	 * The gateway.
	 *
	 * @var Gateway
	 */
	private Gateway $gateway;

	/**
	 * The payment checker (to kick off polling on submit).
	 *
	 * @var PaymentChecker
	 */
	private PaymentChecker $checker;

	/**
	 * Construct with dependencies.
	 *
	 * @param Gateway        $gateway The gateway.
	 * @param PaymentChecker $checker The poller.
	 */
	public function __construct( Gateway $gateway, PaymentChecker $checker ) {
		$this->gateway = $gateway;
		$this->checker = $checker;
	}

	/**
	 * Hook the pay page, submit handler, status endpoint, and assets.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_receipt_' . SHADOW_ETH_GATEWAY_ID, array( $this, 'render' ) );
		add_action( 'admin_post_' . self::SUBMIT_ACTION, array( $this, 'handle_submit' ) );
		add_action( 'admin_post_nopriv_' . self::SUBMIT_ACTION, array( $this, 'handle_submit' ) );
		add_action( 'admin_post_' . self::CHOOSE_ACTION, array( $this, 'handle_choose_asset' ) );
		add_action( 'admin_post_nopriv_' . self::CHOOSE_ACTION, array( $this, 'handle_choose_asset' ) );
		add_action( 'wp_ajax_' . self::STATUS_ACTION, array( $this, 'handle_status' ) );
		add_action( 'wp_ajax_nopriv_' . self::STATUS_ACTION, array( $this, 'handle_status' ) );
	}

	/**
	 * Render the pay page for an order.
	 *
	 * @param int $order_id Order id (WooCommerce passes this to receipt hooks).
	 * @return void
	 */
	public function render( $order_id ): void {
		$order = wc_get_order( (int) $order_id );

		if ( ! $order instanceof \WC_Order || $order->get_payment_method() !== SHADOW_ETH_GATEWAY_ID ) {
			return;
		}

		$this->enqueue_assets( $order );

		$state = OrderMeta::get_state( $order );

		// Already submitted / done — show status only.
		if ( OrderMeta::STATE_AWAITING_PAYMENT !== $state ) {
			$this->render_status_panel( $order );

			return;
		}

		// The buyer clicked "Change" — a read-only signal to re-show the picker.
		// We do not mutate state on a GET; the picker simply overrides the display.
		$force_picker = isset( $_GET['shadow_eth_change'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display toggle, no state change.

		// No asset chosen yet, or the buyer asked to change it — show the picker.
		$chosen_asset = OrderMeta::get_string( $order, OrderMeta::ASSET );

		if ( $force_picker || '' === $chosen_asset || ! $this->gateway->is_asset_enabled( $chosen_asset ) ) {
			$this->render_asset_picker( $order );

			return;
		}

		$this->render_pay_details( $order, $chosen_asset );
	}

	/**
	 * Render the asset picker: the buyer chooses which crypto to pay in. Choosing
	 * one posts back to lock the amount for that asset and show the pay details.
	 *
	 * @param \WC_Order $order The order.
	 * @return void
	 */
	private function render_asset_picker( \WC_Order $order ): void {
		$assets = $this->enabled_assets_for_display();

		?>
		<div class="shadow-eth-pay" id="shadow-eth-pay">
			<h2 class="shadow-eth-pay__heading"><?php esc_html_e( 'Choose how to pay', 'shadow-software-crypto-for-woocommerce' ); ?></h2>
			<p class="shadow-eth-pay__form-intro"><?php esc_html_e( 'Pick the cryptocurrency and network you would like to pay with. We will then show the exact amount and address.', 'shadow-software-crypto-for-woocommerce' ); ?></p>

			<form class="shadow-eth-pay__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::CHOOSE_ACTION ); ?>">
				<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order->get_id() ); ?>">
				<input type="hidden" name="order_key" value="<?php echo esc_attr( $order->get_order_key() ); ?>">
				<?php wp_nonce_field( $this->choose_nonce_action( $order ), 'shadow_eth_nonce' ); ?>

				<div class="shadow-eth-pay__field">
					<label for="shadow-eth-asset"><?php esc_html_e( 'Pay with', 'shadow-software-crypto-for-woocommerce' ); ?></label>
					<select id="shadow-eth-asset" name="asset">
						<?php foreach ( $assets as $asset ) : ?>
							<option value="<?php echo esc_attr( $asset['id'] ); ?>">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: asset label, 2: network name. */
										__( '%1$s on %2$s', 'shadow-software-crypto-for-woocommerce' ),
										$asset['label'],
										$asset['network_name']
									)
								);
								?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<button type="submit" class="shadow-eth-pay__submit button alt"><?php esc_html_e( 'Continue', 'shadow-software-crypto-for-woocommerce' ); ?></button>
			</form>

			<?php $this->render_pay_footer(); ?>
		</div>
		<?php
	}

	/**
	 * Render the pay details for a chosen, locked asset: amount, address, QR, and
	 * the payment-confirmation form.
	 *
	 * @param \WC_Order $order    The order.
	 * @param string    $asset_id The chosen asset id.
	 * @return void
	 */
	private function render_pay_details( \WC_Order $order, string $asset_id ): void {
		$asset       = Assets::get( $asset_id );
		$decimals    = null !== $asset ? $asset['decimals'] : 18;
		$symbol      = null !== $asset ? $asset['symbol'] : 'ETH';
		$is_btc      = null !== $asset && Assets::KIND_BTC === $asset['kind'];
		$addr_ph     = $is_btc ? 'bc1…' : '0x…';
		$net_name    = $this->network_label( null !== $asset ? $asset['network'] : '' );
		$pay_address = OrderMeta::get_string( $order, OrderMeta::PAY_ADDRESS );
		$amount      = Money::from_base_units( OrderMeta::get_string( $order, OrderMeta::AMOUNT_WEI ), $decimals );

		?>
		<div class="shadow-eth-pay" id="shadow-eth-pay"
			data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"
			data-status-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-status-nonce="<?php echo esc_attr( wp_create_nonce( $this->status_nonce_action( $order ) ) ); ?>">

			<h2 class="shadow-eth-pay__heading">
				<?php
				printf(
					/* translators: %s: asset symbol. */
					esc_html__( 'Pay with %s', 'shadow-software-crypto-for-woocommerce' ),
					esc_html( $symbol )
				);
				?>
			</h2>

			<p class="shadow-eth-pay__amount">
				<?php
				printf(
					/* translators: 1: amount, 2: asset symbol. */
					esc_html__( 'Send exactly %1$s %2$s', 'shadow-software-crypto-for-woocommerce' ),
					'<strong>' . esc_html( $amount ) . '</strong>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inline.
					esc_html( $symbol )
				);
				?>
			</p>

			<p class="shadow-eth-pay__hint">
				<?php
				printf(
					/* translators: %s: network name. */
					esc_html__( 'On the %s network.', 'shadow-software-crypto-for-woocommerce' ),
					'<strong>' . esc_html( $net_name ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inline.
				);
				?>
				<a href="<?php echo esc_url( add_query_arg( 'shadow_eth_change', '1', $order->get_checkout_payment_url() ) ); ?>"><?php esc_html_e( 'Change', 'shadow-software-crypto-for-woocommerce' ); ?></a>
			</p>

			<div class="shadow-eth-pay__field">
				<label><?php esc_html_e( 'Pay to this address', 'shadow-software-crypto-for-woocommerce' ); ?></label>
				<div class="shadow-eth-pay__address">
					<code class="shadow-eth-pay__address-value" id="shadow-eth-address"><?php echo esc_html( $pay_address ); ?></code>
					<button type="button" class="shadow-eth-pay__copy button" data-copy-target="shadow-eth-address"><?php esc_html_e( 'Copy', 'shadow-software-crypto-for-woocommerce' ); ?></button>
				</div>
				<div class="shadow-eth-pay__qr" id="shadow-eth-qr" aria-hidden="true"></div>
			</div>

			<hr class="shadow-eth-pay__rule">

			<form class="shadow-eth-pay__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SUBMIT_ACTION ); ?>">
				<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order->get_id() ); ?>">
				<input type="hidden" name="order_key" value="<?php echo esc_attr( $order->get_order_key() ); ?>">
				<?php wp_nonce_field( 'shadow_eth_submit_' . $order->get_id(), 'shadow_eth_nonce' ); ?>

				<p class="shadow-eth-pay__form-intro"><?php esc_html_e( 'After you have sent the payment, confirm it below so we can check the blockchain and complete your order.', 'shadow-software-crypto-for-woocommerce' ); ?></p>

				<div class="shadow-eth-pay__field">
					<label for="shadow-eth-sender"><?php esc_html_e( 'The wallet address you paid from (required)', 'shadow-software-crypto-for-woocommerce' ); ?></label>
					<input type="text" id="shadow-eth-sender" name="sender" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="<?php echo esc_attr( $addr_ph ); ?>" required aria-required="true">
					<p class="shadow-eth-pay__hint"><?php esc_html_e( 'Copy this from your wallet — it is your own “from” address. We use it to confirm the payment is yours, so it is required even if you also add a transaction ID below.', 'shadow-software-crypto-for-woocommerce' ); ?></p>
				</div>

				<details class="shadow-eth-pay__advanced">
					<summary><?php esc_html_e( 'I have a transaction ID (optional, but fastest)', 'shadow-software-crypto-for-woocommerce' ); ?></summary>
					<div class="shadow-eth-pay__field">
						<label for="shadow-eth-txhash"><?php esc_html_e( 'Transaction hash', 'shadow-software-crypto-for-woocommerce' ); ?></label>
						<input type="text" id="shadow-eth-txhash" name="tx_hash" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="<?php echo esc_attr( $is_btc ? 'txid…' : '0x…' ); ?>">
						<p class="shadow-eth-pay__hint"><?php esc_html_e( 'Your wallet shows this after the payment sends (sometimes called “Transaction ID” or “Hash”). Pasting it confirms your order the fastest.', 'shadow-software-crypto-for-woocommerce' ); ?></p>
					</div>
				</details>

				<button type="submit" class="shadow-eth-pay__submit button alt"><?php esc_html_e( 'I’ve paid — check my payment', 'shadow-software-crypto-for-woocommerce' ); ?></button>
			</form>

			<?php $this->render_pay_footer(); ?>
		</div>
		<?php
	}

	/**
	 * Render the small legal/privacy footer shown under the pay form. Discloses
	 * the irreversible nature of on-chain payments. This is purely informational
	 * and carries no outbound links — the buyer-facing checkout stays unbranded.
	 *
	 * @return void
	 */
	private function render_pay_footer(): void {
		?>
		<p class="shadow-eth-pay__footer">
			<?php esc_html_e( 'Payments are made on a public blockchain and are irreversible once confirmed. Only the payment details you enter here are used, together with public blockchain data, to confirm your order.', 'shadow-software-crypto-for-woocommerce' ); ?>
		</p>
		<?php
	}

	/**
	 * Render the live/final status panel for an order that has been submitted.
	 *
	 * @param \WC_Order $order The order.
	 * @return void
	 */
	private function render_status_panel( \WC_Order $order ): void {
		$state    = OrderMeta::get_state( $order );
		$network  = OrderMeta::get_string( $order, OrderMeta::NETWORK );
		$tx_hash  = OrderMeta::get_string( $order, OrderMeta::CONFIRMED_TX );
		$explorer = ( 'bitcoin' === $network && '' !== $tx_hash )
			? 'https://mempool.space/tx/' . $tx_hash
			: Networks::explorer_tx_url( $network, $tx_hash );

		$is_confirmed = OrderMeta::STATE_CONFIRMED === $state;
		$is_failed    = OrderMeta::STATE_FAILED === $state;

		?>
		<div class="shadow-eth-pay shadow-eth-pay--status <?php echo $is_confirmed ? 'is-confirmed' : ( $is_failed ? 'is-failed' : 'is-verifying' ); ?>"
			id="shadow-eth-pay"
			data-order-id="<?php echo esc_attr( (string) $order->get_id() ); ?>"
			data-status-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-status-nonce="<?php echo esc_attr( wp_create_nonce( $this->status_nonce_action( $order ) ) ); ?>"
			data-poll="<?php echo $is_confirmed || $is_failed ? '0' : '1'; ?>">
			<?php if ( $is_confirmed ) : ?>
				<h2 class="shadow-eth-pay__heading"><?php esc_html_e( 'Payment confirmed', 'shadow-software-crypto-for-woocommerce' ); ?></h2>
				<p class="shadow-eth-pay__status-msg"><?php esc_html_e( 'Thank you — your payment is confirmed and your order is complete.', 'shadow-software-crypto-for-woocommerce' ); ?></p>
				<?php if ( '' !== $explorer ) : ?>
					<p><a href="<?php echo esc_url( $explorer ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View the transaction', 'shadow-software-crypto-for-woocommerce' ); ?></a></p>
				<?php endif; ?>
			<?php elseif ( $is_failed ) : ?>
				<h2 class="shadow-eth-pay__heading"><?php esc_html_e( 'We couldn’t confirm your payment', 'shadow-software-crypto-for-woocommerce' ); ?></h2>
				<p class="shadow-eth-pay__status-msg"><?php esc_html_e( 'We did not detect a matching payment in time. If you did pay, please contact us with your transaction details and we’ll sort it out.', 'shadow-software-crypto-for-woocommerce' ); ?></p>
			<?php else : ?>
				<h2 class="shadow-eth-pay__heading"><?php esc_html_e( 'Confirming your payment…', 'shadow-software-crypto-for-woocommerce' ); ?></h2>
				<p class="shadow-eth-pay__status-msg" id="shadow-eth-status-msg"><?php esc_html_e( 'We’re checking the blockchain for your payment. This page updates automatically — you can safely leave it open.', 'shadow-software-crypto-for-woocommerce' ); ?></p>
				<div class="shadow-eth-pay__spinner" aria-hidden="true"></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle a buyer's payment submission.
	 *
	 * @return void
	 */
	public function handle_submit(): void {
		$order_id  = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';

		if ( ! isset( $_POST['shadow_eth_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['shadow_eth_nonce'] ) ), 'shadow_eth_submit_' . $order_id ) ) {
			wp_die( esc_html__( 'Security check failed. Please go back and try again.', 'shadow-software-crypto-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$order = wc_get_order( $order_id );

		// Bind the action to the order key so only the buyer holding the order URL
		// can submit against it.
		if ( ! $order instanceof \WC_Order || ! hash_equals( $order->get_order_key(), $order_key ) ) {
			wp_die( esc_html__( 'Order not found.', 'shadow-software-crypto-for-woocommerce' ), '', array( 'response' => 404 ) );
		}

		if ( OrderMeta::STATE_AWAITING_PAYMENT !== OrderMeta::get_state( $order ) ) {
			// Already submitted; just send them back to the pay page.
			wp_safe_redirect( $order->get_checkout_payment_url() );
			exit;
		}

		$asset_id = OrderMeta::get_string( $order, OrderMeta::ASSET );
		$asset    = Assets::get( $asset_id );

		if ( null === $asset || ! $this->gateway->is_asset_enabled( $asset_id ) ) {
			wc_add_notice( __( 'Please choose how you want to pay first.', 'shadow-software-crypto-for-woocommerce' ), 'error' );
			wp_safe_redirect( $order->get_checkout_payment_url() );
			exit;
		}

		$is_btc  = Assets::KIND_BTC === $asset['kind'];
		$network = $asset['network'];
		$sender  = isset( $_POST['sender'] ) ? sanitize_text_field( wp_unslash( $_POST['sender'] ) ) : '';
		$tx_hash = isset( $_POST['tx_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['tx_hash'] ) ) : '';

		$error = $this->validate_submission( $is_btc, $sender, $tx_hash );

		if ( '' !== $error ) {
			wc_add_notice( $error, 'error' );
			wp_safe_redirect( $order->get_checkout_payment_url() );
			exit;
		}

		// Persist the buyer's claim (normalising the sender + tx id per family),
		// flip to verifying, capture the scan start block, and kick off the poller.
		if ( $is_btc ) {
			$order->update_meta_data( OrderMeta::SENDER, BtcAddress::normalize( $sender ) );

			if ( '' !== $tx_hash ) {
				$order->update_meta_data( OrderMeta::TX_HASH, strtolower( $tx_hash ) );
			}
		} else {
			$order->update_meta_data( OrderMeta::SENDER, Address::to_checksum( $sender ) );

			if ( '' !== $tx_hash ) {
				$order->update_meta_data( OrderMeta::TX_HASH, Address::normalize_tx_hash( $tx_hash ) );
			}
		}

		$order->update_meta_data( OrderMeta::SUBMITTED, (string) time() );
		OrderMeta::set_state( $order, OrderMeta::STATE_VERIFYING );

		// Capture a start height so an OLD payment cannot be claimed: the EVM
		// sender-scan needs it as its scan cursor, and Bitcoin uses it to reject a
		// payment confirmed in a block before this order existed.
		if ( $is_btc ) {
			BtcVerifier::capture_start_height( $order, $this->gateway );
		} elseif ( '' === $tx_hash ) {
			Verifier::capture_start_block( $order, $this->gateway, $network );
		}

		$order->add_order_note( __( 'Accept Crypto: buyer submitted payment details; verifying on-chain.', 'shadow-software-crypto-for-woocommerce' ) );
		$order->save();

		$this->checker->schedule( $order );

		wp_safe_redirect( $order->get_checkout_payment_url() );
		exit;
	}

	/**
	 * Validate a submission for the order's asset family. Returns '' when valid,
	 * or a customer-safe error.
	 *
	 * @param bool   $is_btc  Whether the order is a Bitcoin payment.
	 * @param string $sender  Submitted sender address.
	 * @param string $tx_hash Submitted tx id / hash.
	 */
	private function validate_submission( bool $is_btc, string $sender, string $tx_hash ): string {
		// The sending wallet is ALWAYS required. It is what ties a payment to this
		// buyer: verification insists the payment came from this address, so one
		// customer can never claim another customer's payment. The transaction id
		// is an optional accelerator on top of it.
		if ( '' === $sender ) {
			return __( 'Please enter the wallet address you paid from so we can confirm your payment.', 'shadow-software-crypto-for-woocommerce' );
		}

		if ( $is_btc ) {
			if ( ! BtcAddress::is_valid( $sender ) ) {
				return __( 'That sending Bitcoin address doesn’t look right. It should start with bc1, 1 or 3.', 'shadow-software-crypto-for-woocommerce' );
			}

			if ( '' !== $tx_hash && 1 !== preg_match( '/^[0-9a-fA-F]{64}$/', $tx_hash ) ) {
				return __( 'That transaction ID doesn’t look right. It should be 64 hex characters.', 'shadow-software-crypto-for-woocommerce' );
			}

			return '';
		}

		if ( ! Address::is_valid_format( $sender ) ) {
			return __( 'That sending wallet address doesn’t look right. It should start with 0x and be 42 characters long.', 'shadow-software-crypto-for-woocommerce' );
		}

		if ( '' !== $tx_hash && ! Address::is_valid_tx_hash( $tx_hash ) ) {
			return __( 'That transaction hash doesn’t look right. It should start with 0x and be 66 characters long.', 'shadow-software-crypto-for-woocommerce' );
		}

		return '';
	}

	/**
	 * The asset-choice nonce action for an order (bound to the order key).
	 *
	 * @param \WC_Order $order The order.
	 */
	private function choose_nonce_action( \WC_Order $order ): string {
		return 'shadow_eth_choose_' . $order->get_id() . '_' . $order->get_order_key();
	}

	/**
	 * Handle the buyer choosing which asset to pay in: verify the request, lock
	 * the amount for that asset, and send them back to the pay page (now showing
	 * the address + amount).
	 *
	 * @return void
	 */
	public function handle_choose_asset(): void {
		$order_id  = isset( $_POST['order_id'] ) ? absint( wp_unslash( $_POST['order_id'] ) ) : 0;
		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		$order     = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order || ! hash_equals( $order->get_order_key(), $order_key ) ) {
			wp_die( esc_html__( 'Order not found.', 'shadow-software-crypto-for-woocommerce' ), '', array( 'response' => 404 ) );
		}

		if ( ! isset( $_POST['shadow_eth_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['shadow_eth_nonce'] ) ), $this->choose_nonce_action( $order ) ) ) {
			wp_die( esc_html__( 'Security check failed. Please go back and try again.', 'shadow-software-crypto-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		if ( OrderMeta::STATE_AWAITING_PAYMENT !== OrderMeta::get_state( $order ) ) {
			wp_safe_redirect( $order->get_checkout_payment_url() );
			exit;
		}

		$asset_id = isset( $_POST['asset'] ) ? sanitize_text_field( wp_unslash( $_POST['asset'] ) ) : '';
		$error    = $this->gateway->lock_asset_amount( $order, $asset_id );

		if ( '' !== $error ) {
			wc_add_notice( $error, 'error' );
		}

		wp_safe_redirect( $order->get_checkout_payment_url() );
		exit;
	}

	/**
	 * The status-poll nonce action for an order. The order key is folded in so a
	 * valid nonce proves the caller already holds the order's secret key — the
	 * status endpoint therefore cannot be polled (or its order-received URL
	 * harvested) by guessing order ids.
	 *
	 * @param \WC_Order $order The order.
	 */
	private function status_nonce_action( \WC_Order $order ): string {
		return 'shadow_eth_status_' . $order->get_id() . '_' . $order->get_order_key();
	}

	/**
	 * AJAX: return the current verification status for live updates.
	 *
	 * @return void
	 */
	public function handle_status(): void {
		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
		$nonce    = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'shadow-software-crypto-for-woocommerce' ) ), 404 );
		}

		// Nonce is bound to the order key, so only a holder of the order URL can
		// poll it. Verify against this order's own action.
		if ( ! wp_verify_nonce( $nonce, $this->status_nonce_action( $order ) ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'shadow-software-crypto-for-woocommerce' ) ), 403 );
		}

		$state = OrderMeta::get_state( $order );

		wp_send_json_success(
			array(
				'state'    => $state,
				'done'     => in_array( $state, array( OrderMeta::STATE_CONFIRMED, OrderMeta::STATE_FAILED ), true ),
				'redirect' => OrderMeta::STATE_CONFIRMED === $state ? $order->get_checkout_order_received_url() : '',
			)
		);
	}

	/**
	 * The enabled assets as a display list (id, label, network name).
	 *
	 * @return array<int,array{id:string,label:string,network_name:string}>
	 */
	private function enabled_assets_for_display(): array {
		$out = array();

		foreach ( $this->gateway->get_enabled_assets() as $id ) {
			$asset = Assets::get( $id );

			if ( null !== $asset ) {
				$out[] = array(
					'id'           => $asset['id'],
					'label'        => $asset['label'],
					'network_name' => $this->network_label( $asset['network'] ),
				);
			}
		}

		return $out;
	}

	/**
	 * A human network label for a network slug (EVM networks from the registry;
	 * Bitcoin handled explicitly).
	 *
	 * @param string $network Network slug.
	 */
	private function network_label( string $network ): string {
		if ( 'bitcoin' === $network ) {
			return __( 'Bitcoin', 'shadow-software-crypto-for-woocommerce' );
		}

		$obj = Networks::get( $network );

		return null !== $obj ? $obj['name'] : $network;
	}

	/**
	 * Enqueue the pay-page stylesheet + script (script carries the amount/address
	 * for QR rendering and the status-poll config).
	 *
	 * @param \WC_Order $order The order.
	 * @return void
	 */
	private function enqueue_assets( \WC_Order $order ): void {
		wp_enqueue_style(
			'shadow-eth-pay',
			SHADOW_ETH_URL . 'assets/css/pay.css',
			array(),
			SHADOW_ETH_VERSION
		);

		wp_enqueue_script(
			'shadow-eth-qr',
			SHADOW_ETH_URL . 'assets/js/qr.js',
			array(),
			SHADOW_ETH_VERSION,
			true
		);

		wp_enqueue_script(
			'shadow-eth-pay',
			SHADOW_ETH_URL . 'assets/js/pay.js',
			array( 'shadow-eth-qr' ),
			SHADOW_ETH_VERSION,
			true
		);

		wp_localize_script(
			'shadow-eth-pay',
			'shadowEthPay',
			array(
				'address' => OrderMeta::get_string( $order, OrderMeta::PAY_ADDRESS ),
				'qrUri'   => $this->payment_uri( $order ),
				'copied'  => __( 'Copied!', 'shadow-software-crypto-for-woocommerce' ),
				'copy'    => __( 'Copy', 'shadow-software-crypto-for-woocommerce' ),
				'i18n'    => array(
					'confirmed' => __( 'Payment confirmed — finishing up…', 'shadow-software-crypto-for-woocommerce' ),
					'failed'    => __( 'We couldn’t confirm your payment.', 'shadow-software-crypto-for-woocommerce' ),
				),
			)
		);
	}

	/**
	 * Build the wallet payment URI encoded into the QR code for the order's asset:
	 * a BIP-21 bitcoin: URI for BTC, an EIP-681 ethereum: URI for native ETH, and
	 * an EIP-681 token-transfer URI for ERC-20 tokens. Returns '' if no asset is
	 * locked yet.
	 *
	 * @param \WC_Order $order The order.
	 */
	private function payment_uri( \WC_Order $order ): string {
		$asset = Assets::get( OrderMeta::get_string( $order, OrderMeta::ASSET ) );
		$addr  = OrderMeta::get_string( $order, OrderMeta::PAY_ADDRESS );
		$units = OrderMeta::get_string( $order, OrderMeta::AMOUNT_WEI );

		if ( null === $asset || '' === $addr ) {
			return '';
		}

		if ( Assets::KIND_BTC === $asset['kind'] ) {
			return 'bitcoin:' . $addr . '?amount=' . rawurlencode( Money::from_base_units( $units, 8 ) );
		}

		$chain_id = null !== Networks::get( $asset['network'] ) ? Networks::get( $asset['network'] )['chain_id'] : 1;

		if ( Assets::KIND_ERC20 === $asset['kind'] ) {
			// EIP-681 token transfer: ethereum:<token>@<chain>/transfer?address=<to>&uint256=<units>.
			return 'ethereum:' . $asset['contract'] . '@' . $chain_id . '/transfer?address=' . $addr . '&uint256=' . $units;
		}

		// Native ETH: ethereum:<to>@<chain>?value=<wei>.
		return 'ethereum:' . $addr . '@' . $chain_id . '?value=' . $units;
	}
}
