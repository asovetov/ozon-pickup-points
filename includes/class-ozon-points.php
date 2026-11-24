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
			shipment_method_id BIGINT UNSIGNED NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (point_id),
			KEY city (city),
			KEY shipment_method (shipment_method_id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Ozon отдаёт shipment_method_ids вида 1020005034851300 — в INT UNSIGNED они
		// не влезают и превращаются в 4294967295. Расширяем колонку один раз.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- тип колонки читаем один раз при обновлении справочника.
		$column = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				self::table(),
				'shipment_method_id'
			)
		);

		if ( is_string( $column ) && '' !== $column && false === stripos( $column, 'bigint' ) ) {
			// Имя таблицы собрано из $wpdb->prefix, подставлять его в prepare() нечем.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->query( "ALTER TABLE " . self::table() . ' MODIFY shipment_method_id BIGINT UNSIGNED NULL' );
		}
	}

	/**
	 * Сколько пунктов в кэше.
	 */
	public static function count(): int {
		global $wpdb;

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- имя таблицы собирается из $wpdb->prefix.
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Вытаскивает населённый пункт из полного адреса Ozon.
	 *
	 * Адреса приходят в двух порядках: «Россия, Область, Район, Город, Улица, Дом»
	 * и «Улица, Дом, Город, Область, Россия, Индекс». Город ищем по соседству с улицей.
	 *
	 * @param string $address Полный адрес из delivery-point/info.
	 */
	private static function guess_city( string $address ): string {
		$parts = array_values(
			array_filter(
				array_map( 'trim', explode( ',', $address ) ),
				static function ( $part ) {
					return '' !== $part && 'россия' !== mb_strtolower( $part ) && ! ctype_digit( $part );
				}
			)
		);

		if ( empty( $parts ) ) {
			return '';
		}

		$street_markers = array( 'улица', 'ул.', 'проспект', 'пр-т', 'шоссе', 'переулок', 'пер.', 'бульвар', 'набережная', 'площадь', 'пл.', 'микрорайон', 'мкр', 'проезд', 'тупик', 'линия', 'тракт', 'аллея', 'жилой комплекс', 'корпус' );
		$region_markers = array( 'область', 'обл.', 'край', 'респ', 'район', 'автономный округ' );

		$is_street = static function ( string $part ) use ( $street_markers ): bool {
			$lowered = mb_strtolower( $part ) . ' ';
			foreach ( $street_markers as $marker ) {
				if ( false !== mb_strpos( $lowered, $marker ) ) {
					return true;
				}
			}

			return false;
		};

		$is_region = static function ( string $part ) use ( $region_markers ): bool {
			$lowered = mb_strtolower( $part ) . ' ';
			foreach ( $region_markers as $marker ) {
				if ( false !== mb_strpos( $lowered, $marker ) ) {
					return true;
				}
			}

			return false;
		};

		$street_at = null;
		foreach ( $parts as $index => $part ) {
			if ( $is_street( $part ) ) {
				$street_at = $index;
				break;
			}
		}

		if ( null === $street_at ) {
			foreach ( $parts as $part ) {
				if ( ! $is_region( $part ) ) {
					return self::clean_city( $part );
				}
			}

			return '';
		}

		if ( 0 === $street_at ) {
			// Улица первой: город идёт сразу после номера дома.
			foreach ( array_slice( $parts, 1, 2 ) as $part ) {
				if ( preg_match( '/^\d+[а-яa-z]?(\/\d+[а-яa-z]?)?$/iu', $part ) ) {
					continue;
				}
				if ( $is_region( $part ) ) {
					return '';
				}

				return self::clean_city( $part );
			}

			return '';
		}

		// Сначала регион: город стоит прямо перед улицей.
		$before = array();
		foreach ( array_slice( $parts, 0, $street_at ) as $part ) {
			if ( ! $is_region( $part ) ) {
				$before[] = $part;
			}
		}

		return $before ? self::clean_city( (string) end( $before ) ) : '';
	}

	/**
	 * Снимает с названия города служебные приставки.
	 *
	 * @param string $value Название населённого пункта.
	 */
	private static function clean_city( string $value ): string {
		return trim( (string) preg_replace( '/^(г\.?|город|пос\.?|посёлок|пгт\.?|с\.?|село|д\.?|деревня)\s+/iu', '', $value ) );
	}

	/**
	 * Детали пунктов с устойчивостью к закрытым id: Ozon отвечает 404 на всю пачку,
	 * если хотя бы одного пункта уже нет. Тогда делим пачку и доходим до одиночных.
	 *
	 * @param int[] $ids Идентификаторы пунктов, не больше 100.
	 * @return array<int, array<string, mixed>>
	 */
	private static function info_resilient( array $ids ): array {
		if ( empty( $ids ) ) {
			return array();
		}

		try {
			return Woo_Ozon_Api::delivery_point_info( $ids );
		} catch ( Woo_Ozon_Error $e ) {
			if ( 404 !== (int) $e->status_code ) {
				throw $e;
			}

			// Одиночный пункт не найден — просто пропускаем его.
			if ( 1 === count( $ids ) ) {
				return array();
			}

			$half = (int) ceil( count( $ids ) / 2 );

			return array_merge(
				self::info_resilient( array_slice( $ids, 0, $half ) ),
				self::info_resilient( array_slice( $ids, $half ) )
			);
		}
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

		$ids     = array();
		$methods = array();
		$cursor  = '';
		$pages  = 0;

		try {
			do {
				$page   = Woo_Ozon_Api::list_delivery_points( $cursor, 1000 );
				$points = isset( $page['delivery_points'] ) && is_array( $page['delivery_points'] ) ? $page['delivery_points'] : array();

				foreach ( $points as $point ) {
					// Список отдаёт delivery_point_id, а метод способа доставки лежит здесь же
					// и в delivery-point/info уже не приходит.
					if ( empty( $point['delivery_point_id'] ) ) {
						continue;
					}

					$id            = (int) $point['delivery_point_id'];
					$ids[]         = $id;
					$methods[ $id ] = isset( $point['shipment_method_ids'][0] ) ? (int) $point['shipment_method_ids'][0] : 0;
				}

				$cursor = isset( $page['next_cursor'] ) ? (string) $page['next_cursor'] : '';
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
				foreach ( self::info_resilient( $chunk ) as $info ) {
					if ( empty( $info['delivery_point_id'] ) ) {
						continue;
					}

					// Пункт, который Ozon закрыл, в кэш не кладём: покупателю он уже недоступен.
					if ( isset( $info['is_active'] ) && ! $info['is_active'] ) {
						continue;
					}

					$id      = (int) $info['delivery_point_id'];
					$address = (string) ( $info['full_address'] ?? '' );

					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->replace(
						self::table(),
						array(
							'point_id'           => $id,
							'name'               => sanitize_text_field( (string) ( $info['name'] ?? '' ) ),
							'address'            => sanitize_text_field( $address ),
							'city'               => sanitize_text_field( self::guess_city( $address ) ),
							'latitude'           => isset( $info['coordinates']['latitude'] ) ? (float) $info['coordinates']['latitude'] : null,
							'longitude'          => isset( $info['coordinates']['longitude'] ) ? (float) $info['coordinates']['longitude'] : null,
							'point_type'         => sanitize_text_field( (string) ( $info['type'] ?? '' ) ),
							'max_weight_g'       => isset( $info['restrictions']['max_weight_g'] ) ? (int) $info['restrictions']['max_weight_g'] : null,
							'storage_days'       => isset( $info['storage_period_days'] ) ? (int) $info['storage_period_days'] : null,
							'is_bulky'           => ! empty( $info['is_bulky'] ) ? 1 : 0,
							'shipment_method_id' => ! empty( $methods[ $id ] ) ? (int) $methods[ $id ] : null,
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
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- имя таблицы собирается из $wpdb->prefix.
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$sql = $wpdb->prepare( "DELETE FROM {$table} WHERE updated_at < %s", $now );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$removed = (int) $wpdb->query( $sql );

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
	 * Пункты города с координатами — для карты.
	 *
	 * @param string $city  Город.
	 * @param int    $limit Сколько отдать.
	 * @return array<int, array<string, mixed>>
	 */
	public static function by_city( string $city, int $limit = 300 ): array {
		global $wpdb;

		$city = trim( $city );
		if ( '' === $city ) {
			return array();
		}

		$table = self::table();
		$like  = '%' . $wpdb->esc_like( $city ) . '%';
		$limit = max( 1, min( $limit, 300 ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- имя таблицы из $wpdb->prefix.
		$sql = "SELECT * FROM {$table} WHERE city LIKE %s AND latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY (city = %s) DESC, address LIMIT %d";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql прогнан через prepare() строкой ниже.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $like, $city, $limit ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
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

		// Совпадение по городу важнее совпадения по области: на «Челябинск» первым
		// должен идти Челябинск, а не Агаповка из Челябинской области.
		$order = 'ORDER BY city, address';
		if ( '' !== $query ) {
			$order    = 'ORDER BY (city = %s) DESC, (city LIKE %s) DESC, city, address';
			$params[] = $query;
			$params[] = '%' . $wpdb->esc_like( $query ) . '%';
		}

		$params[] = $limit;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- имя таблицы из $wpdb->prefix, остальное через prepare().
		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ' . $order . ' LIMIT %d';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql собран выше и прогнан через prepare().
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

// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- имя таблицы собирается из $wpdb->prefix.
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
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

// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- имя таблицы собирается из $wpdb->prefix.
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT city FROM {$table} WHERE city <> '' ORDER BY city LIMIT %d", $limit ) );

		return is_array( $rows ) ? array_map( 'strval', $rows ) : array();
	}
}
