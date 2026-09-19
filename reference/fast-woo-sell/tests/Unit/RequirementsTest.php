<?php
/**
 * Unit tests for the requirement gate.
 *
 * @package FWS
 */

declare( strict_types=1 );

namespace FWS\Tests\Unit;

use FWS\Core\Requirements;
use PHPUnit\Framework\TestCase;

/**
 * Test double that fakes the WooCommerce probes.
 */
final class FakeWooRequirements extends Requirements {

	/**
	 * Whether the fake WooCommerce is active.
	 *
	 * @var bool
	 */
	public $wc_active = true;

	/**
	 * Version the fake WooCommerce reports.
	 *
	 * @var string
	 */
	public $wc_version = '9.0';

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	protected function woocommerce_active() {
		return $this->wc_active;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	protected function woocommerce_version() {
		return $this->wc_version;
	}
}

/**
 * @covers \FWS\Core\Requirements
 */
final class RequirementsTest extends TestCase {

	/**
	 * Reset globals the stubs read.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['wp_version']    = '6.5';
		$GLOBALS['fws_test_caps'] = array();
	}

	/**
	 * Build a gate with satisfiable floors.
	 *
	 * @param array $overrides Constructor overrides.
	 *
	 * @return FakeWooRequirements
	 */
	private function gate( array $overrides = array() ): FakeWooRequirements {
		return new FakeWooRequirements(
			array_merge(
				array(
					'min_php'         => '5.0',
					'min_wp'          => '5.0',
					'min_wc'          => '8.0',
					'recommended_php' => '5.0',
				),
				$overrides
			)
		);
	}

	/**
	 * A satisfied environment passes and reports nothing.
	 *
	 * @return void
	 */
	public function test_passes_when_every_floor_is_met(): void {
		$gate = $this->gate();

		$this->assertTrue( $gate->check( Requirements::CONTEXT_RUNTIME ) );
		$this->assertSame( array(), $gate->get_failures() );
		$this->assertSame( '', $gate->get_failure_html() );
		$this->assertSame( '', $gate->get_failure_text() );
	}

	/**
	 * An unreachable PHP floor fails with the PHP code.
	 *
	 * @return void
	 */
	public function test_reports_php_below_floor(): void {
		$gate = $this->gate( array( 'min_php' => '99.0' ) );

		$this->assertFalse( $gate->check( Requirements::CONTEXT_RUNTIME ) );
		$this->assertSame(
			array( Requirements::FAIL_PHP ),
			array_column( $gate->get_failures(), 'code' )
		);
	}

	/**
	 * An unreachable WordPress floor fails with the WordPress code.
	 *
	 * @return void
	 */
	public function test_reports_wordpress_below_floor(): void {
		$GLOBALS['wp_version'] = '5.9';

		$gate = $this->gate( array( 'min_wp' => '6.2' ) );

		$this->assertFalse( $gate->check( Requirements::CONTEXT_RUNTIME ) );
		$this->assertSame(
			array( Requirements::FAIL_WP ),
			array_column( $gate->get_failures(), 'code' )
		);
	}

	/**
	 * A release candidate is not treated as older than the final release.
	 *
	 * @return void
	 */
	public function test_release_candidate_satisfies_the_wordpress_floor(): void {
		$GLOBALS['wp_version'] = '6.5-RC2';

		$gate = $this->gate( array( 'min_wp' => '6.5' ) );

		$this->assertTrue( $gate->check( Requirements::CONTEXT_RUNTIME ) );
	}
}
