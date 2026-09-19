=== Fast Woo Sell ===
Contributors: sangemashhad
Tags: woocommerce, recommendations, related products, cross-sell, upsell
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 8.0
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Smart product recommendations for WooCommerce based on real purchase patterns. All processing stays on your server.

== Description ==

Fast Woo Sell shows shoppers the products they are most likely to need, using:

* **Bought together** — cosine similarity over co-purchases in paid orders.
* **Personal** — the logged-in customer's own order history.
* **Also viewed, trending, best sellers, same category** — fallbacks for cold start.

Design principles:

* No data ever leaves your site. No external API, no telemetry.
* Heavy computation runs in background jobs; the storefront path is one cached query.
* Persian (fa_IR) and RTL supported from day one.

== Installation ==

1. Upload the `fast-woo-sell` folder to `/wp-content/plugins/`.
2. Activate the plugin. Tables are created automatically.
3. The first affinity build runs in the background within a day; you can trigger it from the status page.

== Frequently Asked Questions ==

= Does it need WooCommerce Analytics? =

Yes for the "bought together" engine, which reads the `wc_order_product_lookup` table. Other engines work without it.

== Changelog ==

= 0.4.0 =
* Pre-release: core, tracking, affinity engine and recommendation service. No storefront display yet.
