<?php
/**
 * WP-CLI development commands: `wp fws seed`, `wp fws affinity`, `wp fws status`.
 *
 * DEVELOPMENT ONLY. This file lives under tools/ and is excluded from the
 * release archive by .distignore. It is loaded by the wp-env mu-plugin
 * (tools/wp-env/fws-dev.php), never by the plugin bootstrap.
 *
 * Why a seeder with a *known* pattern instead of random data: step 5 of the
 * plan verifies the affinity maths by hand. That is only possible when we know
 * exactly how many orders contain product A, product B, and both. See
 * FWS_Dev_Command::PATTERN for the fixture and the expected cosine values.
 *
 * @package FWS
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Fast Woo Sell development helpers.
 */
final class FWS_Dev_Command {

	/**
	 * Post meta marking a product or order as seeded so `--reset` removes only ours.
	 */
	const SEED_META = '_fws_seed';

	/**
	 * Deterministic co-purchase fixture.
	 *
	 * Products are referenced by index inside the seeded catalogue (0-based).
	 * Every entry is "this exact set of products, in this many orders".
	 * With `bought_total(p)` = number of orders containing p and
	 * `support(a,b)` = orders containing both, the plugin computes
	 * `score = support / sqrt( total(a) * total(b) )`.
	 *
	 * Expected values (with ONLY the fixture orders, i.e. `--orders=0`):
	 *
	 *   total(0)=100+30+20 = 150   total(1)=100+30 = 130   total(2)=30+20 = 50   total(3)=20
	 *   support(0,1)=130  -> score = 130 / sqrt(150*130) = 0.9309
	 *   support(0,2)=50   -> score =  50 / sqrt(150*50)  = 0.5774
	 *   support(1,2)=30   -> score =  30 / sqrt(130*50)  = 0.3721
	 *   support(0,3)=20   -> score =  20 / sqrt(150*20)  = 0.3651
	 *   support(2,3)=20   -> score =  20 / sqrt(50*20)   = 0.6325
	 *   support(1,3)=0    -> no row
	 *
	 * Random filler orders (`--orders=N`) are drawn from products 10..199 only,
	 * so they never touch the fixture products and the numbers above stay exact.
	 */
	const PATTERN = array(
		array( 'products' => array( 0, 1 ), 'count' => 100 ),
		array( 'products' => array( 0, 1, 2 ), 'count' => 30 ),
		array( 'products' => array( 0, 2, 3 ), 'count' => 20 ),
	);

	/**
	 * First product index that random filler orders may use.
	 */
	const FILLER_FROM = 10;

	/**
	 * Seed demo products and paid orders.
	 *
	 * ## OPTIONS
	 *
	 * [--products=<n>]
	 * : Number of simple products to create. Minimum 10. Default 200.
	 *
	 * [--orders=<n>]
	 * : Number of random filler orders on top of the fixed pattern. Default 1500.
	 *
	 * [--days=<n>]
	 * : Spread order dates over the last N days. Default 120.
	 *
	 * [--reset]
	 * : Delete previously seeded products and orders first.
	 *
	 * [--seed=<int>]
	 * : RNG seed for reproducible filler orders. Default 42.
	 *
	 * ## EXAMPLES
	 *
	 *     wp fws seed
	 *     wp fws seed --products=50 --orders=0      # fixture only, for maths verification
	 *     wp fws seed --reset
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 */
	public function seed( $args, $assoc_args ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			WP_CLI::error( 'WooCommerce is not active.' );
		}

		$products = max( 10, (int) ( $assoc_args['products'] ?? 200 ) );
		$orders   = max( 0, (int) ( $assoc_args['orders'] ?? 1500 ) );
		$days     = max( 1, (int) ( $assoc_args['days'] ?? 120 ) );
		$rng_seed = (int) ( $assoc_args['seed'] ?? 42 );

		if ( isset( $assoc_args['reset'] ) ) {
			$this->reset();
			if ( 0 === $products && 0 === $orders ) {
				return;
			}
		}

		mt_srand( $rng_seed );
		wp_defer_term_counting( true );
		wp_suspend_cache_addition( true );

		WP_CLI::log( sprintf( 'Creating %d products…', $products ) );
		$ids = $this->create_products( $products );

		WP_CLI::log( 'Creating fixture orders (known co-purchase pattern)…' );
		$fixture = 0;
		foreach ( self::PATTERN as $entry ) {
			for ( $i = 0; $i < $entry['count']; $i++ ) {
				$line = array();
				foreach ( $entry['products'] as $idx ) {
					$line[] = $ids[ $idx ];
				}
				$this->create_order( $line, $days );
				++$fixture;
			}
		}
		WP_CLI::log( sprintf( '  %d fixture orders.', $fixture ) );

		if ( $orders > 0 ) {
			WP_CLI::log( sprintf( 'Creating %d random filler orders…', $orders ) );
			$progress = \WP_CLI\Utils\make_progress_bar( 'Orders', $orders );
			$pool     = array_slice( $ids, self::FILLER_FROM );
			for ( $i = 0; $i < $orders; $i++ ) {
				$n    = $this->weighted_basket_size();
				$pick = (array) array_rand( $pool, min( $n, count( $pool ) ) );
				$line = array();
				foreach ( $pick as $k ) {
					$line[] = $pool[ $k ];
				}
				$this->create_order( $line, $days );
				$progress->tick();
			}
			$progress->finish();
		}

		wp_suspend_cache_addition( false );
		wp_defer_term_counting( false );

		WP_CLI::log( 'Importing orders into WooCommerce Analytics lookup tables…' );
		$this->sync_analytics();

		WP_CLI::success( sprintf(
			'Seeded %d products and %d orders. Fixture product IDs: %s',
			count( $ids ),
			$fixture + $orders,
			implode( ', ', array_slice( $ids, 0, 4 ) )
		) );
		WP_CLI::log( 'Next: wp fws affinity rebuild && wp fws affinity check' );
	}

	/**
	 * Affinity maintenance.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : rebuild | check | reset
	 *
	 * @param array $args Positional args.
	 */
	public function affinity( $args ) {
		$action = $args[0] ?? 'rebuild';

		switch ( $action ) {
			case 'rebuild':
				$builder = new \FWS\Recommendation\AffinityBuilder();
				$builder->reset();
				$runs = 0;
				do {
					++$runs;
					$builder->run();
				} while ( \FWS\Recommendation\AffinityBuilder::in_progress() && $runs < 50 );
				WP_CLI::success( sprintf( 'Affinity rebuilt in %d run(s). Rows: %d', $runs, $this->affinity_rows() ) );
				break;

			case 'reset':
				( new \FWS\Recommendation\AffinityBuilder() )->reset();
				WP_CLI::success( 'Cursor cleared.' );
				break;

			case 'check':
				$this->check_fixture();
				break;

			default:
				WP_CLI::error( "Unknown action '{$action}'. Use rebuild | check | reset." );
		}
	}

	/**
	 * Print plugin state: tables, row counts, state options.
	 */
	public function status() {
		global $wpdb;

		$rows = array();
		foreach ( \FWS\Install\Schema::names() as $table ) {
			$name   = $wpdb->prefix . $table;
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$count  = $exists ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$name}`" ) : '—'; // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
			$rows[] = array( 'table' => $name, 'exists' => $exists ? 'yes' : 'NO', 'rows' => $count );
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'table', 'exists', 'rows' ) );

		$state = array();
		foreach ( \FWS\Core\State::option_names() as $opt ) {
			$state[] = array( 'option' => $opt, 'value' => wp_json_encode( get_option( $opt ) ) );
		}
		\WP_CLI\Utils\format_items( 'table', $state, array( 'option', 'value' ) );
	}

	// ------------------------------------------------------------------ private

	/**
	 * Compare the affinity table against the fixture's expected cosine values.
	 */
	private function check_fixture() {
		global $wpdb;

		$ids = get_posts( array(
			'post_type'      => 'product',
			'posts_per_page' => 4,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'meta_key'       => self::SEED_META, // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'     => 'product', // phpcs:ignore WordPress.DB.SlowDBQuery
		) );
		if ( count( $ids ) < 4 ) {
			WP_CLI::error( 'Fixture products not found. Run `wp fws seed` first.' );
		}

		// Expected totals/supports derived from PATTERN.
		$total   = array_fill( 0, 4, 0 );
		$support = array();
		foreach ( self::PATTERN as $entry ) {
			foreach ( $entry['products'] as $a ) {
				$total[ $a ] += $entry['count'];
				foreach ( $entry['products'] as $b ) {
					if ( $a !== $b ) {
						$support[ "$a-$b" ] = ( $support[ "$a-$b" ] ?? 0 ) + $entry['count'];
					}
				}
			}
		}

		$table = $wpdb->prefix . \FWS\Install\Schema::AFFINITY;
		$out   = array();
		$fail  = 0;

		for ( $a = 0; $a < 4; $a++ ) {
			$db_total = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"SELECT support FROM `{$table}` WHERE product_id = %d AND related_id = 0 AND kind = 'bought_total'", // phpcs:ignore WordPress.DB.PreparedSQL
				$ids[ $a ]
			) );
			$ok    = $db_total === $total[ $a ];
			$fail += $ok ? 0 : 1;
			$out[] = array( 'pair' => "total($a)", 'expected' => $total[ $a ], 'db' => $db_total, 'score_expected' => '', 'score_db' => '', 'ok' => $ok ? '✓' : '✗' );
		}

		foreach ( $support as $key => $sup ) {
			list( $a, $b ) = array_map( 'intval', explode( '-', $key ) );
			if ( $a > $b ) {
				continue; // report each unordered pair once; table stores both directions.
			}
			$expected_score = round( $sup / sqrt( $total[ $a ] * $total[ $b ] ), 4 );
			$row            = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"SELECT support, score FROM `{$table}` WHERE product_id = %d AND related_id = %d AND kind = 'bought'", // phpcs:ignore WordPress.DB.PreparedSQL
				$ids[ $a ],
				$ids[ $b ]
			) );
			$db_sup   = $row ? (int) $row->support : 0;
			$db_score = $row ? round( (float) $row->score, 4 ) : 0.0;
			$ok       = ( $db_sup === $sup ) && ( abs( $db_score - $expected_score ) < 0.0002 );
			$fail    += $ok ? 0 : 1;
			$out[]    = array( 'pair' => "$a-$b", 'expected' => $sup, 'db' => $db_sup, 'score_expected' => $expected_score, 'score_db' => $db_score, 'ok' => $ok ? '✓' : '✗' );
		}

		\WP_CLI\Utils\format_items( 'table', $out, array( 'pair', 'expected', 'db', 'score_expected', 'score_db', 'ok' ) );

		if ( $fail > 0 ) {
			WP_CLI::warning( "{$fail} mismatch(es). If filler orders were seeded (--orders>0) totals are still exact because filler never uses fixture products; a mismatch means a real engine problem or a stale affinity_window_days smaller than --days." );
			WP_CLI::halt( 1 );
		}
		WP_CLI::success( 'Affinity table matches the hand-computed fixture.' );
	}

	/**
	 * Create simple, published, in-stock products across 8 categories.
	 *
	 * @param int $n How many.
	 * @return int[] Product IDs in creation order.
	 */
	private function create_products( $n ) {
		$cats = array();
		foreach ( array( 'موبایل', 'لوازم جانبی', 'لپ‌تاپ', 'صوتی', 'خانه هوشمند', 'گیمینگ', 'شبکه', 'ذخیره‌سازی' ) as $name ) {
			$term = term_exists( $name, 'product_cat' );
			if ( ! $term ) {
				$term = wp_insert_term( $name, 'product_cat' );
			}
			$cats[] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
		}

		$ids      = array();
		$progress = \WP_CLI\Utils\make_progress_bar( 'Products', $n );
		for ( $i = 0; $i < $n; $i++ ) {
			$p = new WC_Product_Simple();
			$p->set_name( sprintf( 'محصول نمونه %03d', $i ) );
			$p->set_slug( 'fws-demo-' . $i );
			$p->set_status( 'publish' );
			$p->set_catalog_visibility( 'visible' );
			$p->set_regular_price( (string) ( mt_rand( 5, 400 ) * 10000 ) );
			$p->set_manage_stock( true );
			$p->set_stock_quantity( mt_rand( 5, 80 ) );
			$p->set_stock_status( 'instock' );
			$p->set_category_ids( array( $cats[ $i % count( $cats ) ] ) );
			$p->update_meta_data( self::SEED_META, 'product' );
			$ids[] = $p->save();
			$progress->tick();
		}
		$progress->finish();
		return $ids;
	}

	/**
	 * Create one paid order with the given product IDs, dated randomly within $days.
	 *
	 * @param int[] $product_ids Lines.
	 * @param int   $days        Date spread.
	 */
	private function create_order( array $product_ids, $days ) {
		$order = wc_create_order( array( 'status' => 'completed' ) );
		foreach ( $product_ids as $pid ) {
			$order->add_product( wc_get_product( $pid ), mt_rand( 1, 2 ) );
		}
		$order->set_billing_first_name( 'مشتری' );
		$order->set_billing_last_name( (string) mt_rand( 1000, 9999 ) );
		$order->set_billing_email( 'buyer' . mt_rand( 1, 400 ) . '@example.test' );
		$order->set_billing_country( 'IR' );
		$order->set_payment_method( 'bacs' );
		$order->set_created_via( 'fws-seed' );

		$ts = time() - mt_rand( 0, $days * DAY_IN_SECONDS );
		$order->set_date_created( $ts );
		$order->set_date_paid( $ts );
		$order->set_date_completed( $ts );
		$order->update_meta_data( self::SEED_META, 'order' );
		$order->calculate_totals();
		$order->save();
	}

	/**
	 * Realistic basket size: mostly 1–2 items, occasionally more.
	 *
	 * @return int
	 */
	private function weighted_basket_size() {
		$r = mt_rand( 1, 100 );
		if ( $r <= 45 ) {
			return 1;
		}
		if ( $r <= 80 ) {
			return 2;
		}
		if ( $r <= 95 ) {
			return 3;
		}
		return mt_rand( 4, 6 );
	}

	/**
	 * Push seeded orders into wc_order_stats / wc_order_product_lookup synchronously.
	 * The affinity builder reads those tables, and the async import may not have run yet.
	 */
	private function sync_analytics() {
		if ( ! class_exists( '\Automattic\WooCommerce\Admin\API\Reports\Cache' ) ) {
			WP_CLI::warning( 'WooCommerce Admin not available; analytics lookup tables were not synced.' );
			return;
		}
		$orders = wc_get_orders( array(
			'limit'      => -1,
			'return'     => 'ids',
			'meta_key'   => self::SEED_META, // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value' => 'order', // phpcs:ignore WordPress.DB.SlowDBQuery
		) );
		$progress = \WP_CLI\Utils\make_progress_bar( 'Analytics sync', count( $orders ) );
		foreach ( $orders as $id ) {
			\Automattic\WooCommerce\Admin\API\Reports\Orders\Stats\DataStore::sync_order( $id );
			\Automattic\WooCommerce\Admin\API\Reports\Products\DataStore::sync_order_products( $id );
			$progress->tick();
		}
		$progress->finish();
		\Automattic\WooCommerce\Admin\API\Reports\Cache::invalidate();
	}

	/**
	 * Remove everything we seeded.
	 */
	private function reset() {
		WP_CLI::log( 'Removing previously seeded data…' );
		$orders = wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'meta_key' => self::SEED_META, 'meta_value' => 'order' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		foreach ( $orders as $id ) {
			$o = wc_get_order( $id );
			if ( $o ) {
				$o->delete( true );
			}
		}
		$products = get_posts( array( 'post_type' => 'product', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => self::SEED_META, 'meta_value' => 'product' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		foreach ( $products as $id ) {
			wp_delete_post( $id, true );
		}
		( new \FWS\Recommendation\AffinityBuilder() )->reset();
		WP_CLI::log( sprintf( '  removed %d orders, %d products.', count( $orders ), count( $products ) ) );
	}

	/**
	 * @return int
	 */
	private function affinity_rows() {
		global $wpdb;
		$table = $wpdb->prefix . \FWS\Install\Schema::AFFINITY;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
	}
}

WP_CLI::add_command( 'fws', 'FWS_Dev_Command' );
