/**
 * Buyer pay-page behaviour: copy-to-clipboard, a QR code for the payment, and
 * live status polling so the order-received page appears automatically once the
 * payment confirms on-chain.
 *
 * No external network calls and no third-party CDN — everything runs from the
 * data localized by PayPage. QR rendering is a progressive enhancement: if the
 * bundled generator is unavailable the address text + copy button still work.
 */
( function () {
	'use strict';

	var config = window.shadowEthPay || {};

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	function copyToClipboard( text ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			return navigator.clipboard.writeText( text );
		}
		return new Promise( function ( resolve, reject ) {
			try {
				var area = document.createElement( 'textarea' );
				area.value = text;
				area.setAttribute( 'readonly', '' );
				area.style.position = 'absolute';
				area.style.left = '-9999px';
				document.body.appendChild( area );
				area.select();
				document.execCommand( 'copy' );
				document.body.removeChild( area );
				resolve();
			} catch ( e ) {
				reject( e );
			}
		} );
	}

	function bindCopy() {
		var buttons = document.querySelectorAll( '.shadow-eth-pay__copy' );
		Array.prototype.forEach.call( buttons, function ( button ) {
			button.addEventListener( 'click', function () {
				var targetId = button.getAttribute( 'data-copy-target' );
				var target = targetId ? document.getElementById( targetId ) : null;
				var text = target ? target.textContent : config.address || '';
				if ( ! text ) {
					return;
				}
				copyToClipboard( text ).then( function () {
					var original = config.copy || button.textContent;
					button.textContent = config.copied || 'Copied!';
					setTimeout( function () {
						button.textContent = original;
					}, 1600 );
				} );
			} );
		} );
	}


	function renderQr() {
		var host = document.getElementById( 'shadow-eth-qr' );
		if ( ! host || ! config.qrUri || typeof window.ShadowEthQR === 'undefined' ) {
			return;
		}
		try {
			// The payment URI (bitcoin: / ethereum: / token transfer) is built
			// server-side per asset and passed in ready to encode.
			window.ShadowEthQR.render( host, config.qrUri );
			host.setAttribute( 'aria-hidden', 'false' );
		} catch ( e ) {
			// QR is optional; ignore failures.
		}
	}

	function pollStatus() {
		var root = document.getElementById( 'shadow-eth-pay' );
		if ( ! root || root.getAttribute( 'data-poll' ) !== '1' ) {
			return;
		}

		var orderId = root.getAttribute( 'data-order-id' );
		var base = root.getAttribute( 'data-status-url' );
		var nonce = root.getAttribute( 'data-status-nonce' );
		if ( ! orderId || ! base || ! nonce ) {
			return;
		}

		var url = base +
			( base.indexOf( '?' ) === -1 ? '?' : '&' ) +
			'action=shadow_eth_payment_status&order_id=' +
			encodeURIComponent( orderId ) +
			'&nonce=' +
			encodeURIComponent( nonce );

		var attempts = 0;
		var maxAttempts = 600; // ~10 min at 1s cadence via setTimeout below.

		function tick() {
			attempts++;
			fetch( url, { credentials: 'same-origin' } )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( payload ) {
					if ( ! payload || ! payload.success || ! payload.data ) {
						return schedule();
					}
					var data = payload.data;
					var msg = document.getElementById( 'shadow-eth-status-msg' );
					if ( data.done ) {
						if ( data.state === 'confirmed' ) {
							if ( msg && config.i18n ) {
								msg.textContent = config.i18n.confirmed;
							}
							if ( data.redirect ) {
								window.location.href = data.redirect;
								return;
							}
							window.location.reload();
							return;
						}
						// Failed — reload to show the final state panel.
						window.location.reload();
						return;
					}
					schedule();
				} )
				.catch( function () {
					schedule();
				} );
		}

		function schedule() {
			if ( attempts >= maxAttempts ) {
				return;
			}
			// Back off gently: 3s for the first two minutes, then 8s.
			var delay = attempts < 40 ? 3000 : 8000;
			setTimeout( tick, delay );
		}

		setTimeout( tick, 3000 );
	}

	ready( function () {
		bindCopy();
		renderQr();
		pollStatus();
	} );
} )();
