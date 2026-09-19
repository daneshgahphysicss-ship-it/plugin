<?php
/**
 * Contract for every component the plugin boots.
 *
 * @package FWS
 */

namespace FWS\Core;

defined( 'ABSPATH' ) || exit;

/**
 * A component that attaches itself to WordPress.
 *
 * Constructors must stay side effect free: nothing may hook, query or write
 * until register_hooks() is called. That keeps every component cheap to build
 * and safe to instantiate in isolation.
 */
interface Hookable {

	/**
	 * Attach the component to WordPress.
	 *
	 * @return void
	 */
	public function register_hooks(): void;
}
