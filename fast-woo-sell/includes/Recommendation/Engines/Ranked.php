<?php
/**
 * Ranked list helper.
 *
 * @package FWS
 */

namespace FWS\Recommendation\Engines;

use FWS\Support\Sanitize;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a scored result set into a clean list of product ids.
 *
 * Shared by every engine so exclusion and deduplication behave identically no
 * matter which one produced the list. An engine that forgot to apply its own
 * exclusions would put the current product in its own related block, and that
 * bug is worth having only one place to fix.
 */
final class Ranked {

	/**
	 * Product ids from affinity rows, in order.
	 *
	 * @param array<int, array{product_id:int,score:float,support:int}> $rows    Scored rows.
	 * @param int[]                                                    $exclude Products to drop.
	 * @param int                                                      $limit   Largest number to return.
	 *
	 * @return int[]
	 */
	public static function ids( array $rows, array $exclude, int $limit ): array {
		$ids = array();

		foreach ( $rows as $row ) {
			if ( ! isset( $row['product_id'] ) ) {
				continue;
			}

			$ids[] = $row['product_id'];
		}

		return self::clean( $ids, $exclude, $limit );
	}

	/**
	 * Deduplicate, drop exclusions, cap length, preserve order.
	 *
	 * @param int[] $ids     Candidate products.
	 * @param int[] $exclude Products to drop.
	 * @param int   $limit   Largest number to return.
	 *
	 * @return int[]
	 */
	public static function clean( array $ids, array $exclude, int $limit ): array {
		$ids     = Sanitize::id_list( $ids, 500 );
		$exclude = Sanitize::id_list( $exclude, 500 );
		$limit   = Sanitize::clamp( $limit, 1, 200 );

		if ( array() !== $exclude ) {
			$ids = array_values( array_diff( $ids, $exclude ) );
		}

		return array_slice( $ids, 0, $limit );
	}
}
