<?php
/**
 * Class FWS_Admin_Analytics
 * داده‌ساز بخش تحلیل دیتابیس پنل مدیریت: نقشه درختی ارتباطات (Treemap) و جدول قوانین برتر
 *
 * نسخه ۲.۷: مصورسازی ارتباط «محصول مبدأ ➔ مکمل» بر اساس تکرار خرید همزمان؛
 * الگوریتم Squarified Treemap سمت سرور اجرا می‌شود (بدون وابستگی به کتابخانه خارجی).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FWS_Admin_Analytics {

	/**
	 * دریافت قوانین برتر جدول همبستگی به‌همراه نام محصولات و دسته‌بندی مبدأ
	 * @param int    $limit
	 * @param string $orderby co_occurrence | confidence_score
	 * @return array
	 */
	public static function get_rules( $limit = 20, $orderby = 'co_occurrence' ) {
		global $wpdb;
		$table = $wpdb->prefix . FWS_Database_Miner::TABLE_AFFINITY;

		$exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
		if ( ! $exists ) {
			return array();
		}

		$allowed_order = array( 'co_occurrence', 'confidence_score' );
		$orderby_sql   = in_array( $orderby, $allowed_order, true ) ? $orderby : 'co_occurrence';

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"
            SELECT source_product_id, recommended_product_id, co_occurrence, confidence_score, lift_score
            FROM {$table}
            ORDER BY {$orderby_sql} DESC, co_occurrence DESC
            LIMIT %d
        ",
				max( 1, (int) $limit )
			)
		);

		if ( empty( $rows ) ) {
			return array();
		}

		// کش نام محصولات با یک بار priming
		$pids = array();
		foreach ( $rows as $r ) {
			$pids[] = (int) $r->source_product_id;
			$pids[] = (int) $r->recommended_product_id;
		}
		$pids = array_unique( $pids );
		if ( ! empty( $pids ) && function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( $pids, false, false );
		}

		$category_map = self::get_source_category_map( array_unique( array_map( 'absint', array_column( $rows, 'source_product_id' ) ) ) );

		$items = array();
		foreach ( $rows as $r ) {
			$src = get_post( $r->source_product_id );
			$rec = get_post( $r->recommended_product_id );
			if ( ! $src || ! $rec || 'product' !== $src->post_type || 'product' !== $rec->post_type ) {
				continue; // محصول حذف‌شده در پاکسازی بعدی حذف خواهد شد
			}
			$source_cat = isset( $category_map[ (int) $r->source_product_id ] ) ? $category_map[ (int) $r->source_product_id ] : 'متفرقه';
			$items[]    = array(
				'source_id'  => (int) $r->source_product_id,
				'target_id'  => (int) $r->recommended_product_id,
				'source'     => $src->post_title,
				'target'     => $rec->post_title,
				'label'      => $src->post_title . ' ➔ ' . $rec->post_title,
				'category'   => $source_cat,
				'co'         => (int) $r->co_occurrence,
				'confidence' => (float) $r->confidence_score,
				'lift'       => (float) $r->lift_score,
			);
		}
		return $items;
	}

	/**
	 * داده آماده Treemap: اندازه هر کاشی = تکرار خرید همزمان (Frequency)
	 * @param int $limit
	 * @return array
	 */
	public static function get_treemap_items( $limit = 40 ) {
		$rules = self::get_rules( $limit, 'co_occurrence' );
		$items = array();
		foreach ( $rules as $r ) {
			$value   = max( 1, $r['co'] );
			$items[] = array(
				'value'      => $value,
				'label'      => $r['label'],
				'category'   => $r['category'],
				'co'         => $r['co'],
				'confidence' => $r['confidence'],
				'lift'       => $r['lift'],
			);
		}
		return $items;
	}

	/**
	 * نگاشت شناسه محصول مبدأ به نام دسته‌بندی (یک کوئری تجمیعی)
	 * @param array $ids
	 * @return array<int,string>
	 */
	private static function get_source_category_map( $ids ) {
		global $wpdb;
		$map = array();
		$ids = array_filter( array_map( 'absint', (array) $ids ) );
		if ( empty( $ids ) ) {
			return $map;
		}
		$in   = implode( ',', $ids );
		$rows = $wpdb->get_results(
			"
            SELECT tr.object_id, t.name
            FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
            INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
            WHERE tr.object_id IN ({$in})
        "
		);
		foreach ( $rows as $row ) {
			$oid = (int) $row->object_id;
			if ( ! isset( $map[ $oid ] ) ) {
				$map[ $oid ] = $row->name; // اولین دسته‌بندی ثبت‌شده
			}
		}
		return $map;
	}

	/**
	 * الگوریتم Squarified Treemap — خروجی: مستطیل‌های x,y,w,h در بوم مجازی
	 * @param array $items آیتم‌ها با کلید value
	 * @param int   $width  عرض بوم مجازی
	 * @param int   $height ارتفاع بوم مجازی
	 * @return array
	 */
	public static function layout_treemap( $items, $width = 1000, $height = 420 ) {
		$items = is_array( $items ) ? $items : array();
		if ( empty( $items ) || $width <= 0 || $height <= 0 ) {
			return array();
		}

		// نرمال‌سازی مقادیر به مساحت بوم
		$total = 0;
		$clean = array();
		foreach ( $items as $item ) {
			$v = (float) ( isset( $item['value'] ) ? $item['value'] : 0 );
			if ( $v <= 0 ) {
				continue;
			}
			$item['value'] = $v;
			$clean[]       = $item;
			$total        += $v;
		}
		if ( empty( $clean ) || $total <= 0 ) {
			return array();
		}
		usort(
			$clean,
			function ( $a, $b ) {
				return $b['value'] <=> $a['value'];
			}
		);

		$scale = ( $width * $height ) / $total;
		foreach ( $clean as &$item ) {
			$item['_area'] = $item['value'] * $scale;
		}
		unset( $item );

		$rects = array();
		$x     = 0.0;
		$y     = 0.0;
		$w     = (float) $width;
		$h     = (float) $height;
		$i     = 0;
		$n     = count( $clean );

		while ( $i < $n && $w > 0.01 && $h > 0.01 ) {
			$side = min( $w, $h );

			// ساخت ردیف جاری با انتخاب حریصانه بهترین نسبت ابعاد
			$row      = array( $clean[ $i ] );
			$sum_area = $clean[ $i ]['_area'];
			++$i;

			while ( $i < $n ) {
				$candidate_row   = $row;
				$candidate_row[] = $clean[ $i ];
				$candidate_sum   = $sum_area + $clean[ $i ]['_area'];
				if ( self::worst_ratio( $row, $sum_area, $side ) >= self::worst_ratio( $candidate_row, $candidate_sum, $side ) ) {
					$row      = $candidate_row;
					$sum_area = $candidate_sum;
					++$i;
				} else {
					break;
				}
			}

			// چیدمان ردیف در ضلع کوتاه‌تر
			if ( $h >= $w ) {
				$rw = $sum_area / $h;
				$cy = $y;
				foreach ( $row as $item ) {
					$ih      = $item['_area'] / max( 0.0001, $rw );
					$rects[] = array(
						'item' => $item,
						'x'    => $x,
						'y'    => $cy,
						'w'    => $rw,
						'h'    => $ih,
					);
					$cy     += $ih;
				}
				$x += $rw;
				$w -= $rw;
			} else {
				$rh = $sum_area / $w;
				$cx = $x;
				foreach ( $row as $item ) {
					$iw      = $item['_area'] / max( 0.0001, $rh );
					$rects[] = array(
						'item' => $item,
						'x'    => $cx,
						'y'    => $y,
						'w'    => $iw,
						'h'    => $rh,
					);
					$cx     += $iw;
				}
				$y += $rh;
				$h -= $rh;
			}
		}

		return $rects;
	}

	/**
	 * بدترین نسبت ابعاد یک ردیف (معیار Squarify)
	 */
	private static function worst_ratio( $row, $sum_area, $side ) {
		$max = 0.0;
		$min = PHP_FLOAT_MAX;
		foreach ( $row as $item ) {
			$a = $item['_area'];
			if ( $a > $max ) {
				$max = $a;
			}
			if ( $a < $min ) {
				$min = $a;
			}
		}
		if ( $sum_area <= 0 || $min <= 0 || $side <= 0 ) {
			return PHP_FLOAT_MAX;
		}
		$s2  = $sum_area * $sum_area;
		$sd2 = $side * $side;
		return max( ( $sd2 * $max ) / $s2, $s2 / ( $sd2 * $min ) );
	}

	/**
	 * پالت رنگ ثابت برای دسته‌بندی‌ها (بر اساس نام دسته، پایدار بین رفرش‌ها)
	 * @param string $category
	 * @return string
	 */
	public static function category_color( $category ) {
		$palette = array( '#f97316', '#3b82f6', '#10b981', '#8b5cf6', '#ec4899', '#06b6d4', '#eab308', '#ef4444', '#84cc16', '#a855f7' );
		// BUG-15 fix (v2.8.1): crc32() can be negative on 32-bit PHP -> negative array offset.
		return $palette[ abs( crc32( (string) $category ) ) % count( $palette ) ];
	}
}
