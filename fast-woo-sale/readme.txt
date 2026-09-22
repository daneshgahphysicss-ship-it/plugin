=== Fast Woo Predictive Purchase ===
Contributors: sangemashhad
Tags: woocommerce, recommendations, market basket, cross-sell, upsell
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 8.0
Stable tag: 2.8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Market-basket analysis of your own WooCommerce orders, turned into product bundles, cart complements, one-click thank-you upsells and next-purchase predictions. Everything runs on your server.

== Description ==

* Mines paid orders (WooCommerce Analytics lookup tables) into a product affinity table with confidence and lift.
* Storefront widgets: product-page bundle, cart complements, thank-you one-click upsell, free-shipping progress bar, account next-purchase prediction, search banner.
* Manual rules and blacklist override the algorithm.
* Full appearance control: disable plugin CSS, presets, CSS variables, custom CSS. No font injection.
* No external requests, no telemetry.

== Changelog ==

= 2.8.1 =
* Fix: settings form was never wrapped in a <form>; "Save settings" now works (Settings API).
* Fix: thank-you upsell now reduces stock when the order stock was already reduced, and refuses to raise the total of an order already paid online (unpaid / offline-gateway orders are redirected to pay).
* Fix: bundle discount applies only to items actually added to the cart.
* Fix: variable/grouped/external products are no longer offered in one-click widgets.
* Fix: search-term cache is keyed on matched product ids (no more transient flooding).
* Fix: mining lock is released on fatal/timeout.
* Fix: search injection only on explicit product searches.
* Fix: recs_limit / min_confidence honoured in cart and free-shipping widgets.
* Fix: stale nonce on cached pages is refreshed automatically.
* Fix: user prediction cache purged on new order; uninstall clears weekly cron; atomic rate limiter; admin JS TypeError; 32-bit crc32; settings memoized per request.

= 2.8.0 =
* Appearance panel: style toggle, presets, palette, typography, custom CSS, live preview.
* Fixed unstyled cart grid and bundle item classes.

See CHANGELOG-*.md files for full history.
