<?php
/**
 * Кэш пунктов выдачи Ozon.
 *
 * Пунктов десятки тысяч, и запрашивать их на каждый поиск в оформлении заказа нельзя:
 * Ozon отдаёт их страницами по курсору. Держим копию в своей таблице и ищем по ней.
 *
 * @package WooOzonDelivery
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Таблица и поиск пунктов выдачи.
 */
class Woo_Ozon_Points {

	/**
	 * Имя таблицы.
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'woo_ozon_points';
	}

	/**
	 * Создаёт таблицу при активации и при обновлении схемы.
	 */
	public static function install_table(): void {
		global $wpdb;

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			point_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(191) NOT NULL DEFAULT '',
			address VARCHAR(255) NOT NULL DEFAULT '',
			city VARCHAR(120) NOT NULL DEFAULT '',
			latitude DECIMAL(10,6) NULL,
			longitude DECIMAL(10,6) NULL,
			point_type VARCHAR(32) NOT NULL DEFAULT '',
			max_weight_g INT UNSIGNED NULL,
			storage_days SMALLINT UNSIGNED NULL,
			is_bulky TINYINT(1) NOT NULL DEFAULT 0,
			shipment_method_id INT UNSIGNED NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (point_id),
			KEY city (city),
			KEY shipment_method (shipment_method_id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Сколько пунктов в кэше.
	 */
	public static function count(): int {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Обновляет кэш из Ozon.
	 *
	 * @return array{ok: bool, message: string, count: int}
	 */
	public static function refresh(): array {
		global $wpdb;

		if ( ! Woo_Ozon_Api::is_configured() ) {
			return array(
				'ok'      => false,
				'message' => 'Сначала укажите Client ID и Client Secret.',
				'count'   => self::count(),
			);
		}

		self::install_table();

		$ids    = array();
		$cursor = '';
		$pages  = 0;

		try {
			do {
				$page   = Woo_Ozon_Api::list_delivery_points( $cursor, 1000 );
				$points = isset( $page['delivery_points'] ) && is_array( $page['delivery_points'] ) ? $page['delivery_points'] : array();

				foreach ( $points as $point ) {
					if ( isset( $point['id'] ) ) {
						$ids[] = (int) $point['id'];
					}
				}

				$cursor = isset( $page['pagination']['next_cursor'] ) ? (string) $page['pagination']['next_cursor'] : '';
				$pages++;

				// Защита от бесконечной пагинации: 200 страниц по 1000 — это 200 000 пунктов.
				if ( $pages > 200 ) {
					break;
				}
			} while ( '' !== $cursor );
		} catch ( Woo_Ozon_Error $e ) {
			return array(
				'ok'      => false,
				'message' => 'Не удалось получить список пунктов: ' . $e->getMessage(),
				'count'   => self::count(),
			);
		}

		if ( empty( $ids ) ) {
			return array(
				'ok'      => false,
				'message' => 'Ozon вернул пустой список пунктов.',
				'count'   => self::count(),
			);
		}

		$now     = current_time( 'mysql', true );
		$updated = 0;

		try {
			foreach ( array_chunk( $ids, 100 ) as $chunk ) {
				foreach ( Woo_Ozon_Api::delivery_point_info( $chunk ) as $info ) {
					if ( empty( $info['id'] ) ) {
						continue;
					}

					$address = self::flatten_address( $info );

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->replace(
						self::table(),
						array(
							'point_id'           => (int) $info['id'],
							'name'               => sanitize_text_field( (string) ( $info['name'] ?? '' ) ),
							'address'            => sanitize_text_field( $address ),
							'city'               => sanitize_text_field( (string) ( $info['city'] ?? ( $info['settlement'] ?? '' ) ) ),
							'latitude'           => isset( $info['coordinates']['latitude'] ) ? (float) $info['coordinates']['latitude'] : null,
							'longitude'          => isset( $info['coordinates']['longitude'] ) ? (float) $info['coordinates']['longitude'] : null,
							'point_type'         => sanitize_text_field( (string) ( $info['type'] ?? '' ) ),
							'max_weight_g'       => isset( $info['restrictions']['max_weight_g'] ) ? (int) $info['restrictions']['max_weight_g'] : null,
							'storage_days'       => isset( $info['restrictions']['storage_days'] ) ? (int) $info['restrictions']['storage_days'] : null,
							'is_bulky'           => ! empty( $info['is_bulky'] ) ? 1 : 0,
							'shipment_method_id' => isset( $info['shipment_method_id'] ) ? (int) $info['shipment_method_id'] : null,
							'updated_at'         => $now,
						),
						array( '%d', '%s', '%s', '%s', '%f', '%f', '%s', '%d', '%d', '%d', '%d', '%s' )
					);

					$updated++;
				}
			}
		} catch ( Woo_Ozon_Error $e ) {
			return array(
				'ok'      => false,
				'message' => 'Список получен, но детали не загрузились: ' . $e->getMessage(),
				'count'   => self::count(),
			);
		}

		// Пункты, которых Ozon больше не отдаёт, закрыты: убираем их из кэша,
		// иначе покупатель выберет пункт, куда доставка уже не работает.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$removed = (int) $wpdb->query(
			$wpdb->prepare( "DELETE FROM " . self::table() . ' WHERE updated_at < %s', $now )
		);

		return array(
			'ok'      => true,
			'message' => sprintf( 'Обновлено пунктов: %d, закрытых убрано: %d.', $updated, $removed ),
			'count'   => self::count(),
		);
	}

	/**
	 * Адрес приходит вложенной структурой — склеиваем в одну строку.
	 *
	 * @param array<string, mixed> $info Данные пункта.
	 */
	private static function flatten_address( array $info ): string {
		if ( isset( $info['address'] ) && is_string( $info['address'] ) ) {
			return $info['address'];
		}

		$parts = array();
		foreach ( array( 'region', 'settlement', 'street', 'house', 'building', 'postcode' ) as $key ) {
			if ( ! empty( $info[ $key ] ) && is_string( $info[ $key ] ) ) {
				$parts[] = $info[ $key ];
			}
		}

		return implode( ', ', $parts );
	}

	/**
	 * Поиск пунктов по городу и адресу.
	 *
	 * @param string $query Строка поиска.
	 * @param string $city Город.
	 * @param int    $limit Сколько вернуть.
	 * @return array<int, array<string, mixed>>
	 */
	public static function search( string $query = '', string $city = '', int $limit = 30 ): array {
		global $wpdb;

		$table  = self::table();
		$limit  = max( 1, min( $limit, 100 ) );
		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $city ) {
			$where[]  = 'city = %s';
			$params[] = $city;
		}

		if ( '' !== $query ) {
			// Город тоже ищем: покупатели чаще пишут «Санкт-Петербург», чем адрес пункта.
			$where[]  = '(address LIKE %s OR name LIKE %s OR city LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $query ) . '%';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$params[] = $limit;

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY city, address LIMIT %d';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Один пункт по идентификатору.
	 *
	 * @param int $point_id Идентификатор пункта.
	 * @return array<string, mixed>|null
	 */
	public static function get( int $point_id ): ?array {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE point_id = %d", $point_id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Города, по которым есть пункты.
	 *
	 * @return string[]
	 */
	public static function cities( int $limit = 50 ): array {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT city FROM {$table} WHERE city <> '' ORDER BY city LIMIT %d", $limit ) );

		return is_array( $rows ) ? array_map( 'strval', $rows ) : array();
	}
}
