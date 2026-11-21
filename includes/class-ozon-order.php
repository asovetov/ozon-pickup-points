<?php
/**
 * Заявка Ozon из админки и синхронизация статусов.
 *
 * Создание посылки — мутация: вызывается только по кнопке с nonce и правом manage_woocommerce,
 * повтор защищён ключом идемпотентности. Статусы подтягиваются по крону и переносятся в статус
 * заказа, чтобы покупатель видел правду без ручной работы.
 *
 * @package WooOzonDelivery
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Админка заказа и крон.
 */
class Woo_Ozon_Order {

	public const META_ORDER_NUMBER   = '_woo_ozon_order_number';
	public const META_POSTING_NUMBER = '_woo_ozon_posting_number';
	public const META_STATUS         = '_woo_ozon_status';
	public const META_COST           = '_woo_ozon_cost';
	public const CRON_HOOK           = 'woo_ozon_sync_statuses';

	/**
	 * Соответствие статусов Ozon статусам WooCommerce по умолчанию.
	 *
	 * Только штатные статусы: у чужого сайта может не быть ни «Доставляется», ни
	 * «Готов к выдаче». Свои названия подключаются фильтром `woo_ozon_status_map`.
	 *
	 * @var array<string, string>
	 */
	private const CORE_MAP = array(
		'created'             => 'wc-processing',
		'awaiting_approve'    => 'wc-processing',
		'awaiting_packaging'  => 'wc-processing',
		'sent'                => 'wc-processing',
		'delivering'          => 'wc-processing',
		'ready_for_pickup'    => 'wc-processing',
		'delivered'           => 'wc-completed',
		'cancelled'           => 'wc-cancelled',
		'arbitration'         => 'wc-on-hold',
	);

	/**
	 * Статусы, после которых опрашивать Ozon больше не нужно.
	 *
	 * @var string[]
	 */
	private const TERMINAL = array( 'delivered', 'cancelled', 'cancelled_from_split_pending' );

	/**
	 * Карта статусов с учётом фильтра.
	 *
	 * @return array<string, string>
	 */
	public static function status_map(): array {
		/**
		 * Позволяет перевести статусы Ozon в свои статусы магазина.
		 *
		 * @param array<string, string> $map Соответствие «статус Ozon → wc-статус».
		 */
		$map = apply_filters( 'woo_ozon_status_map', self::CORE_MAP );

		return is_array( $map ) ? $map : self::CORE_MAP;
	}

	/**
	 * Подключает хуки.
	 */
	public static function init(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'admin_post_woo_ozon_create', array( __CLASS__, 'handle_create' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'sync_statuses' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
	}

	/**
	 * Добавляет расписание раз в 30 минут.
	 *
	 * @param array<string, array<string, mixed>> $schedules Расписания.
	 * @return array<string, array<string, mixed>>
	 */
	public static function add_schedule( array $schedules ): array {
		$schedules['woo_ozon_half_hour'] = array(
			'interval' => 1800,
			'display'  => __( 'Каждые 30 минут', 'woo-ozon-delivery' ),
		);

		return $schedules;
	}

	/**
	 * Блок в карточке заказа.
	 */
	public static function add_meta_box(): void {
		$screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' )
			&& wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box(
			'woo-ozon-box',
			__( 'Ozon', 'woo-ozon-delivery' ),
			array( __CLASS__, 'render_meta_box' ),
			$screen,
			'side',
			'high'
		);
	}

	/**
	 * Содержимое блока.
	 *
	 * @param WP_Post|WC_Order $post_or_order Объект экрана.
	 */
	public static function render_meta_box( $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			echo '<p>' . esc_html__( 'Недостаточно прав.', 'woo-ozon-delivery' ) . '</p>';
			return;
		}

		$point  = (string) $order->get_meta( Woo_Ozon_Checkout::META_POINT_LABEL );
		$number = (string) $order->get_meta( self::META_ORDER_NUMBER );
		$status = (string) $order->get_meta( self::META_STATUS );
		$cost   = (string) $order->get_meta( self::META_COST );

		echo '<p><strong>' . esc_html__( 'Пункт выдачи', 'woo-ozon-delivery' ) . '</strong><br>';
		echo '' !== $point ? esc_html( $point ) : esc_html__( 'не выбран', 'woo-ozon-delivery' );
		echo '</p>';

		if ( '' !== $number ) {
			printf(
				'<p><strong>%s</strong><br>%s</p>',
				esc_html__( 'Заявка Ozon', 'woo-ozon-delivery' ),
				esc_html( $number )
			);

			if ( '' !== $status ) {
				printf(
					'<p><strong>%s</strong><br>%s</p>',
					esc_html__( 'Статус Ozon', 'woo-ozon-delivery' ),
					esc_html( $status )
				);
			}

			if ( '' !== $cost ) {
				printf(
					'<p><strong>%s</strong><br>%s ₽</p>',
					esc_html__( 'Стоимость по Ozon', 'woo-ozon-delivery' ),
					esc_html( $cost )
				);
			}

			return;
		}

		if ( '' === $point ) {
			echo '<p>' . esc_html__( 'Сначала покупатель должен выбрать пункт выдачи.', 'woo-ozon-delivery' ) . '</p>';
			return;
		}

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=woo_ozon_create&order_id=' . $order->get_id() ),
			'woo_ozon_create_' . $order->get_id()
		);

		printf(
			'<p><a class="button button-primary" href="%s">%s</a></p>',
			esc_url( $url ),
			esc_html__( 'Создать заявку в Ozon', 'woo-ozon-delivery' )
		);
	}

	/**
	 * Обработчик кнопки: собирает посылку и создаёт заявку.
	 */
	public static function handle_create(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'woo-ozon-delivery' ) );
		}

		$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;

		check_admin_referer( 'woo_ozon_create_' . $order_id );

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wp_die( esc_html__( 'Заказ не найден.', 'woo-ozon-delivery' ) );
		}

		$result = self::create_shipment( $order );

		$redirect = add_query_arg(
			array(
				'post'   => $order_id,
				'action' => 'edit',
				'woo_ozon' => $result['ok'] ? 'created' : 'error',
			),
			admin_url( 'post.php' )
		);

		if ( ! $result['ok'] ) {
			set_transient( 'woo_ozon_error_' . $order_id, $result['message'], 300 );
		}

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Создаёт заявку Ozon для заказа.
	 *
	 * @param WC_Order $order Заказ.
	 * @return array{ok: bool, message: string}
	 */
	public static function create_shipment( WC_Order $order ): array {
		$point_id = (int) $order->get_meta( Woo_Ozon_Checkout::META_POINT_ID );
		if ( $point_id <= 0 ) {
			return array(
				'ok'      => false,
				'message' => __( 'В заказе нет пункта выдачи Ozon.', 'woo-ozon-delivery' ),
			);
		}

		$point = Woo_Ozon_Points::get( $point_id );
		if ( null === $point || empty( $point['shipment_method_id'] ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Пункт выдачи не найден в кэше или у него нет способа доставки.', 'woo-ozon-delivery' ),
			);
		}

		$settings = Woo_Ozon_Api::settings();
		$phone    = self::normalize_phone( (string) $order->get_billing_phone() );
		$weight   = self::order_weight_g( $order, (int) $settings['default_weight_g'] );

		$posting = Woo_Ozon_Api::build_posting(
			1,
			(int) $point['shipment_method_id'],
			Woo_Ozon_Api::default_cutoff(),
			(string) max( 0, (float) $order->get_total() ),
			$weight,
			(int) $settings['default_length_mm'],
			(int) $settings['default_width_mm'],
			(int) $settings['default_height_mm']
		);

		$payload = array(
			'recipient' => array( 'phone_number' => $phone ),
			'postings'  => array( $posting ),
			'delivery'  => array( 'delivery_point' => array( 'delivery_point_id' => $point_id ) ),
		);

		// Ключ идемпотентности привязан к заказу: повтор кнопки не создаст вторую посылку.
		$idempotency = 'wc-' . $order->get_id() . '-' . substr( md5( (string) $order->get_order_key() ), 0, 12 );

		try {
			$response = Woo_Ozon_Api::create_order( $payload, $idempotency );
		} catch ( Woo_Ozon_Error $e ) {
			$order->add_order_note( 'Ozon: ошибка создания заявки — ' . $e->getMessage() );

			return array(
				'ok'      => false,
				'message' => $e->getMessage(),
			);
		}

		$order_number   = (string) ( $response['order_number'] ?? '' );
		$posting_number = (string) ( $response['postings'][0]['posting_number'] ?? '' );

		$order->update_meta_data( self::META_ORDER_NUMBER, $order_number );
		$order->update_meta_data( self::META_POSTING_NUMBER, $posting_number );
		$order->update_meta_data( self::META_STATUS, 'created' );
		$order->save();

		$order->add_order_note(
			sprintf(
				/* translators: 1: номер заявки Ozon, 2: номер посылки */
				__( 'Ozon: заявка создана. Заказ %1$s, посылка %2$s.', 'woo-ozon-delivery' ),
				$order_number,
				$posting_number
			)
		);

		return array(
			'ok'      => true,
			'message' => __( 'Заявка создана.', 'woo-ozon-delivery' ),
		);
	}

	/**
	 * Телефон в формате, который принимает Ozon.
	 *
	 * @param string $phone Исходный номер.
	 */
	private static function normalize_phone( string $phone ): string {
		$digits = preg_replace( '/\D+/', '', $phone ) ?? '';

		if ( 11 === strlen( $digits ) && '8' === $digits[0] ) {
			$digits = '7' . substr( $digits, 1 );
		}

		if ( 10 === strlen( $digits ) ) {
			$digits = '7' . $digits;
		}

		return '+' . $digits;
	}

	/**
	 * Вес заказа в граммах.
	 *
	 * @param WC_Order $order Заказ.
	 * @param int      $fallback Вес по умолчанию.
	 */
	private static function order_weight_g( WC_Order $order, int $fallback ): int {
		$weight = 0.0;

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$item_weight = (float) $product->get_weight();
			if ( $item_weight <= 0 ) {
				$item_weight = 0.3;
			}

			$weight += $item_weight * (int) $item->get_quantity();
		}

		$grams = (int) round( $weight * 1000 );

		return $grams > 0 ? $grams : $fallback;
	}

	/**
	 * Крон: опрашивает Ozon и переносит статус в заказ.
	 */
	public static function sync_statuses(): void {
		if ( ! Woo_Ozon_Api::is_configured() ) {
			return;
		}

		// Берём заказы, у которых есть номер посылки, и не смотрим на название статуса:
		// у каждого магазина свои статусы, а признак один — заявка создана.
		$orders = wc_get_orders(
			array(
				'limit'      => 50,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::META_POSTING_NUMBER,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		foreach ( $orders as $order ) {
			$posting_number = (string) $order->get_meta( self::META_POSTING_NUMBER );
			if ( '' === $posting_number ) {
				continue;
			}

			// Доставленные и отменённые не опрашиваем повторно.
			if ( in_array( (string) $order->get_meta( self::META_STATUS ), self::TERMINAL, true ) ) {
				continue;
			}

			try {
				$data = Woo_Ozon_Api::post( '/v1/posting/info', array( 'posting_numbers' => array( $posting_number ) ) );
			} catch ( Woo_Ozon_Error $e ) {
				$order->add_order_note( 'Ozon: не удалось получить статус — ' . $e->getMessage() );
				continue;
			}

			$postings = isset( $data['postings'] ) && is_array( $data['postings'] ) ? $data['postings'] : array();
			if ( empty( $postings ) ) {
				continue;
			}

			$statuses = array();
			foreach ( $postings as $posting ) {
				if ( ! empty( $posting['status'] ) ) {
					$statuses[] = (string) $posting['status'];
				}
			}

			if ( empty( $statuses ) ) {
				continue;
			}

			// Заказ считается доставленным только когда доставлены все посылки.
			$all_terminal = ! array_diff( $statuses, self::TERMINAL );
			$raw          = $all_terminal
				? ( in_array( 'delivered', $statuses, true ) ? 'delivered' : $statuses[0] )
				: ( current( array_diff( $statuses, self::TERMINAL ) ) ?: $statuses[0] );

			$order->update_meta_data( self::META_STATUS, $raw );
			$order->save();

			$map    = self::status_map();
			$target = $map[ $raw ] ?? '';
			if ( '' === $target || $order->get_status() === str_replace( 'wc-', '', $target ) ) {
				continue;
			}

			$order->update_status(
				str_replace( 'wc-', '', $target ),
				sprintf(
					/* translators: %s — статус Ozon */
					__( 'Ozon: статус посылки — %s.', 'woo-ozon-delivery' ),
					$raw
				)
			);
		}
	}
}
