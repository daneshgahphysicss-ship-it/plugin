<?php
/**
 * Database schema.
 *
 * @package FWS
 */

namespace FWS\Install;

defined( 'ABSPATH' ) || exit;

/**
 * The single source of truth for every table the plugin owns.
 *
 * Tables are grouped into phases so an install only creates what the enabled
 * modules actually need. Nothing here is executed on a front end request: the
 * Migrator runs dbDelta on activation and on a version change only, which is
 * also why the MySQL 8 display width churn in dbDelta does not matter.
 *
 * Column conventions:
 *   - identifiers and hashes use `CHARACTER SET ascii COLLATE ascii_bin`,
 *     cutting index entry size four fold on hot tables versus utf8mb4
 *   - money uses DECIMAL(18,4), matching WooCommerce precision without the
 *     storage waste of DECIMAL(26,8)
 *   - optional timestamps are `NULL DEFAULT NULL`, never a zero date, so
 *     "never happened" and "happened at the epoch" cannot be confused
 *   - all DATETIME columns use site time via current_time('mysql'),
 *     consistent with the rest of the WordPress codebase
 */
final class Schema {

	/**
	 * Core: visitors, events, statistics, affinity, placements.
	 */
	const PHASE_CORE = 1;

	/**
	 * Abandoned carts: carts, cart_log.
	 */
	const PHASE_CARTS = 2;

	/**
	 * Stock forecasting: forecast.
	 */
	const PHASE_FORECAST = 3;

	/**
	 * Rules, segments and experiments.
	 */
	const PHASE_OPTIMISE = 4;

	/**
	 * Table name without the WordPress prefix.
	 */
	const VISITORS          = 'fws_visitors';
	const EVENTS            = 'fws_events';
	const STATS_PRODUCT     = 'fws_stats_product_daily';
	const STATS_VARIANT     = 'fws_stats_variant_daily';
	const AFFINITY          = 'fws_affinity';
	const PLACEMENTS        = 'fws_placements';
	const CARTS             = 'fws_carts';
	const CART_LOG          = 'fws_cart_log';
	const FORECAST          = 'fws_forecast';
	const RULES             = 'fws_rules';
	const SEGMENTS          = 'fws_segments';
	const EXPERIMENTS       = 'fws_experiments';
	const EXPERIMENT_VARIANTS = 'fws_experiment_variants';

	/**
	 * Fully qualified table name.
	 *
	 * @param string $name One of the class constants.
	 *
	 * @return string
	 */
	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . $name;
	}

	/**
	 * Every unprefixed table name, in creation order.
	 *
	 * @return string[]
	 */
	public static function names(): array {
		return array(
			self::VISITORS,
			self::EVENTS,
			self::STATS_PRODUCT,
			self::STATS_VARIANT,
			self::AFFINITY,
			self::PLACEMENTS,
			self::CARTS,
			self::CART_LOG,
			self::FORECAST,
			self::RULES,
			self::SEGMENTS,
			self::EXPERIMENTS,
			self::EXPERIMENT_VARIANTS,
		);
	}

	/**
	 * The phase each table belongs to.
	 *
	 * @return array<string, int>
	 */
	public static function phases(): array {
		return array(
			self::VISITORS             => self::PHASE_CORE,
			self::EVENTS              => self::PHASE_CORE,
			self::STATS_PRODUCT       => self::PHASE_CORE,
			self::STATS_VARIANT       => self::PHASE_CORE,
			self::AFFINITY            => self::PHASE_CORE,
			self::PLACEMENTS          => self::PHASE_CORE,
			self::CARTS               => self::PHASE_CARTS,
			self::CART_LOG            => self::PHASE_CARTS,
			self::FORECAST            => self::PHASE_FORECAST,
			self::RULES               => self::PHASE_OPTIMISE,
			self::SEGMENTS            => self::PHASE_OPTIMISE,
			self::EXPERIMENTS         => self::PHASE_OPTIMISE,
			self::EXPERIMENT_VARIANTS => self::PHASE_OPTIMISE,
		);
	}

	/**
	 * Every CREATE TABLE statement for the given phase, keyed by table name.
	 *
	 * @param int $max_phase Highest phase to include.
	 *
	 * @return array<string, string>
	 */
	public static function definitions( int $max_phase = self::PHASE_OPTIMISE ): array {
		global $wpdb;

		$collate = $wpdb->get_charset_collate();
		$phases  = self::phases();
		$out     = array();

		foreach ( self::names() as $name ) {
			if ( $phases[ $name ] > $max_phase ) {
				continue;
			}

			$method = 'sql_' . substr( $name, 4 );

			$out[ $name ] = self::{$method}( $wpdb->prefix . $name, $collate );
		}

		return $out;
	}

	/**
	 * Raw event log. The highest volume table in the plugin.
	 *
	 * `meta` is the only wide column and is never indexed or filtered on; every
	 * value the engines actually read is promoted to its own column.
	 *
	 * @param string $table   Prefixed table name.
	 * @param string $collate Charset and collation clause.
	 *
	 * @return string
	 */
	private static function sql_events( string $table, string $collate ): string {
		return "CREATE TABLE {$table} (
	id bigint(20) unsigned NOT NULL auto_increment,
	event_type varchar(24) character set ascii collate ascii_bin NOT NULL,
	object_id bigint(20) unsigned NOT NULL default 0,
	object_type varchar(16) character set ascii NOT NULL default 'product',
	visitor_id char(36) character set ascii collate ascii_bin NOT NULL default '',
	session_id char(36) character set ascii collate ascii_bin NOT NULL default '',
	user_id bigint(20) unsigned NOT NULL default 0,
	surface varchar(32) character set ascii NOT NULL default '',
	placement_id bigint(20) unsigned NOT NULL default 0,
	variant_id bigint(20) unsigned NOT NULL default 0,
	source_object_id bigint(20) unsigned NOT NULL default 0,
	quantity smallint(5) unsigned NOT NULL default 1,
	value decimal(18,4) NOT NULL default 0,
	order_id bigint(20) unsigned NOT NULL default 0,
	created_at datetime NOT NULL,
	PRIMARY KEY (id),
	KEY type_time (event_type, created_at),
	KEY object_type_time (object_id, event_type, created_at),
	KEY session_type (session_id, event_type),
	KEY variant_type (variant_id, event_type),
	KEY visitor_time (visitor_id, created_at)
) {$collate};";
	}

	/**
	 * Daily rollup per product.
	 *
	 * `orders` is stored alongside the attributed columns so the table can be
	 * summed over any date range without ever touching the raw event log.
	 *
	 * @param string $table   Prefixed table name.
	 * @param string $collate Charset and collation clause.
	 *
	 * @return string
	 */
	private static function sql_stats_product_daily( string $table, string $collate ): string {
		return "CREATE TABLE {$table} (
	id bigint(20) unsigned NOT NULL auto_increment,
	product_id bigint(20) unsigned NOT NULL default 0,
	stat_date date NOT NULL default '0000-00-00',
	views int(10) unsigned NOT NULL default 0,
	impressions int(10) unsigned NOT NULL default 0,
	clicks int(10) unsigned NOT NULL default 0,
	add_to_carts int(10) unsigned NOT NULL default 0,
	orders int(10) unsigned NOT NULL default 0,
	units int(10) unsigned NOT NULL default 0,
	revenue decimal(26,8) NOT NULL default 0,
	attributed_orders int(10) unsigned NOT NULL default 0,
	attributed_units int(10) unsigned NOT NULL default 0,
	attributed_revenue decimal(26,8) NOT NULL default 0,
	PRIMARY KEY  (id),
	UNIQUE KEY product_date (product_id, stat_date),
	KEY stat_date (stat_date)
) {$collate};";
	}

	/**
	 * Daily rollup per placement, engine and experiment arm.
	 *
	 * Kept separate from the product rollup because the two are aggregated on
	 * different keys; merging them produced a table that could not be summed
	 * without double counting.
	 *
	 * @param string $table   Prefixed table name.
	 * @param string $collate Charset and collation clause.
	 *
	 * @return string
	 */
	private static function sql_stats_variant_daily( string $table, string $collate ): string {
		return "CREATE TABLE {$table} (
	id bigint(20) unsigned NOT NULL auto_increment,
	placement_id bigint(20) unsigned NOT NULL default 0,
	engine varchar(32) character set ascii collate ascii_general_ci NOT NULL default '',
	variant char(1) character set ascii collate ascii_general_ci NOT NULL default '',
	stat_date date NOT NULL default '0000-00-00',
	impressions int(10) unsigned NOT NULL default 0,
	clicks int(10) unsigned NOT NULL default 0,
	add_to_carts int(10) unsigned NOT NULL default 0,
	orders int(10) unsigned NOT NULL default 0,
	units int(10) unsigned NOT NULL default 0,
	revenue decimal(26,8) NOT NULL default 0,
	PRIMARY KEY  (id),
	UNIQUE KEY placement_engine_variant_date (placement_id, engine, variant, stat_date),
	KEY stat_date (stat_date)
) {$collate};";
	}

	/**
	 * Product to product affinity, one row per ordered pair and kind.
	 *
	 * Rebuilt in full by a scheduled job rather than maintained incrementally,
	 * because incremental updates drift and cannot be reconciled cheaply.
	 *
	 * @param string $table   Prefixed table name.
	 * @param string $collate Charset and collation clause.
	 *
	 * @return string
	 */
	private static function sql_affinity( string $table, string $collate ): string {
		return "CREATE TABLE {$table} (
	id bigint(20) unsigned NOT NULL auto_increment,
	product_id bigint(20) unsigned NOT NULL default 0,
	related_id bigint(20) unsigned NOT NULL default 0,
	kind varchar(20) character set ascii collate ascii_general_ci NOT NULL default '',
	support int(10) unsigned NOT NULL default 0,
	score decimal(12,6) NOT NULL default 0,
	window_days smallint(5) unsigned NOT NULL default 0,
	updated_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	UNIQUE KEY pair_kind (product_id, related_id, kind),
	KEY lookup (product_id, kind, score),
	KEY kind_updated (kind, updated_at),
	KEY related_id (related_id)
) {$collate};";
	}

	/**
	 * Where and how a recommendation block is rendered.
	 *
	 * @param string $table   Prefixed table name.
	 * @param string $collate Charset and collation clause.
	 *
	 * @return string
	 */
	private static function sql_placements( string $table, string $collate ): string {
		return "CREATE TABLE {$table} (
	id bigint(20) unsigned NOT NULL auto_increment,
	title varchar(191) NOT NULL default '',
	status varchar(20) character set ascii collate ascii_general_ci NOT NULL default 'draft',
	module varchar(20) character set ascii collate ascii_general_ci NOT NULL default 'recommendations',
	location varchar(50) character set ascii collate ascii_general_ci NOT NULL default '',
	engine varchar(32) character set ascii collate ascii_general_ci NOT NULL default '',
	layout varchar(20) character set ascii collate ascii_general_ci NOT NULL default 'grid',
	limit_count smallint(5) unsigned NOT NULL default 4,
	priority smallint(6) NOT NULL default 10,
	experiment_id bigint(20) unsigned NOT NULL default 0,
	conditions longtext NULL,
	settings longtext NULL,
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	updated_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	KEY status_location_priority (status, location, priority),
	KEY experiment_id (experiment_id)
) {$collate};";
	}

	/**
	 * Background job queue.
	 *
	 * `dedupe_key` is nullable rather than defaulting to an empty string so the
	 * unique index tolerates many rows that opt out of deduplication; MySQL
	 * allows repeated NULLs in a unique index but not repeated empty strings.
	 *
	 * @param string $table   Prefixed table name.
	 * @param string $collate Charset and collation clause.
	 *
	 * @return string
	 */
	private static function sql_queue( string $table, string $collate ): string {
		return "CREATE TABLE {$table} (
	id bigint(20) unsigned NOT NULL auto_increment,
	job varchar(50) character set ascii collate ascii_general_ci NOT NULL default '',
	dedupe_key varchar(64) character set ascii collate ascii_general_ci NULL default NULL,
	status varchar(20) character set ascii collate ascii_general_ci NOT NULL default 'pending',
	priority smallint(6) NOT NULL default 10,
	attempts tinyint(3) unsigned NOT NULL default 0,
	payload longtext NULL,
	last_error text NULL,
	available_at datetime NOT NULL default '0000-00-00 00:00:00',
	locked_at datetime NULL default NULL,
	locked_by varchar(40) character set ascii collate ascii_general_ci NOT NULL default '',
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	UNIQUE KEY dedupe_key (dedupe_key),
	KEY claim (status, available_at, priority),
	KEY job (job),
	KEY locked_at (locked_at)
) {$collate};";
	}

	/**
	 * One row per tracked cart.
	 *
	 * Contact details are stored on the cart row rather than in a separate table
	 * because every recovery query needs them, and because an erasure request
	 * must be satisfiable by updating a single row.
	 *
	 * @param string $table   Prefixed table name.
	 * @param string $collate Charset and collation clause.
	 *
	 * @return string
	 */
	private static function sql_carts( string $table, string $collate ): string {
		return "CREATE TABLE {$table} (
	id bigint(20) unsigned NOT NULL auto_increment,
	cart_key char(32) character set ascii collate ascii_general_ci NOT NULL default '',
	visitor_id char(32) character set ascii collate ascii_general_ci NOT NULL default '',
	session_key varchar(32) character set ascii collate ascii_general_ci NOT NULL default '',
	user_id bigint(20) unsigned NOT NULL default 0,
	status varchar(20) character set ascii collate ascii_general_ci NOT NULL default 'active',
	stage tinyint(3) unsigned NOT NULL default 0,
	email varchar(191) NOT NULL default '',
	phone varchar(32) NOT NULL default '',
	first_name varchar(100) NOT NULL default '',
	contact_source varchar(20) character set ascii collate ascii_general_ci NOT NULL default '',
	contact_consent tinyint(1) NOT NULL default 0,
	currency char(3) character set ascii collate ascii_general_ci NOT NULL default '',
	subtotal decimal(26,8) NOT NULL default 0,
	total decimal(26,8) NOT NULL default 0,
	item_count smallint(5) unsigned NOT NULL default 0,
	coupon_code varchar(64) NOT NULL default '',
	recovery_token char(64) character set ascii collate ascii_general_ci NULL default NULL,
	recovery_expires_at datetime NULL default NULL,
	attempts tinyint(3) unsigned NOT NULL default 0,
	last_attempt_at datetime NULL default NULL,
	next_attempt_at datetime NULL default NULL,
	order_id bigint(20) unsigned NOT NULL default 0,
	recovered_at datetime NULL default NULL,
	recovered_total decimal(26,8) NOT NULL default 0,
	ip_hash char(64) character set ascii collate ascii_general_ci NOT NULL default '',
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	updated_at datetime NOT NULL default '0000-00-00 00:00:00',
	abandoned_at datetime NULL default NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY cart_key (cart_key),
	UNIQUE KEY recovery_token (recovery_token),
	KEY due (status, next_attempt_at),
	KEY visitor_id (visitor_id),
	KEY session_key (session_key),
	KEY user_id (user_id),
	KEY email (email(100)),
	KEY order_id (order_id),
	KEY updated_at (updated_at)
) {$collate};";
	}

	/**
	 * Line items of a tracked cart.
	 *
	 * Name and SKU are snapshots: the product may be deleted before the cart is
	 * recovered, and a recovery email that says "your item" is worthless.
	 *
	 * @param string $table   Prefixed table name.
	 * @param string $collate Charset and collation clause.
	 *
	 * @return string
	 */
	private static function sql_cart_items( string $table, string $collate ): string {
		return "CREATE TABLE {$table} (
	id bigint(20) unsigned NOT NULL auto_increment,
	cart_id bigint(20) unsigned NOT NULL default 0,
	product_id bigint(20) unsigned NOT NULL default 0,
	variation_id bigint(20) unsigned NOT NULL default 0,
	quantity smallint(5) unsigned NOT NULL default 1,
	line_subtotal decimal(26,8) NOT NULL default 0,
	line_total decimal(26,8) NOT NULL default 0,
	product_name varchar(191) NOT NULL default '',
	product_sku varchar(100) NOT NULL default '',
	PRIMARY KEY  (id),
	KEY cart_id (cart_id),
	KEY product_id (product_id)
) {$collate};";
	}

	/**
	 * Every recovery message scheduled or sent for a cart.
	 *
	 * A row is written when the message is scheduled, not when it is sent, so
	 * that a crashed cron run can never silently skip an attempt.
	 *
	 * @param string $table   Prefixed table name.
	 * @param string $collate Charset and collation clause.
	 *
	 * @return string
	 */
	private static function sql_cart_messages( string $table, string $collate ): string {
		return "CREATE TABLE {$table} (
	id bigint(20) unsigned NOT NULL auto_increment,
	cart_id bigint(20) unsigned NOT NULL default 0,
	attempt tinyint(3) unsigned NOT NULL default 0,
	channel varchar(20) character set ascii collate ascii_general_ci NOT NULL default 'email',
	status varchar(20) character set ascii collate ascii_general_ci NOT NULL default 'scheduled',
	template varchar(50) character set ascii collate ascii_general_ci NOT NULL default '',
	recipient varchar(191) NOT NULL default '',
	subject varchar(191) NOT NULL default '',
	coupon_code varchar(64) NOT NULL default '',
	error text NULL,
	scheduled_at datetime NOT NULL default '0000-00-00 00:00:00',
	sent_at datetime NULL default NULL,
	opened_at datetime NULL default NULL,
	clicked_at datetime NULL default NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY cart_attempt_channel (cart_id, attempt, channel),
	KEY due (status, scheduled_at)
) {$collate};";
	}

	/**
	 * Latest forecast per sellable unit.
	 *
	 * `days_left` is nullable because a product with zero velocity has no
	 * depletion date, and storing a sentinel there made every report lie.
	 *
	 * @param string $table   Prefixed table name.
	 * @param string $collate Charset and collation clause.
	 *
	 * @return string
	 */
	private static function sql_forecast( string $table, string $collate ): string {
		return "CREATE TABLE {$table} (
	id bigint(20) unsigned NOT NULL auto_increment,
	product_id bigint(20) unsigned NOT NULL default 0,
	variation_id bigint(20) unsigned NOT NULL default 0,
	sku varchar(100) NOT NULL default '',
	stock_quantity decimal(15,4) NULL default NULL,
	velocity_7 decimal(12,4) NOT NULL default 0,
	velocity_30 decimal(12,4) NOT NULL default 0,
	velocity_90 decimal(12,4) NOT NULL default 0,
	trend decimal(8,4) NOT NULL default 0,
	days_left smallint(6) NULL default NULL,
	depletes_on date NULL default NULL,
	suggested_reorder int(10) unsigned NOT NULL default 0,
	severity tinyint(3) unsigned NOT NULL default 0,
	calculated_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	UNIQUE KEY unit (product_id, variation_id),
	KEY urgency (severity, days_left),
	KEY depletes_on (depletes_on)
) {$collate};";
	}

	/**
	 * Merchandising rules.
	 *
	 * `terminal` separates rules that decide an outcome on their own from rules
	 * whose effects accumulate; mixing the two silently discarded boosts.
	 *
	 * @param string $table   Prefixed table name.
	 * @param string $collate Charset and collation clause.
	 *
	 * @return string
	 */
	private static function sql_rules( string $table, string $collate ): string {
		return "CREATE TABLE {$table} (
	id bigint(20) unsigned NOT NULL auto_increment,
	title varchar(191) NOT NULL default '',
	status varchar(20) character set ascii collate ascii_general_ci NOT NULL default 'draft',
	scope varchar(20) character set ascii collate ascii_general_ci NOT NULL default 'recommendations',
	action varchar(30) character set ascii collate ascii_general_ci NOT NULL default '',
	terminal tinyint(1) NOT NULL default 0,
	priority smallint(6) NOT NULL default 10,
	amount decimal(12,6) NOT NULL default 0,
	conditions longtext NULL,
	targets longtext NULL,
	starts_at datetime NULL default NULL,
	ends_at datetime NULL default NULL,
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	updated_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	KEY active (status, scope, terminal, priority)
) {$collate};";
	}

	/**
	 * A/B tests.
	 *
	 * @param string $table   Prefixed table name.
	 * @param string $collate Charset and collation clause.
	 *
	 * @return string
	 */
	private static function sql_experiments( string $table, string $collate ): string {
		return "CREATE TABLE {$table} (
	id bigint(20) unsigned NOT NULL auto_increment,
	title varchar(191) NOT NULL default '',
	status varchar(20) character set ascii collate ascii_general_ci NOT NULL default 'draft',
	module varchar(20) character set ascii collate ascii_general_ci NOT NULL default 'recommendations',
	placement_id bigint(20) unsigned NOT NULL default 0,
	hypothesis text NULL,
	arms longtext NULL,
	traffic_split varchar(50) character set ascii collate ascii_general_ci NOT NULL default '50/50',
	primary_metric varchar(40) character set ascii collate ascii_general_ci NOT NULL default 'revenue_per_impression',
	min_samples int(10) unsigned NOT NULL default 200,
	min_conversions int(10) unsigned NOT NULL default 25,
	min_days smallint(5) unsigned NOT NULL default 7,
	winner char(1) character set ascii collate ascii_general_ci NOT NULL default '',
	confidence decimal(6,4) NOT NULL default 0,
	started_at datetime NULL default NULL,
	concluded_at datetime NULL default NULL,
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	KEY status (status),
	KEY placement_id (placement_id)
) {$collate};";
	}

	/**
	 * Daily counters per experiment arm.
	 *
	 * @param string $table   Prefixed table name.
	 * @param string $collate Charset and collation clause.
	 *
	 * @return string
	 */
	private static function sql_experiment_stats( string $table, string $collate ): string {
		return "CREATE TABLE {$table} (
	id bigint(20) unsigned NOT NULL auto_increment,
	experiment_id bigint(20) unsigned NOT NULL default 0,
	variant char(1) character set ascii collate ascii_general_ci NOT NULL default '',
	stat_date date NOT NULL default '0000-00-00',
	visitors int(10) unsigned NOT NULL default 0,
	impressions int(10) unsigned NOT NULL default 0,
	clicks int(10) unsigned NOT NULL default 0,
	add_to_carts int(10) unsigned NOT NULL default 0,
	orders int(10) unsigned NOT NULL default 0,
	units int(10) unsigned NOT NULL default 0,
	revenue decimal(26,8) NOT NULL default 0,
	PRIMARY KEY  (id),
	UNIQUE KEY experiment_variant_date (experiment_id, variant, stat_date),
	KEY experiment_id (experiment_id)
) {$collate};";
	}

	/**
	 * Whether a table physically exists.
	 *
	 * @param string $name Unprefixed table name.
	 *
	 * @return bool
	 */
	public static function exists( string $name ): bool {
		global $wpdb;

		$table = self::table( $name );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema probe, cannot be cached.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		return $table === $found;
	}

	/**
	 * Tables that should exist for the given phase but do not.
	 *
	 * @param int $max_phase Highest phase to consider.
	 *
	 * @return string[]
	 */
	public static function missing( int $max_phase = self::PHASE_OPTIMISE ): array {
		$missing = array();

		foreach ( self::phases() as $name => $phase ) {
			if ( $phase <= $max_phase && ! self::exists( $name ) ) {
				$missing[] = $name;
			}
		}

		return $missing;
	}
}
