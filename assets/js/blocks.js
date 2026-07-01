/**
 * WooCommerce Cart & Checkout Blocks registration for the Ethereum gateway.
 *
 * The method carries no on-checkout fields — the buyer pays and confirms on the
 * order-pay page after placing the order — so this simply renders the label and
 * description and lets WooCommerce redirect to the pay page on success.
 */
( function () {
	'use strict';

	var settings = window.wc.wcSettings.getSetting( 'shadow_eth_data', {} );
	var element = window.wp.element;
	var decodeEntities = window.wp.htmlEntities.decodeEntities;
	var registry = window.wc.wcBlocksRegistry;

	var label = decodeEntities( settings.title || 'Pay with crypto (ETH, USDC, USDT, BTC)' );

	function Content() {
		return element.createElement(
			'div',
			{ className: 'shadow-eth-blocks-description' },
			decodeEntities( settings.description || '' )
		);
	}

	function Label() {
		var icon = settings.icon
			? element.createElement( 'img', {
				src: settings.icon,
				alt: '',
				style: { width: '24px', height: '24px', marginInlineEnd: '8px', verticalAlign: 'middle' }
			} )
			: null;
		return element.createElement(
			'span',
			{ style: { display: 'inline-flex', alignItems: 'center' } },
			icon,
			element.createElement( 'span', null, label )
		);
	}

	registry.registerPaymentMethod( {
		name: 'shadow_eth',
		label: element.createElement( Label, null ),
		content: element.createElement( Content, null ),
		edit: element.createElement( Content, null ),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: label,
		supports: {
			features: ( settings.supports && settings.supports.length ) ? settings.supports : [ 'products' ]
		}
	} );
} )();
