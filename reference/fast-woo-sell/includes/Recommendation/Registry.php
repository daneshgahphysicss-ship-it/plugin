<?php
/**
 * Engine registry.
 *
 * @package FWS
 */

namespace FWS\Recommendation;

use FWS\Recommendation\Engines\AlsoBought;
use FWS\Recommendation\Engines\AlsoViewed;
use FWS\Recommendation\Engines\BestSellers;
use FWS\Recommendation\Engines\ManualRelated;
use FWS\Recommendation\Engines\Personal;
use FWS\Recommendation\Engines\RecentlyViewed;
use FWS\Recommendation\Engines\SameCategory;
use FWS\Recommendation\Engines\Trending;
use FWS\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * The engines the plugin knows about, and the order it falls back through.
 *
 * The fallback order runs from most specific to most general. That ordering is
 * the whole reason a recommendation block is never empty: co-purchase data needs
 * orders, co-view needs traffic, category needs neither, and a new shop with no
 * history at all still lands on something sensible.
 */
final class Registry {

	/**
	 * Instances, built once per request.
	 *
	 * @var array<string, Engine>|null
	 */
	private static $engines = null;

	/**
	 * Every registered engine, keyed by id.
	 *
	 * @return array<string, Engine>
	 */
	public static function all(): array {
		if ( null !== self::$engines ) {
			return self::$engines;
		}

		$engines = array(
			new AlsoBought(),
			new Personal(),
			new AlsoViewed(),
			new ManualRelated(),
			new RecentlyViewed(),
			new Trending(),
			new BestSellers(),
			new SameCategory(),
		);

		/**
		 * Filter the available recommendation engines.
		 *
		 * @since 1.0.0
		 *
		 * @param Engine[] $engines Engine instances in fallback order.
		 */
		$engines = (array) apply_filters( 'fws/recommendation/engines', $engines );

		$map = array();

		foreach ( $engines as $engine ) {
			if ( ! $engine instanceof Engine ) {
				Logger::warning( 'Ignored a registered recommendation engine that does not implement the contract.' );

				continue;
			}

			$map[ $engine->id() ] = $engine;
		}

		self::$engines = $map;

		return self::$engines;
	}

	/**
	 * One engine by id.
	 *
	 * @param string $id Engine id.
	 *
	 * @return Engine|null
	 */
	public static function get( string $id ): ?Engine {
		$all = self::all();

		return $all[ $id ] ?? null;
	}

	/**
	 * Whether an id names a known engine.
	 *
	 * @param string $id Engine id.
	 *
	 * @return bool
	 */
	public static function exists( string $id ): bool {
		return null !== self::get( $id );
	}

	/**
	 * Engine ids in fallback order.
	 *
	 * @return string[]
	 */
	public static function order(): array {
		return array_keys( self::all() );
	}

	/**
	 * Id to label, for admin selects.
	 *
	 * @return array<string, string>
	 */
	public static function choices(): array {
		$out = array();

		foreach ( self::all() as $id => $engine ) {
			$out[ $id ] = $engine->label();
		}

		return $out;
	}

	/**
	 * Id to description, for admin help text.
	 *
	 * @return array<string, string>
	 */
	public static function descriptions(): array {
		$out = array();

		foreach ( self::all() as $id => $engine ) {
			$out[ $id ] = $engine->description();
		}

		return $out;
	}

	/**
	 * Forget the built instances.
	 *
	 * Only useful in tests and after a settings change that adds an engine.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$engines = null;
	}
}
