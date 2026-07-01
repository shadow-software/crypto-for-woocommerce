<?php
/**
 * Dependency + version manifest for assets/js/blocks.js.
 *
 * Hand-maintained (the plugin ships no JS build step). Keep the version in step
 * with SHADOW_ETH_VERSION so cache busting works after an update.
 *
 * @package ShadowEth
 */

return array(
	'dependencies' => array(
		'wc-blocks-registry',
		'wc-settings',
		'wp-element',
		'wp-html-entities',
		'wp-i18n',
	),
	'version'      => '1.0.0',
);
