<?php
/**
 * Boot orchestrator.
 *
 * @package FWS
 */

namespace FWS\Core;

use FWS\Support\Logger;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Builds and registers every component, once per request.
 *
 * Components are plain classes that implement Hookable. They are listed by id
 * so a third party, or a test, can swap one out without touching this file.
 * Each registration is isolated: a component that throws is logged and skipped
 * rather than being allowed to take the store down with it.
 */
final class Plugin {

	/**
	 * Singleton.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Whether boot() has already run.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Registered components, keyed by id.
	 *
	 * @var array<string, Hookable>
	 */
	private $components = array();

	/**
	 * Private constructor; use instance().
	 */
	private function __construct() {}

	/**
	 * The shared instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Build and register every component.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		// Schema catch-up after a plugin update. Deliberately admin and cron
		// only: no front end request should ever be able to trigger DDL.
		add_action( 'admin_init', array( \FWS\Install\Migrator::class, 'maybe_upgrade' ), 5 );

		// Reschedule anything that went missing, for instance while WooCommerce
		// was deactivated and the custom intervals were undefined.
		add_action( 'admin_init', array( Cron::class, 'schedule_all' ), 6 );

		foreach ( $this->component_map() as $id => $class_name ) {
			$this->register( (string) $id, (string) $class_name );
		}

		/**
		 * Fires after every component has been registered.
		 *
		 * @since 1.0.0
		 *
		 * @param Plugin $plugin The plugin instance.
		 */
		do_action( 'fws/booted', $this );
	}

	/**
	 * A registered component.
	 *
	 * @param string $id Component id.
	 *
	 * @return Hookable|null
	 */
	public function component( string $id ) {
		return isset( $this->components[ $id ] ) ? $this->components[ $id ] : null;
	}

	/**
	 * Every registered component.
	 *
	 * @return array<string, Hookable>
	 */
	public function components(): array {
		return $this->components;
	}

	/**
	 * Whether boot() has run.
	 *
	 * @return bool
	 */
	public function is_booted(): bool {
		return $this->booted;
	}

	/**
	 * Instantiate one component and let it hook itself in.
	 *
	 * @param string $id         Component id.
	 * @param string $class_name Fully qualified class name.
	 *
	 * @return void
	 */
	private function register( string $id, string $class_name ): void {
		if ( ! class_exists( $class_name ) ) {
			Logger::error(
				'Component class not found.',
				array(
					'id'    => $id,
					'class' => $class_name,
				)
			);

			return;
		}

		try {
			$component = new $class_name();

			if ( ! $component instanceof Hookable ) {
				Logger::error(
					'Component does not implement Hookable.',
					array(
						'id'    => $id,
						'class' => $class_name,
					)
				);

				return;
			}

			$component->register_hooks();

			$this->components[ $id ] = $component;
		} catch ( Throwable $e ) {
			Logger::exception(
				'Component failed to register.',
				$e,
				array(
					'id'    => $id,
					'class' => $class_name,
				)
			);
		}
	}

	/**
	 * Component id to class name.
	 *
	 * Module groups are gated on their master switch so a disabled module costs
	 * nothing beyond this array lookup: no class is loaded, no hook is added.
	 *
	 * @return array<string, string>
	 */
	private function component_map(): array {
		$map = array(
			// Tracking is the foundation every other module reads from, so it is
			// never gated on a feature switch. Individual event types are gated
			// inside the recorder instead.
			'tracking.server'  => \FWS\Tracking\ServerEvents::class,
			'tracking.rest'    => \FWS\Tracking\RestController::class,
			'tracking.assets'  => \FWS\Tracking\Assets::class,
			'tracking.rollup'  => \FWS\Tracking\Rollup::class,
			'tracking.cleanup' => \FWS\Tracking\Cleanup::class,
		);

		// The affinity table is what every statistical engine reads, so the job
		// that builds it is gated on the same switch as the engines themselves.
		// Leaving it running while recommendations are off would spend cron time
		// on data nothing reads.
		if ( Config::bool( 'recommendations_enabled' ) ) {
			$map['recommendation.affinity'] = \FWS\Recommendation\AffinityBuilder::class;
		}

		/**
		 * Filter the components the plugin boots.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string> $map Component id to class name.
		 */
		return (array) apply_filters( 'fws/components', $map );
	}
}
