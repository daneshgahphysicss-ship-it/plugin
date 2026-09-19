<?php
/**
 * Logging.
 *
 * @package FWS
 */

namespace FWS\Support;

use FWS\Core\Config;

defined( 'ABSPATH' ) || exit;

/**
 * Writes to the WooCommerce log under a single source.
 *
 * Logs land in WooCommerce > Status > Logs so shop owners already know where
 * to look, and so log rotation and retention are handled for us. Nothing is
 * ever sent anywhere off site.
 */
final class Logger {

	/**
	 * Log source, which becomes the log file name.
	 */
	const SOURCE = 'fast-woo-sell';

	/**
	 * Cached WooCommerce logger.
	 *
	 * @var \WC_Logger_Interface|null
	 */
	private static $logger = null;

	/**
	 * Log a message that only matters while debugging.
	 *
	 * Suppressed unless debug logging is switched on, because this level is
	 * emitted on hot paths.
	 *
	 * @param string $message Message.
	 * @param array  $context Extra data appended to the message.
	 *
	 * @return void
	 */
	public static function debug( string $message, array $context = array() ): void {
		if ( ! self::debug_enabled() ) {
			return;
		}

		self::write( 'debug', $message, $context );
	}

	/**
	 * Log a notable but expected event.
	 *
	 * @param string $message Message.
	 * @param array  $context Extra data appended to the message.
	 *
	 * @return void
	 */
	public static function info( string $message, array $context = array() ): void {
		self::write( 'info', $message, $context );
	}

	/**
	 * Log a recoverable problem.
	 *
	 * @param string $message Message.
	 * @param array  $context Extra data appended to the message.
	 *
	 * @return void
	 */
	public static function warning( string $message, array $context = array() ): void {
		self::write( 'warning', $message, $context );
	}

	/**
	 * Log a failure that stopped work from completing.
	 *
	 * @param string $message Message.
	 * @param array  $context Extra data appended to the message.
	 *
	 * @return void
	 */
	public static function error( string $message, array $context = array() ): void {
		self::write( 'error', $message, $context );
	}

	/**
	 * Log a caught exception at error level.
	 *
	 * @param string     $message   What was being attempted.
	 * @param \Throwable $throwable The caught exception.
	 * @param array      $context   Extra data appended to the message.
	 *
	 * @return void
	 */
	public static function exception( string $message, \Throwable $throwable, array $context = array() ): void {
		$context['exception'] = get_class( $throwable );
		$context['reason']    = $throwable->getMessage();
		$context['at']        = $throwable->getFile() . ':' . $throwable->getLine();

		self::write( 'error', $message, $context );
	}

	/**
	 * Whether debug level messages are recorded.
	 *
	 * @return bool
	 */
	public static function debug_enabled(): bool {
		if ( defined( 'FWS_DEBUG' ) && FWS_DEBUG ) {
			return true;
		}

		return class_exists( Config::class ) && Config::bool( 'debug_logging' );
	}

	/**
	 * Write one line.
	 *
	 * @param string $level   One of debug, info, warning, error.
	 * @param string $message Message.
	 * @param array  $context Extra data appended to the message.
	 *
	 * @return void
	 */
	private static function write( string $level, string $message, array $context ): void {
		$line = self::format( $message, $context );

		$logger = self::logger();

		if ( null !== $logger ) {
			$logger->log( $level, $line, array( 'source' => self::SOURCE ) );

			return;
		}

		// WooCommerce is not loaded, which happens during activation failures
		// and uninstall. Fall back only when the site asked for debug output.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Deliberate fallback when WooCommerce is unavailable.
			error_log( sprintf( '[%s] %s: %s', self::SOURCE, $level, $line ) );
		}
	}

	/**
	 * Flatten the context onto the message.
	 *
	 * Values are rendered as a compact key=value list rather than JSON so log
	 * lines stay greppable, and only scalars are printed so an object graph can
	 * never be dumped into a log file.
	 *
	 * @param string $message Message.
	 * @param array  $context Extra data.
	 *
	 * @return string
	 */
	private static function format( string $message, array $context ): string {
		if ( empty( $context ) ) {
			return $message;
		}

		$parts = array();

		foreach ( $context as $key => $value ) {
			if ( is_bool( $value ) ) {
				$value = $value ? 'true' : 'false';
			} elseif ( null === $value ) {
				$value = 'null';
			} elseif ( is_array( $value ) ) {
				$value = '[' . count( $value ) . ' items]';
			} elseif ( is_object( $value ) ) {
				$value = get_class( $value );
			}

			$parts[] = $key . '=' . (string) $value;
		}

		return $message . ' | ' . implode( ' ', $parts );
	}

	/**
	 * The WooCommerce logger, when available.
	 *
	 * @return \WC_Logger_Interface|null
	 */
	private static function logger() {
		if ( null !== self::$logger ) {
			return self::$logger;
		}

		if ( ! function_exists( 'wc_get_logger' ) ) {
			return null;
		}

		self::$logger = wc_get_logger();

		return self::$logger;
	}
}
