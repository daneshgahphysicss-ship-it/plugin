<?php
/**
 * Custom capabilities.
 *
 * @package FWS
 */

namespace FWS\Install;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the plugin's own capabilities on the WordPress roles.
 *
 * Dedicated capabilities are used instead of leaning on `manage_woocommerce`
 * so that a shop can let staff read reports without also handing them the
 * customer contact details held for cart recovery.
 */
final class Capabilities {

	/**
	 * Read dashboards and reports.
	 */
	const VIEW_REPORTS = 'fws_view_reports';

	/**
	 * Change settings, placements, rules and experiments.
	 */
	const MANAGE_SETTINGS = 'fws_manage_settings';

	/**
	 * See and act on abandoned carts, which contain customer contact details.
	 */
	const MANAGE_CARTS = 'fws_manage_carts';

	/**
	 * Export data as CSV.
	 */
	const EXPORT_DATA = 'fws_export_data';

	/**
	 * Every capability the plugin owns.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::VIEW_REPORTS,
			self::MANAGE_SETTINGS,
			self::MANAGE_CARTS,
			self::EXPORT_DATA,
		);
	}

	/**
	 * Which roles receive which capabilities on install.
	 *
	 * @return array<string, string[]>
	 */
	public static function role_map(): array {
		$map = array(
			'administrator' => self::all(),
			'shop_manager'  => array(
				self::VIEW_REPORTS,
				self::MANAGE_SETTINGS,
				self::MANAGE_CARTS,
				self::EXPORT_DATA,
			),
		);

		/**
		 * Filter the role to capability map applied on install.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string, string[]> $map Role slug to capability list.
		 */
		return (array) apply_filters( 'fws/capabilities/roles', $map );
	}

	/**
	 * Grant the capabilities.
	 *
	 * @return void
	 */
	public static function install(): void {
		foreach ( self::role_map() as $role_name => $caps ) {
			$role = get_role( $role_name );

			if ( null === $role ) {
				continue;
			}

			foreach ( $caps as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}
	}

	/**
	 * Revoke the capabilities from every role.
	 *
	 * Iterates all roles rather than only the ones in the map, so capabilities
	 * a site granted by hand are cleaned up too.
	 *
	 * @return void
	 */
	public static function remove(): void {
		$roles = wp_roles();
		$caps  = self::all();

		foreach ( array_keys( $roles->roles ) as $role_name ) {
			$role = get_role( $role_name );

			if ( null === $role ) {
				continue;
			}

			foreach ( $caps as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}

	/**
	 * Whether the current user holds a capability.
	 *
	 * Administrators are allowed through on `manage_options` as a safety net for
	 * sites where the roles were reset after install.
	 *
	 * @param string $capability One of the class constants.
	 *
	 * @return bool
	 */
	public static function current_user_can( string $capability ): bool {
		return current_user_can( $capability ) || current_user_can( 'manage_options' );
	}
}
