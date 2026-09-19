<?php
/**
 * Requirement gate for PHP, WordPress and WooCommerce.
 *
 * COMPATIBILITY CONTRACT: this file runs before the PHP version gate, so it
 * must stay parseable by PHP 5.6. See the note at the top of the main plugin
 * file for the list of forbidden syntax.
 *
 * @package FWS
 */

namespace FWS\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Collects unmet requirements and renders them for humans.
 *
 * Failure messages are built lazily, at render time, so that no translated
 * string is requested during `plugins_loaded`.
 *
 * Not marked final: the WooCommerce probes are protected seams so the gate can
 * be unit tested without a WooCommerce install.
 */
class Requirements {

	const CONTEXT_ACTIVATION = 'activation';
	const CONTEXT_RUNTIME    = 'runtime';

	const FAIL_PHP        = 'php_too_old';
	const FAIL_WP         = 'wp_too_old';
	const FAIL_WC_MISSING = 'wc_missing';
	const FAIL_WC_OLD     = 'wc_too_old';

	const WARN_PHP = 'php_below_recommended';

	/**
	 * Minimum supported PHP version.
	 *
	 * @var string
	 */
	private $min_php;

	/**
	 * Minimum supported WordPress version.
	 *
	 * @var string
	 */
	private $min_wp;

	/**
	 * Minimum supported WooCommerce version.
	 *
	 * @var string
	 */
	private $min_wc;

	/**
	 * PHP version we recommend but do not enforce.
	 *
	 * @var string
	 */
	private $recommended_php;

	/**
	 * Cached results keyed by context.
	 *
	 * @var array
	 */
	private $results = array();

	/**
	 * Context of the most recent check() call.
	 *
	 * @var string
	 */
	private $last_context = '';

	/**
	 * Constructor.
	 *
	 * @param array $args Optional overrides for the version floors.
	 */
	public function __construct( $args = array() ) {
		$defaults = array(
			'min_php'         => '7.4',
			'min_wp'          => '6.2',
			'min_wc'          => '8.0',
			'recommended_php' => '8.1',
		);

		if ( ! is_array( $args ) ) {
			$args = array();
		}

		$args = array_merge( $defaults, $args );

		$this->min_php         = (string) $args['min_php'];
		$this->min_wp          = (string) $args['min_wp'];
		$this->min_wc          = (string) $args['min_wc'];
		$this->recommended_php = (string) $args['recommended_php'];
	}

	/**
	 * Run the gate for a context.
	 *
	 * WooCommerce is only probed in the runtime context. See the note on
	 * fws_on_activation() for why.
	 *
	 * @param string $context One of the CONTEXT_* constants.
	 *
	 * @return bool True when every hard requirement is met.
	 */
	public function check( $context = self::CONTEXT_RUNTIME ) {
		$context = self::CONTEXT_ACTIVATION === $context
			? self::CONTEXT_ACTIVATION
			: self::CONTEXT_RUNTIME;

		$this->last_context = $context;

		if ( isset( $this->results[ $context ] ) ) {
			return empty( $this->results[ $context ]['failures'] );
		}

		$failures = array();
		$warnings = array();

		$php_failure = $this->check_php();

		if ( is_array( $php_failure ) ) {
			$failures[] = $php_failure;
		}

		$wp_failure = $this->check_wp();

		if ( is_array( $wp_failure ) ) {
			$failures[] = $wp_failure;
		}

		if ( self::CONTEXT_RUNTIME === $context ) {
			$wc_failure = $this->check_woocommerce();

			if ( is_array( $wc_failure ) ) {
				$failures[] = $wc_failure;
			}
		}

		$php_warning = $this->warn_php();

		if ( is_array( $php_warning ) ) {
			$warnings[] = $php_warning;
		}

		$this->results[ $context ] = array(
			'failures' => $failures,
			'warnings' => $warnings,
		);

		return empty( $failures );
	}

	/**
	 * Hard check: PHP version.
	 *
	 * @return array|null Failure descriptor, or null when satisfied.
	 */
	private function check_php() {
		if ( version_compare( PHP_VERSION, $this->min_php, '>=' ) ) {
			return null;
		}

		return array(
			'code' => self::FAIL_PHP,
			'data' => array(
				'required' => $this->min_php,
				'current'  => PHP_VERSION,
			),
		);
	}

	/**
	 * Hard check: WordPress version.
	 *
	 * @return array|null Failure descriptor, or null when satisfied.
	 */
	private function check_wp() {
		$current = $this->wp_version();

		if ( version_compare( $current, $this->min_wp, '>=' ) ) {
			return null;
		}

		return array(
			'code' => self::FAIL_WP,
			'data' => array(
				'required' => $this->min_wp,
				'current'  => $current,
			),
		);
	}

	/**
	 * Hard check: WooCommerce presence and version.
	 *
	 * @return array|null Failure descriptor, or null when satisfied.
	 */
	private function check_woocommerce() {
		if ( ! $this->woocommerce_active() ) {
			return array(
				'code' => self::FAIL_WC_MISSING,
				'data' => array( 'required' => $this->min_wc ),
			);
		}

		$current = $this->woocommerce_version();

		if ( version_compare( $current, $this->min_wc, '>=' ) ) {
			return null;
		}

		return array(
			'code' => self::FAIL_WC_OLD,
			'data' => array(
				'required' => $this->min_wc,
				'current'  => $current,
			),
		);
	}

	/**
	 * Whether WooCommerce is loaded.
	 *
	 * Protected so tests can override it without a WooCommerce install.
	 *
	 * @return bool
	 */
	protected function woocommerce_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Loaded WooCommerce version, or `0` when unknown.
	 *
	 * Protected so tests can override it without a WooCommerce install.
	 *
	 * @return string
	 */
	protected function woocommerce_version() {
		return defined( 'WC_VERSION' ) ? (string) WC_VERSION : '0';
	}

	/**
	 * Soft check: PHP below the recommended version.
	 *
	 * Collected but never rendered as an admin notice. It surfaces inside the
	 * Site Health report instead, so the plugin never nags.
	 *
	 * @return array|null Warning descriptor, or null when satisfied.
	 */
	private function warn_php() {
		if ( version_compare( PHP_VERSION, $this->recommended_php, '>=' ) ) {
			return null;
		}

		return array(
			'code' => self::WARN_PHP,
			'data' => array(
				'recommended' => $this->recommended_php,
				'current'     => PHP_VERSION,
			),
		);
	}

	/**
	 * Normalised WordPress version.
	 *
	 * Strips pre-release suffixes such as `-RC1` so that a release candidate is
	 * not reported as older than the matching final release.
	 *
	 * @return string
	 */
	private function wp_version() {
		$version = isset( $GLOBALS['wp_version'] ) ? (string) $GLOBALS['wp_version'] : '0';
		$version = preg_replace( '/[^0-9.].*$/', '', $version );

		return '' === $version ? '0' : $version;
	}

	/**
	 * Failure descriptors from a previous check().
	 *
	 * @param string|null $context Context to read, defaults to the last checked.
	 *
	 * @return array
	 */
	public function get_failures( $context = null ) {
		return $this->read( 'failures', $context );
	}

	/**
	 * Warning descriptors from a previous check().
	 *
	 * @param string|null $context Context to read, defaults to the last checked.
	 *
	 * @return array
	 */
	public function get_warnings( $context = null ) {
		return $this->read( 'warnings', $context );
	}

	/**
	 * Read one bucket of a cached result.
	 *
	 * @param string      $bucket  Either `failures` or `warnings`.
	 * @param string|null $context Context to read, defaults to the last checked.
	 *
	 * @return array
	 */
	private function read( $bucket, $context ) {
		if ( null === $context || '' === $context ) {
			$context = $this->last_context;
		}

		if ( ! isset( $this->results[ $context ][ $bucket ] ) ) {
			return array();
		}

		return $this->results[ $context ][ $bucket ];
	}

	/**
	 * Human readable sentence for one descriptor.
	 *
	 * @param array $descriptor Failure or warning descriptor.
	 *
	 * @return string Plain, untranslated-safe text. Not escaped.
	 */
	public function describe( $descriptor ) {
		$code = isset( $descriptor['code'] ) ? $descriptor['code'] : '';
		$data = isset( $descriptor['data'] ) && is_array( $descriptor['data'] ) ? $descriptor['data'] : array();

		$required    = isset( $data['required'] ) ? $data['required'] : '';
		$current     = isset( $data['current'] ) ? $data['current'] : '';
		$recommended = isset( $data['recommended'] ) ? $data['recommended'] : '';

		switch ( $code ) {
			case self::FAIL_PHP:
				/* translators: 1: required PHP version, 2: current PHP version */
				$format = __( 'به PHP نسخه %1$s یا بالاتر نیاز است. نسخه فعلی سرور شما %2$s است.', 'fast-woo-sell' );

				return sprintf( $format, $required, $current );

			case self::FAIL_WP:
				/* translators: 1: required WordPress version, 2: current WordPress version */
				$format = __( 'به وردپرس نسخه %1$s یا بالاتر نیاز است. نسخه فعلی شما %2$s است.', 'fast-woo-sell' );

				return sprintf( $format, $required, $current );

			case self::FAIL_WC_MISSING:
				/* translators: %s: required WooCommerce version */
				$format = __( 'ووکامرس نصب یا فعال نیست. به ووکامرس نسخه %s یا بالاتر نیاز است.', 'fast-woo-sell' );

				return sprintf( $format, $required );

			case self::FAIL_WC_OLD:
				/* translators: 1: required WooCommerce version, 2: current WooCommerce version */
				$format = __( 'به ووکامرس نسخه %1$s یا بالاتر نیاز است. نسخه فعلی شما %2$s است.', 'fast-woo-sell' );

				return sprintf( $format, $required, $current );

			case self::WARN_PHP:
				/* translators: 1: recommended PHP version, 2: current PHP version */
				$format = __( 'افزونه روی PHP %2$s کار می‌کند، اما نسخه %1$s یا بالاتر سریع‌تر و امن‌تر است.', 'fast-woo-sell' );

				return sprintf( $format, $recommended, $current );
		}

		return '';
	}

	/**
	 * Escaped HTML list of the current failures.
	 *
	 * Safe to pass through wp_kses_post(). Returns an empty string when there
	 * is nothing to report.
	 *
	 * @param string|null $context Context to read, defaults to the last checked.
	 *
	 * @return string
	 */
	public function get_failure_html( $context = null ) {
		$failures = $this->get_failures( $context );

		if ( empty( $failures ) ) {
			return '';
		}

		$items = '';

		foreach ( $failures as $failure ) {
			$text = $this->describe( $failure );

			if ( '' === $text ) {
				continue;
			}

			$items .= '<li>' . esc_html( $text ) . $this->action_link( $failure ) . '</li>';
		}

		if ( '' === $items ) {
			return '';
		}

		return '<ul style="margin-inline-start:1.5em;list-style:disc;">' . $items . '</ul>';
	}

	/**
	 * Plain text version of the current failures, for logs and WP-CLI.
	 *
	 * @param string|null $context Context to read, defaults to the last checked.
	 *
	 * @return string
	 */
	public function get_failure_text( $context = null ) {
		$lines = array();

		foreach ( $this->get_failures( $context ) as $failure ) {
			$text = $this->describe( $failure );

			if ( '' !== $text ) {
				$lines[] = $text;
			}
		}

		return implode( ' ', $lines );
	}

	/**
	 * Optional follow-up link for a failure.
	 *
	 * @param array $failure Failure descriptor.
	 *
	 * @return string Escaped HTML, or an empty string.
	 */
	private function action_link( $failure ) {
		$code = isset( $failure['code'] ) ? $failure['code'] : '';

		if ( self::FAIL_WC_MISSING !== $code ) {
			return '';
		}

		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'install_plugins' ) ) {
			return '';
		}

		$url = add_query_arg(
			array(
				'tab'    => 'plugin-information',
				'plugin' => 'woocommerce',
			),
			admin_url( 'plugin-install.php' )
		);

		return ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'نصب ووکامرس', 'fast-woo-sell' ) . '</a>';
	}
}
