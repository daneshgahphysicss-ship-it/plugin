/**
 * Fast Woo Sell — browser side tracker.
 *
 * Reports the three event types the server accepts from a browser:
 * product views, recommendation impressions and recommendation clicks.
 *
 * Markup contract:
 *   container  <div data-fws-placement="12" data-fws-engine="also_bought" data-fws-variant="a">
 *   item       <a  data-fws-product="123" data-fws-variation="0">
 *
 * Nothing here reads or writes an identifier: identity is an httpOnly cookie
 * the browser cannot touch, so a third party script on the page cannot lift it.
 *
 * @package FWS
 */
( function () {
	'use strict';

	var config = window.fwsTrackingData || {};

	if ( ! config.endpoint ) {
		return;
	}

	// Honour the browser level signals even though the server checks them too:
	// this way an opted out visitor never sends a request at all.
	if ( '1' === navigator.doNotTrack || '1' === window.doNotTrack || true === navigator.globalPrivacyControl ) {
		return;
	}

	var queue = [];
	var seen = {};
	var timer = null;
	var batch = parseInt( config.batch, 10 ) || 20;
	var delay = parseInt( config.delay, 10 ) || 2000;
	var ratio = parseFloat( config.ratio ) || 0.5;
	var dwell = parseInt( config.dwell, 10 ) || 700;
	var surface = config.surface || 'other';

	/**
	 * Add an event to the queue.
	 *
	 * @param {string} type Event type.
	 * @param {Object} data Event fields.
	 */
	function push( type, data ) {
		var event = data || {};

		event.type = type;
		event.surface = event.surface || surface;

		queue.push( event );

		if ( queue.length >= batch ) {
			flush();
			return;
		}

		schedule();
	}

	/**
	 * Flush after a quiet period, so a burst of impressions travels together.
	 */
	function schedule() {
		if ( null !== timer ) {
			return;
		}

		timer = window.setTimeout( function () {
			timer = null;
			flush();
		}, delay );
	}

	/**
	 * Send everything queued.
	 *
	 * @param {boolean} unloading Whether the page is going away.
	 */
	function flush( unloading ) {
		if ( 0 === queue.length ) {
			return;
		}

		if ( null !== timer ) {
			window.clearTimeout( timer );
			timer = null;
		}

		var payload = JSON.stringify( { events: queue.splice( 0, batch ), nonce: config.nonce || '' } );

		// sendBeacon survives the page being torn down, which is the only way a
		// click event recorded immediately before navigation reliably arrives.
		if ( navigator.sendBeacon ) {
			try {
				if ( navigator.sendBeacon( config.endpoint, new Blob( [ payload ], { type: 'application/json' } ) ) ) {
					return;
				}
			} catch ( e ) {
				// Fall through to fetch.
			}
		}

		if ( ! window.fetch ) {
			return;
		}

		window.fetch( config.endpoint, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: payload,
			credentials: 'same-origin',
			keepalive: true === unloading
		} ).catch( function () {} );
	}

	/**
	 * Read the placement context an element sits in.
	 *
	 * @param {Element} element Any node inside a placement.
	 *
	 * @return {Object|null} Context, or null when outside a placement.
	 */
	function context( element ) {
		var container = element.closest ? element.closest( '[data-fws-placement]' ) : null;

		if ( ! container ) {
			return null;
		}

		return {
			placement_id: parseInt( container.getAttribute( 'data-fws-placement' ), 10 ) || 0,
			engine: container.getAttribute( 'data-fws-engine' ) || '',
			variant: container.getAttribute( 'data-fws-variant' ) || '',
			surface: container.getAttribute( 'data-fws-surface' ) || surface
		};
	}

	/**
	 * Build an event for one item node.
	 *
	 * @param {Element} node An element carrying data-fws-product.
	 *
	 * @return {Object|null}
	 */
	function item( node ) {
		var product = parseInt( node.getAttribute( 'data-fws-product' ), 10 ) || 0;

		if ( product <= 0 ) {
			return null;
		}

		var ctx = context( node ) || { placement_id: 0, engine: '', variant: '', surface: surface };

		return {
			object_id: product,
			variation_id: parseInt( node.getAttribute( 'data-fws-variation' ), 10 ) || 0,
			placement_id: ctx.placement_id,
			engine: ctx.engine,
			variant: ctx.variant,
			surface: ctx.surface
		};
	}

	/**
	 * Record an impression once per product per placement per page view.
	 *
	 * @param {Element} node An element carrying data-fws-product.
	 */
	function impress( node ) {
		var event = item( node );

		if ( null === event ) {
			return;
		}

		var key = event.placement_id + ':' + event.object_id;

		if ( seen[ key ] ) {
			return;
		}

		seen[ key ] = true;

		push( 'impression', event );
	}

	/**
	 * Watch every placement for visibility.
	 */
	function observe() {
		var nodes = document.querySelectorAll( '[data-fws-placement] [data-fws-product]' );

		if ( 0 === nodes.length ) {
			return;
		}

		if ( ! window.IntersectionObserver ) {
			// Without the API every item is counted as seen on load. Less precise
			// but better than a placement that never reports at all.
			Array.prototype.forEach.call( nodes, impress );
			return;
		}

		var timers = new WeakMap();

		var observer = new IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( entry.isIntersecting ) {
						// A card that scrolls past in a flick was not really seen,
						// so a short dwell is required before it counts.
						timers.set(
							entry.target,
							window.setTimeout( function () {
								impress( entry.target );
								observer.unobserve( entry.target );
							}, dwell )
						);

						return;
					}

					if ( timers.has( entry.target ) ) {
						window.clearTimeout( timers.get( entry.target ) );
						timers.delete( entry.target );
					}
				} );
			},
			{ threshold: ratio }
		);

		Array.prototype.forEach.call( nodes, function ( node ) {
			observer.observe( node );
		} );
	}

	/**
	 * Record clicks on recommended products.
	 *
	 * Delegated from the document so placements rendered later, by a lazy load
	 * or a variation switch, are covered without rebinding.
	 *
	 * @param {Event} event The click.
	 */
	function onClick( event ) {
		var target = event.target;

		if ( ! target || ! target.closest ) {
			return;
		}

		var node = target.closest( '[data-fws-placement] [data-fws-product]' );

		if ( ! node ) {
			return;
		}

		var data = item( node );

		if ( null === data ) {
			return;
		}

		push( 'click', data );

		// Navigation is imminent, so this cannot wait for the quiet period.
		flush( true );
	}

	/**
	 * Start.
	 */
	function start() {
		var product = parseInt( config.product, 10 ) || 0;

		if ( product > 0 ) {
			push( 'product_view', { object_id: product, surface: 'product' } );
		}

		observe();

		document.addEventListener( 'click', onClick, true );

		// pagehide fires in cases visibilitychange does not, notably Safari, and
		// both are needed to catch a queue that is still waiting on its timer.
		window.addEventListener( 'pagehide', function () {
			flush( true );
		} );

		document.addEventListener( 'visibilitychange', function () {
			if ( 'hidden' === document.visibilityState ) {
				flush( true );
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
