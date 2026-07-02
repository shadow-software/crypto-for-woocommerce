=== Shadowchain Crypto for WooCommerce ===
Contributors: shadowsoftware
Donate link: https://shadowsoftware.com/
Tags: woocommerce, crypto, bitcoin, ethereum, stablecoin
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.0
WC requires at least: 8.2
WC tested up to: 10.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A simple, free, open-source plugin to confirm common blockchain transactions (USDT/USDC/BTC/ETH) and mark orders paid in Woocommerce.

== Description ==

Shadowchain Crypto for WooCommerce lets your store accept cryptocurrency straight to
your own wallets — no payment processor, no account, no fees, and no private keys
on your server. You enter your own receiving addresses; customers pay them
directly and the plugin watches the blockchain with free, public tools, only
marking an order paid once the payment is confirmed on-chain.

Supported payments:

* **Ether (ETH)** — native ETH on Ethereum, Base, Arbitrum One, and OP Mainnet.
* **USDC and USDT** — the leading dollar stablecoins, as ERC-20 tokens on those
  same networks (whichever are deployed there).
* **Bitcoin (BTC)** — native BTC on the Bitcoin mainnet.

This plugin is completely free and open source. It is built and maintained by
[Shadow Software](https://shadowsoftware.com/).

= How it works =

1. The merchant enters their own receiving addresses (one EVM address for
   ETH/USDC/USDT, and a Bitcoin address for BTC) and picks which assets to accept.
2. The customer places the order and, on the pay page, chooses which crypto to pay
   with. The order total is converted from your store currency to that crypto at
   the live market rate and locked in.
3. The pay page shows the exact amount, your address, and a scannable QR code. The
   customer pays from their own wallet.
4. The customer confirms by entering the wallet address they paid from (and,
   optionally, the transaction ID — which is fastest).
5. A background job checks the blockchain and completes the order once the payment
   has enough confirmations. The customer's page updates automatically.

= Why merchants like it =

* **Self-custodial.** Funds go straight to your wallets. The plugin never holds
  money and never sees a private key.
* **No fees, no middleman, no account.** There is no gateway service to sign up
  for and nothing takes a cut.
* **Free tools only.** Confirmation uses free public RPC nodes (EVM) and free
  public block explorers (Bitcoin); the exchange rate uses a free public price
  API. You can point any network at your own endpoint if you prefer.
* **On-chain verified.** An order is completed only after the exact payment is
  found on-chain with the confirmations you require — never from the browser
  alone. Each payment is bound to the buyer's own wallet and to a unique amount,
  so one payment can never settle two orders.
* **Multi-asset, multi-network.** ETH, USDC, USDT across Ethereum, Base, Arbitrum
  One and OP Mainnet, plus native Bitcoin — each individually toggleable.
* **Address safety.** Your receiving address is checked (including its EIP-55
  checksum) when you save it, so a typo can't silently send funds nowhere.
* **HPOS compatible** and works with both the classic checkout shortcode and the
  WooCommerce Checkout block.
* **Translation-ready.**

= What this plugin is not =

It does not custody funds, provide refunds on-chain, or swap/convert between
currencies. It confirms native ETH, USDC, USDT and BTC payments to your own
addresses and marks the order paid. Because payments are on-chain and
irreversible, treat confirmed orders like cash.

== Installation ==

1. Upload the `shadowchain-crypto-for-woocommerce` folder to `/wp-content/plugins/`, or install
   the ZIP via Plugins → Add New → Upload.
2. Activate the plugin through the Plugins menu.
3. Go to WooCommerce → Settings → Payments → **Crypto (self-custodial)**.
4. Enable it and paste your **EVM receiving address** (for ETH/USDC/USDT) and/or
   your **Bitcoin address**, then tick which assets and networks to accept.
5. Optionally adjust the required confirmations, underpayment tolerance, and
   payment window.
6. Save. "Pay with crypto" now appears at checkout.

WooCommerce's background scheduler (Action Scheduler) drives the on-chain checks,
so make sure your site's cron is running normally.

== Frequently Asked Questions ==

= Does the plugin ever hold my money or my keys? =

No. Customers pay your address directly from their own wallet. The plugin only
reads the blockchain to confirm the payment. No private keys are ever entered or
stored.

= Is there any fee or account to sign up for? =

No. The plugin is free and open source, there is no gateway service, and nothing
takes a cut of your sales.

= Which assets and networks are supported? =

Native ETH, plus USDC and USDT (as ERC-20 tokens), on Ethereum mainnet, Base,
Arbitrum One, and OP Mainnet — and native Bitcoin. Each asset and network can be
enabled or disabled independently, and each network can be pointed at your own
RPC or explorer endpoint.

= How is the amount calculated? =

When the customer chooses which crypto to pay with, the order total in your store
currency is converted to that asset at the live market rate (via the free
CoinGecko price API) and locked for the customer. Each order is given a tiny,
unique amount so that one on-chain payment can never settle two orders. You can
allow a small underpayment tolerance to absorb rate drift and rounding.

= What if the customer doesn't know their transaction ID? =

They can simply enter the wallet address they paid from. The plugin looks for a
matching payment to your address from that wallet. Pasting the transaction ID is
faster, but it is optional.

= How does the plugin know the payment is from this customer? =

Every payment is bound to the wallet address the customer entered and to the
order's unique amount, and (for Bitcoin) must be dated at or after the order was
placed. This prevents a customer from claiming someone else's payment or reusing
one payment for several orders.

= When is an order marked paid? =

Only after the matching payment is found on-chain and has reached the number of
confirmations you configured for that network. The customer's browser never
completes an order on its own.

= What happens if no payment arrives? =

If a matching payment is not confirmed within the payment window you set, the
order is marked failed so you are not left guessing.

= Does it need an API key? =

No. It works out of the box with free public nodes and a free public price API.
You may optionally supply your own RPC endpoints.

= Is HPOS (High-Performance Order Storage) supported? =

Yes, along with the WooCommerce Cart and Checkout blocks.

== External services ==

This plugin connects to a small number of free, third-party services to price
orders and to confirm payments on the blockchain. No account or API key is
required for any of them. Here is exactly what is contacted, when, and what data
is sent.

**1. CoinGecko price API (api.coingecko.com)**

* **What it is for:** converting your store-currency order total into the amount
  of the chosen crypto (ETH, USDC, USDT or BTC) using the live market rate.
* **When it is called:** when a customer chooses which crypto to pay with (results
  are cached briefly to minimise requests).
* **What is sent:** only the request itself — the coin id (`ethereum`, `usd-coin`,
  `tether` or `bitcoin`) and your store currency code (for example `usd`). No
  personal data, no order details, and no customer information are sent.
* CoinGecko API Terms of Service: https://www.coingecko.com/en/api_terms
* CoinGecko Privacy Policy: https://www.coingecko.com/en/privacy

**2. Public Ethereum RPC nodes (by default the free PublicNode endpoints:
ethereum-rpc.publicnode.com, base-rpc.publicnode.com,
arbitrum-one-rpc.publicnode.com, optimism-rpc.publicnode.com)**

* **What it is for:** reading the EVM blockchains to find and confirm a customer's
  ETH, USDC or USDT payment (the transaction, its receipt and event logs, block
  data, the chain id, and the current block height).
* **When it is called:** after a customer submits an EVM payment, on a background
  schedule, until the payment is confirmed or the payment window expires.
* **What is sent:** standard read-only JSON-RPC queries containing the customer's
  submitted transaction hash and/or wallet address and your store's receiving
  address — all of which are already public information on the blockchain. No
  private keys are ever sent, and no data is ever written to the blockchain by
  this plugin.
* You may replace these defaults with your own RPC endpoint for any network on
  the settings screen.
* PublicNode Terms of Service: https://www.publicnode.com/terms
* PublicNode Privacy Policy: https://www.publicnode.com/privacy

**3. Public Bitcoin block explorers (by default mempool.space and, as a fallback,
blockstream.info — used only when Bitcoin is enabled)**

* **What it is for:** reading the Bitcoin blockchain to find and confirm a
  customer's BTC payment (a transaction, an address's transactions, and the
  current block height).
* **When it is called:** after a customer submits a Bitcoin payment, on a
  background schedule, until the payment is confirmed or the payment window
  expires.
* **What is sent:** standard read-only REST requests containing the customer's
  submitted transaction id and/or wallet address and your store's Bitcoin
  address — all already public information on the blockchain. No private keys are
  ever sent, and nothing is written to the blockchain.
* You may replace these defaults with your own Esplora-compatible explorer URL on
  the settings screen.
* mempool.space Terms of Service: https://mempool.space/terms-of-service
* mempool.space Privacy Policy: https://mempool.space/privacy-policy
* Blockstream Privacy Policy: https://blockstream.com/privacy/

If you configure a custom RPC or explorer endpoint, the same read-only queries are
sent to the provider you choose, subject to that provider's own terms and privacy
policy.

== Privacy ==

This plugin does not create user accounts, does not set cookies, and does not
send any personal data to Shadow Software or to any external service. To price
and confirm a payment it stores, on the order itself, the asset and network, the
amount owed, the wallet address the customer says they paid from, and the
confirmed transaction id. These are kept with the order as its payment audit
trail (and, being on-chain data, are already public). No order or customer data
is transmitted to the third-party services listed above beyond the read-only
blockchain queries described there.

Full policies for the plugin's author, Shadow Software LLC:

* Terms: https://shadowsoftware.com/terms
* Privacy Policy: https://shadowsoftware.com/privacy

== Screenshots ==

1. The branded gateway settings screen: receiving addresses, per-network and
   per-asset toggles, Bitcoin settings, confirmations, tolerance, and window.
2. The customer pay page: choose the crypto to pay with, then the exact amount,
   receiving address, QR code, and the simple "how did you pay?" form.
3. Live confirmation: the pay page updates itself as the payment confirms on-chain.

== Changelog ==

= 1.0.0 =
* Initial release: self-custodial crypto payments to the merchant's own wallets —
  native ETH plus USDC and USDT (ERC-20) on Ethereum, Base, Arbitrum One and OP
  Mainnet, and native Bitcoin. Live fiat pricing per asset, on-chain confirmation
  via free public RPC nodes and block explorers (by transaction id or by sending
  wallet), a unique per-order amount and mandatory sender binding so one payment
  can never settle two orders, chain-id verification, configurable
  confirmations/tolerance/window, EIP-55 and Bitcoin address checking, bundled
  offline QR codes, HPOS support, and WooCommerce Blocks support.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
