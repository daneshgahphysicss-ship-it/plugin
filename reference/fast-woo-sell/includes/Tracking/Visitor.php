<?php
/**
 * Visitor and session identity.
 *
 * @package FWS
 */

namespace FWS\Tracking;

use FWS\Core\Config;
use FWS\Support\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Issues and reads the anonymous visitor identifier.
 *
 * The identifier is minted on the server and stored in a single httpOnly
 * cookie. A value supplied in a request body is always ignored: if the client
 * could choose its own identifier it could also claim someone else's history,
 * and every affinity and attribution figure downstream would be forgeable.
 *
 * The cookie is written only on requests that are never cached, which is why
 * page views are reported through the tracking endpoint rather than issuing a
 * cookie on every front end GET. That keeps full page caches intact.
 *
 * Cookie payload is `visitor|session|last_seen`, which avoids a second cookie
 * for the session and lets an idle gap start a new session with no server side
 * storage at all.
 */
final class Visitor {

	/**
	 * Cookie name.
	 */
	const COOKIE = 'fws_v';

	/**
	 * How long the visitor identifier survives, in seconds. 180 days.
	 */
	const LIFETIME = 15552000;

	/**
	 * Idle gap that starts a new session, in seconds.
	 */
	const SESSION_IDLE = 1800;

	/**
	 * Order meta key holding the visitor identifier at checkout.
	 */
	const ORDER_META = '_fws_visitor';

	/**
	 * Resolved visitor identifier for this request.
	 *
	 * @var string
	 */
	private static $visitor_id = '';

	/**
	 * Resolved session identifier for this request.
	 *
	 * @var string
	 */
	private static $session_id = '';

	/**
	 * Unix time the cookie last recorded activity.
	 *
	 * @var int
	 */
	private static $last_seen = 0;

	/**
	 * Cached trackability decision.
	 *
	 * @var bool|null
	 */
	private static $trackable = null;

	/**
	 * The visitor identifier, minting one in memory when the cookie is absent.
	 *
	 * @return string 32 character hexadecimal string.
	 */
	public static function id(): string {
		self::resolve();

		return self::$visitor_id;
	}

	/**
	 * The session identifier.
	 *
	 * @return string 32 character hexadecimal string.
	 */
	public static function session_id(): string {
		self::resolve();

		return self::$session_id;
	}

	/**
	 * The logged in user id, or zero.
	 *
	 * @return int
	 */
	public static function user_id(): int {
		return get_current_user_id();
	}

	/**
	 * Whether the cookie already exists, meaning the identity is durable.
	 *
	 * @return bool
	 */
	public static function has_cookie(): bool {
		return isset( $_COOKIE[ self::COOKIE ] ) && '' !== (string) $_COOKIE[ self::COOKIE ];
	}

	/**
	 * Persist the identity and refresh the session window.
	 *
	 * Safe to call more than once per request. Must only be called from a
	 * request that is not cached, which in practice means the tracking endpoint
	 * or a WooCommerce write hook.
	 *
	 * @return void
	 */
	public static function touch(): void {
		self::resolve();

		if ( headers_sent() ) {
			return;
		}

		$now   = time();
		$value = self::$visitor_id . '|' . self::$session_id . '|' . $now;

		self::$last_seen = $now;

		$_COOKIE[ self::COOKIE ] = $value;

		self::write_cookie( $value, $now + self::LIFETIME );
	}

	/**
	 * Drop the cookie, for a privacy erasure request.
	 *
	 * @return void
	 */
	public static function forget(): void {
		self::flush();

		unset( $_COOKIE[ self::COOKIE ] );

		if ( headers_sent() ) {
			return;
		}

		self::write_cookie( '', time() - YEAR_IN_SECONDS );
	}

	/**
	 * Whether this request may be recorded at all.
	 *
	 * @return bool
	 */
	public static function is_trackable(): bool {
		if ( null !== self::$trackable ) {
			return self::$trackable;
		}

		self::$trackable = self::decide_trackable();

		return self::$trackable;
	}

	/**
	 * Device class as a single character: d, t or m.
	 *
	 * Derived from the user agent rather than the viewport because the value is
	 * also needed on server side events, where no browser is present.
	 *
	 * @return string
	 */
	public static function device(): string {
		$agent = self::user_agent();

		if ( '' === $agent ) {
			return '';
		}

		if ( preg_match( '/(ipad|tablet|playbook|silk|android(?!.*mobile))/i', $agent ) ) {
			return 't';
		}

		if ( preg_match( '/(mobile|iphone|ipod|android|blackberry|windows phone|opera mini)/i', $agent ) ) {
			return 'm';
		}

		return 'd';
	}

	/**
	 * Forget every memoised value. Test helper.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$visitor_id = '';
		self::$session_id = '';
		self::$last_seen  = 0;
		self::$trackable  = null;
	}

	/**
	 * Store the current visitor identifier on an order.
	 *
	 * Without this, an order paid hours later by a gateway callback carries no
	 * cookie and could never be attributed.
	 *
	 * @param \WC_Order $order The order.
	 *
	 * @return void
	 */
	public static function attach_to_order( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		if ( '' !== (string) $order->get_meta( self::ORDER_META, true ) ) {
			return;
		}

		$visitor_id = self::id();

		if ( '' === $visitor_id ) {
			return;
		}

		$order->update_meta_data( self::ORDER_META, $visitor_id );
		$order->save_meta_data();
	}

	/**
	 * Read the visitor identifier recorded on an order.
	 *
	 * @param \WC_Order $order The order.
	 *
	 * @return string
	 */
	public static function from_order( $order ): string {
		if ( ! $order instanceof \WC_Order ) {
			return '';
		}

		return Sanitize::hex( $order->get_meta( self::ORDER_META, true ), 32 );
	}

	/**
	 * Read the cookie, or mint identifiers for this request.
	 *
	 * @return void
	 */
	private static function resolve(): void {
		if ( '' !== self::$visitor_id ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each part is validated by Sanitize below.
		$raw   = isset( $_COOKIE[ self::COOKIE ] ) ? (string) wp_unslash( $_COOKIE[ self::COOKIE ] ) : '';
		$parts = '' === $raw ? array() : explode( '|', $raw, 3 );

		$visitor   = isset( $parts[0] ) ? Sanitize::hex( $parts[0], 32 ) : '';
		$session   = isset( $parts[1] ) ? Sanitize::hex( $parts[1], 32 ) : '';
		$last_seen = isset( $parts[2] ) ? absint( $parts[2] ) : 0;

		// A malformed cookie is treated as absent rather than repaired, so a
		// tampered value can never be partially trusted.
		if ( '' === $visitor ) {
			$visitor   = self::mint();
			$session   = '';
			$last_seen = 0;
		}

		if ( '' === $session || $last_seen <= 0 || ( time() - $last_seen ) > self::SESSION_IDLE ) {
			$session = self::mint();
		}

		self::$visitor_id = $visitor;
		self::$session_id = $session;
		self::$last_seen  = $last_seen;
	}

	/**
	 * Send the identity cookie.
	 *
	 * @param string $value   Cookie value.
	 * @param int    $expires Expiry as a Unix timestamp.
	 *
	 * @return void
	 */
	private static function write_cookie( string $value, int $expires ): void {
		setcookie(
			self::COOKIE,
			$value,
			array(
				'expires'  => $expires,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * A new random identifier.
	 *
	 * @return string
	 */
	private static function mint(): string {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Exception $e ) {
			// random_bytes only throws when the platform has no CSPRNG. The
			// WordPress fallback is weaker but keeps tracking working.
			return md5( wp_generate_password( 32, true, true ) . microtime( true ) );
		}
	}

	/**
	 * The trackability decision, before memoisation.
	 *
	 * @return bool
	 */
	private static function decide_trackable(): bool {
		// Cron and the WooCommerce webhook path write server side events with no
		// browser present; a missing user agent must not block them.
		if ( wp_doing_cron() ) {
			return true;
		}

		if ( Config::bool( 'respect_dnt' ) && self::privacy_signal() ) {
			return false;
		}

		if ( ! Config::bool( 'track_logged_out' ) && 0 === self::user_id() ) {
			return false;
		}

		if ( self::looks_like_bot() ) {
			return false;
		}

		/**
		 * Filter whether the current request may be recorded.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $trackable Decision so far.
		 */
		return (bool) apply_filters( 'fws/tracking/is_trackable', true );
	}

	/**
	 * Whether the browser asked not to be tracked.
	 *
	 * @return bool
	 */
	private static function privacy_signal(): bool {
		$dnt = isset( $_SERVER['HTTP_DNT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_DNT'] ) ) : '';
		$gpc = isset( $_SERVER['HTTP_SEC_GPC'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_SEC_GPC'] ) ) : '';

		return '1' === $dnt || '1' === $gpc;
	}

	/**
	 * Cheap crawler detection.
	 *
	 * Deliberately a short list of high volume crawlers rather than an attempt at
	 * completeness: the cost of a missed bot is one noisy row, the cost of a
	 * false positive is a real visitor who is never recommended anything.
	 *
	 * @return bool
	 */
	private static function looks_like_bot(): bool {
		$agent = self::user_agent();

		if ( '' === $agent ) {
			return true;
		}

		return (bool) preg_match(
			'/(bot|crawl|spider|slurp|facebookexternalhit|preview|monitor|curl|wget|python-requests|headless|lighthouse|pingdom|semrush|ahrefs)/i',
			$agent
		);
	}

	/**
	 * The user agent string.
	 *
	 * @return string
	 */
	private static function user_agent(): string {
		if ( ! isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return '';
		}

		return substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 );
	}
}
