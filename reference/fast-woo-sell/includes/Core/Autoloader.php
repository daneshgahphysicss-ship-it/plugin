<?php
/**
 * Namespace based class loader.
 *
 * @package FWS
 */

namespace FWS\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Maps the FWS namespace onto the includes directory.
 *
 * FWS\Recommendations\Engines\BoughtTogether
 *   -> includes/Recommendations/Engines/BoughtTogether.php
 */
final class Autoloader {

	/**
	 * Namespace prefix this loader owns.
	 */
	const PREFIX = 'FWS\\';

	/**
	 * Absolute path the prefix maps onto, with a trailing separator.
	 *
	 * @var string
	 */
	private $base_dir;

	/**
	 * Constructor.
	 *
	 * @param string $base_dir Directory the namespace prefix maps onto.
	 */
	public function __construct( string $base_dir ) {
		$this->base_dir = rtrim( $base_dir, '/\\' ) . DIRECTORY_SEPARATOR;
	}

	/**
	 * Create and register a loader.
	 *
	 * @param string $base_dir Directory the namespace prefix maps onto.
	 *
	 * @return self
	 */
	public static function register( string $base_dir ): self {
		$loader = new self( $base_dir );

		spl_autoload_register( array( $loader, 'load' ) );

		return $loader;
	}

	/**
	 * Load a class file.
	 *
	 * @param string $class_name Fully qualified class name.
	 *
	 * @return void
	 */
	public function load( string $class_name ): void {
		$length = strlen( self::PREFIX );

		if ( 0 !== strncmp( self::PREFIX, $class_name, $length ) ) {
			return;
		}

		$relative = substr( $class_name, $length );

		// A class name can never legitimately contain a traversal segment.
		if ( '' === $relative || false !== strpos( $relative, '..' ) ) {
			return;
		}

		$file = $this->base_dir . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
