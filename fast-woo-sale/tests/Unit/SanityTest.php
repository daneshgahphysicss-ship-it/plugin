<?php
/**
 * Placeholder so the unit suite is green from day one; real tests arrive in step 4.
 */
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

final class SanityTest extends TestCase {
	public function test_plugin_header_version_matches_constant(): void {
		$header = (string) file_get_contents( dirname( __DIR__, 2 ) . '/fast-woo-sale.php' );
		preg_match( '/^ \* Version:\s+([\d.]+)/m', $header, $m );
		preg_match( "/define\('FWS_VERSION',\s*'([\d.]+)'\)/", $header, $c );
		$this->assertSame( $m[1], $c[1], 'Plugin header version and FWS_VERSION must match.' );
	}
}
