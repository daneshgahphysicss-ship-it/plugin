<?php
/**
 * Schema creation and upgrades.
 *
 * @package FWS
 */

namespace FWS\Install;

use FWS\Core\State;
use FWS\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the tables and moves them forward between versions.
 *
 * dbDelta runs only when the stored schema version differs from the shipped
 * one, never on an ordinary request, and never on the front end. A lock guards
 * against two concurrent admin requests both trying to upgrade.
 */
final class Migrator {

	/**
	 * Transient guarding a running upgrade.
	 */
	const LOCK = 'fws_migration_lock';

	/**
	 * How long the lock survives a fatal error mid upgrade, in seconds.
	 */
	const LOCK_TTL = 300;

	/**
	 * Bring the database up to the shipped schema version.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		$target    = defined( 'FWS_DB_VERSION' ) ? (string) FWS_DB_VERSION : '1.0.0';
		$installed = (string) State::get( State::DB_VERSION, '' );

		if ( $installed === $target ) {
			return;
		}

		if ( ! self::acquire_lock() ) {
			return;
		}

		try {
			$errors = self::install_tables();

			if ( ! empty( $errors ) ) {
				Logger::error(
					'Schema upgrade reported errors.',
					array(
						'from'   => '' === $installed ? 'none' : $installed,
						'to'     => $target,
						'errors' => $errors,
					)
				);
			}

			if ( '' !== $installed ) {
				self::run_migrations( $installed, $target );
			}

			State::set( State::DB_VERSION, $target );

			Logger::info(
				'Schema is up to date.',
				array(
					'from' => '' === $installed ? 'none' : $installed,
					'to'   => $target,
				)
			);

			/**
			 * Fires after the schema reaches the shipped version.
			 *
			 * @since 1.0.0
			 *
			 * @param string $target    Version now installed.
			 * @param string $installed Version that was installed before.
			 */
			do_action( 'fws/migrated', $target, $installed );
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Create or alter every table for the given phase.
	 *
	 * @param int $max_phase Highest schema phase to create.
	 *
	 * @return string[] Database errors, empty on success.
	 */
	public static function install_tables( int $max_phase = Schema::PHASE_OPTIMISE ): array {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$suppress = $wpdb->suppress_errors( true );
		$errors   = array();

		foreach ( Schema::definitions( $max_phase ) as $name => $sql ) {
			$wpdb->last_error = '';

			dbDelta( $sql );

			if ( '' !== $wpdb->last_error ) {
				$errors[ $name ] = $wpdb->last_error;
			}
		}

		$wpdb->suppress_errors( $suppress );

		return $errors;
	}

	/**
	 * Version to upgrade routine.
	 *
	 * Routines run in ascending version order for every version greater than the
	 * installed one and less than or equal to the target. They must be safe to
	 * run twice: an interrupted upgrade will replay them.
	 *
	 * @return array<string, callable>
	 */
	public static function migrations(): array {
		return array();
	}

	/**
	 * Run every routine between two versions.
	 *
	 * @param string $from Installed version.
	 * @param string $to   Target version.
	 *
	 * @return void
	 */
	private static function run_migrations( string $from, string $to ): void {
		$migrations = self::migrations();

		uksort( $migrations, 'version_compare' );

		foreach ( $migrations as $version => $callback ) {
			if ( version_compare( (string) $version, $from, '<=' ) ) {
				continue;
			}

			if ( version_compare( (string) $version, $to, '>' ) ) {
				continue;
			}

			if ( ! is_callable( $callback ) ) {
				Logger::error( 'Migration is not callable.', array( 'version' => $version ) );

				continue;
			}

			$callback();

			Logger::info( 'Migration applied.', array( 'version' => $version ) );
		}
	}

	/**
	 * Drop every table the plugin owns.
	 *
	 * Only ever called from uninstall, and only when the shop explicitly opted
	 * in to data deletion.
	 *
	 * @return void
	 */
	public static function drop_tables(): void {
		global $wpdb;

		foreach ( Schema::names() as $name ) {
			$table = Schema::table( $name );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names cannot be parameterised; every value comes from a class constant.
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
	}

	/**
	 * Take the upgrade lock.
	 *
	 * @return bool
	 */
	private static function acquire_lock(): bool {
		if ( get_transient( self::LOCK ) ) {
			return false;
		}

		set_transient( self::LOCK, time(), self::LOCK_TTL );

		return true;
	}

	/**
	 * Release the upgrade lock.
	 *
	 * @return void
	 */
	private static function release_lock(): void {
		delete_transient( self::LOCK );
	}
}
