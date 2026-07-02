# Contributing to ShadowPay Crypto for WooCommerce

Thanks for your interest in improving this plugin! It is free and open source,
maintained by [Shadow Software](https://shadowsoftware.com/).

## Ground rules

This plugin moves real money on public blockchains, so correctness and security
come first. Every change must keep the full quality gate green and must not
weaken any of the payment-integrity guarantees described in the
[README](README.md#security-model).

## Getting set up

```bash
git clone git@github.com:shadow-software/crypto-woocommerce.git
cd crypto-woocommerce
composer install
```

## The quality gate

Run this before opening a pull request — CI runs the same thing on every push and
PR to `production`, across PHP 8.0–8.3:

```bash
composer ci     # phpcs (WordPress Coding Standards) + phpstan (level 6) + phpunit
```

Individual steps:

```bash
composer lint       # coding standards + PHP 8.0 compatibility
composer lint:fix   # auto-fix what phpcbf can
composer stan       # static analysis
composer test       # unit tests
```

## Coding standards

- **WordPress Coding Standards** (WordPress-Extra + WordPress-Docs). `phpcs` is
  the source of truth; if it passes, the style is correct.
- **PHP 8.0+** — the plugin lints for 8.0 and up.
- **No Composer dependencies in the shipped plugin.** Everything the runtime
  needs is bundled (the tiny autoloader, Keccak-256/EIP-55, the QR generator).
  Dev-only tooling lives in `require-dev`.
- **Exact money math only.** Never use floats for amounts — use the big-integer
  helpers in `includes/Money.php` (wei / sats / token base units).
- **Escape on output, sanitize on input, verify nonces.** No exceptions.
- **Every user-facing string** uses the `crypto-woocommerce` text domain.

## Adding a network or token

Networks live in `includes/Networks.php`; assets (native + ERC-20) in
`includes/Assets.php`. **Token contract addresses are security-critical** — a
wrong address would let a worthless look-alike token satisfy an order. Verify any
new contract on-chain (its `decimals()` and that it is the canonical issuer
deployment) and add a test in `tests/php/AssetsTest.php` before submitting.

## Pull requests

1. Branch off `master`.
2. Keep the change focused; add or update tests for anything you touch.
3. Make sure `composer ci` passes.
4. Open the PR against `production` with a clear description of the behaviour
   change and why it is safe.

## Reporting security issues

Please **do not** open a public issue for a vulnerability — see
[SECURITY.md](SECURITY.md).
