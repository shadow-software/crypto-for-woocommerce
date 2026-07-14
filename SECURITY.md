# Security Policy

This plugin confirms real-money cryptocurrency payments, so we take security
seriously and welcome responsible disclosure.

## Reporting a vulnerability

**Please do not report security issues through public GitHub issues, pull
requests, or discussions.**

Instead, report privately using either:

- GitHub's [private vulnerability reporting](https://github.com/shadow-software/crypto-for-woocommerce/security/advisories/new)
  (**Security → Report a vulnerability**), or
- email **security@shadowsoftware.com** with the details.

Please include:

- a description of the issue and its impact,
- the plugin version and environment (WordPress / WooCommerce / PHP versions),
- clear reproduction steps or a proof of concept, and
- any suggested remediation.

We will acknowledge your report, keep you updated on our progress, and credit you
in the release notes if you would like once a fix is available.

## Scope

In scope:

- Any way to have an order marked **paid** without a valid, sufficient on-chain
  payment (replay, claiming another buyer's payment, wrong-token/wrong-chain
  acceptance, amount/decimals mis-pricing, confirmation bypass).
- Any way to have a **valid** payment wrongly rejected, or funds mis-directed.
- Standard web vulnerabilities in the plugin (XSS, CSRF, SSRF, injection,
  auth/nonce bypass, sensitive-data exposure).

Out of scope:

- Vulnerabilities in WordPress, WooCommerce, or third-party services themselves.
- Issues that require a compromised server, a malicious administrator, or a
  merchant deliberately mis-configuring their own store to defraud themselves.
- The inherent irreversibility of on-chain payments (this is by design and is
  disclosed to merchants and buyers).

## Supported versions

The latest released version receives security fixes. Because on-chain payments
are irreversible, we strongly recommend always running the latest release.
