<?php
/**
 * Thin wrapper over the WooCommerce logger.
 *
 * All plugin logs land in the 'shadow-eth' source so a merchant can read them
 * under WooCommerce → Status → Logs. Falls back to error_log() only when the WC
 * logger is unavailable (e.g. during very early bootstrap).
 *
 * @package ShadowEth
 */

namespace ShadowEth;

defined( 'ABSPATH' ) || exit;

/**
 * Static log facade.
 */
final class Logger {

	/**
	 * The log channel/source name.
	 */
	private const SOURCE = 'shadow-eth';

	/**
	 * Log an informational message.
	 *
	 * @param string $message Message text.
	 * @return void
	 */
	public static function info( string $message ): void {
		self::log( 'info', $message );
	}

	/**
	 * Log a warning.
	 *
	 * @param string $message Message text.
	 * @return void
	 */
	public static function warn( string $message ): void {
		self::log( 'warning', $message );
	}

	/**
	 * Log an error.
	 *
	 * @param string $message Message text.
	 * @return void
	 */
	public static function error( string $message ): void {
		self::log( 'error', $message );
	}

	/**
	 * Route a message to the WooCommerce logger at the given level.
	 *
	 * @param string $level   PSR-3 level name.
	 * @param string $message Message text.
	 * @return void
	 */
	private static function log( string $level, string $message ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array( 'source' => self::SOURCE ) );

			return;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Fallback only when the WC logger is unavailable and WP_DEBUG is on.
			error_log( '[shadow-eth] ' . $level . ': ' . $message );
		}
	}
}
