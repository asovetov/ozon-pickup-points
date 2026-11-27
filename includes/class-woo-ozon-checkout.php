<?php
/**
 * Выбор пункта выдачи в оформлении заказа.
 *
 * В корзине пункт неизвестен, поэтому поле появляется на шаге оформления, когда покупатель
 * уже выбрал метод «Ozon». Список берётся из своего кэша, а не из API: иначе каждый ввод
 * города бил бы в Ozon.
 *
 * @package WooOzonDelivery
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Поле выбора ПВЗ, AJAX-поиск и сохранение в заказ.
 */
class Woo_Ozon_Checkout {

	/**
	 * Мета заказа: идентификатор пункта.
	 */
	public const META_POINT_ID = '_woo_ozon_point_id';

	/**
	 * Мета заказа: подпись пункта на момент заказа.
	 */
	public const META_POINT_LABEL = '_woo_ozon_point_label';

	/**
	 * Подключает хуки.
	 */
	public static function init(): void {
		// Блок выбора пункта живёт прямо в строке тарифа, рядом с остальными способами.
		add_action( 'woocommerce_after_shipping_rate', array( __CLASS__, 'render_rate_block' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_woo_ozon_points', array( __CLASS__, 'ajax_points' ) );
		add_action( 'wp_ajax_nopriv_woo_ozon_points', array( __CLASS__, 'ajax_points' ) );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'validate' ) );
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'remember_point' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_to_order' ), 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( __CLASS__, 'render_admin_point' ) );
		add_filter( 'woocommerce_email_order_meta_fields', array( __CLASS__, 'email_fields' ), 10, 3 );
	}

	/**
	 * Скрипт и стили только там, где нужны.
	 */
	public static function enqueue(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}

		wp_enqueue_script(
			'woo-ozon-checkout',
			WOO_OZON_URL . 'assets/js/ozon-checkout.js',
			array( 'jquery' ),
			(string) filemtime( WOO_OZON_DIR . 'assets/js/ozon-checkout.js' ),
			true
		);

		wp_localize_script(
			'woo-ozon-checkout',
			'WooOzon',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'woo_ozon_points' ),
				'quoteNonce' => wp_create_nonce( 'woo_ozon_quote' ),
				'mapKey'     => Woo_Ozon_Api::map_key(),
				'i18n'       => array(
					'empty'       => __( 'Enter a city or a pickup point address', 'sovetov-ozon-pickup-points' ),
					'loading'     => __( 'Searching for pickup points…', 'sovetov-ozon-pickup-points' ),
					'none'        => __( 'No pickup points found. Try another city.', 'sovetov-ozon-pickup-points' ),
					'error'       => __( 'Could not load the pickup point list.', 'sovetov-ozon-pickup-points' ),
					'quoting'     => __( 'Calculating delivery…', 'sovetov-ozon-pickup-points' ),
					/* translators: %s: сумма доставки. */
					'quote'       => __( 'Ozon delivery: %s', 'sovetov-ozon-pickup-points' ),
					/* translators: %s: текст ошибки от Ozon. */
					'quoteFail'   => __( 'Ozon could not calculate delivery: %s', 'sovetov-ozon-pickup-points' ),
					'needPhone'   => __( 'Enter a phone number: Ozon calculates delivery by the recipient phone number.', 'sovetov-ozon-pickup-points' ),
					'mapNoCity'   => __( 'City is not set', 'sovetov-ozon-pickup-points' ),
					'cityChanged' => __( 'The city has changed — choose a pickup point again.', 'sovetov-ozon-pickup-points' ),
					'mapFail'     => __( 'The map failed to load. Choose a pickup point from the list.', 'sovetov-ozon-pickup-points' ),
				),
			)
		);

		wp_enqueue_style(
			'woo-ozon-checkout',
			WOO_OZON_URL . 'assets/css/ozon-checkout.css',
			array(),
			(string) filemtime( WOO_OZON_DIR . 'assets/css/ozon-checkout.css' )
		);
	}

	/**
	 * Выбран ли Ozon в текущем заказе.
	 */
	private static function ozon_selected(): bool {
		$chosen = WC()->session ? WC()->session->get( 'chosen_shipping_methods' ) : array();
		if ( ! is_array( $chosen ) ) {
			return false;
		}

		foreach ( $chosen as $method ) {
			if ( is_string( $method ) && 0 === strpos( $method, 'woo_ozon' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Поле выбора пункта на странице оформления.
	 */
	/**
	 * Кнопка выбора пункта внутри строки тарифа Ozon.
	 *
	 * @param WC_Shipping_Rate $method Тариф.
	 * @param int              $index  Порядковый номер.
	 */
	public static function render_rate_block( $method, $index ): void {
		// Пункт выдачи выбирают на оформлении: в корзине ещё нет города и адреса,
		// и карта открывалась бы пустой.
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return;
		}

		unset( $index );

		if ( ! $method instanceof WC_Shipping_Rate || 0 !== strpos( (string) $method->get_id(), 'woo_ozon' ) ) {
			return;
		}

		$selected       = WC()->session ? (string) WC()->session->get( 'woo_ozon_point_id', '' ) : '';
		$selected_label = WC()->session ? (string) WC()->session->get( 'woo_ozon_point_label', '' ) : '';
		$city           = WC()->customer ? (string) WC()->customer->get_billing_city() : '';
		?>
		<div class="woo-ozon-pvz" id="woo-ozon-pvz">
			<button type="button" class="button open-pvz-btn" id="woo-ozon-map-open">
				<?php echo esc_html__( 'Choose a point on the map', 'sovetov-ozon-pickup-points' ); ?>
			</button>

			<p class="woo-ozon-pvz__chosen" id="woo-ozon-chosen"<?php echo '' === $selected_label ? ' hidden' : ''; ?>>
				<?php echo esc_html( $selected_label ); ?>
			</p>

			<p class="woo-ozon-quote" id="woo-ozon-quote-status" aria-live="polite"></p>

			<input type="hidden" name="woo_ozon_point_id" id="woo-ozon-point-id" value="<?php echo esc_attr( $selected ); ?>">
			<input type="hidden" name="woo_ozon_point_label" id="woo-ozon-point-label" value="<?php echo esc_attr( $selected_label ); ?>">
			<input type="hidden" id="woo-ozon-city" value="<?php echo esc_attr( $city ); ?>">
			<input type="hidden" id="woo-ozon-point-city" value="">
		</div>

		<div class="woo-ozon-map" id="woo-ozon-map-modal" hidden>
			<div class="woo-ozon-map__bar">
				<strong id="woo-ozon-map-city"></strong>
				<button type="button" class="woo-ozon-map__close" id="woo-ozon-map-close" aria-label="<?php echo esc_attr__( 'Close the map', 'sovetov-ozon-pickup-points' ); ?>">×</button>
			</div>
			<div class="woo-ozon-map__canvas" id="woo-ozon-map-canvas"></div>
		</div>
		<?php
	}

	/**
	 * AJAX: поиск пунктов по кэшу.
	 */
	public static function ajax_points(): void {
		check_ajax_referer( 'woo_ozon_points', 'nonce' );

		$query = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['q'] ) ) : '';
		$city  = isset( $_GET['city'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['city'] ) ) : '';
		$map   = isset( $_GET['map'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_GET['map'] ) );

		$rows = $map
			? Woo_Ozon_Points::by_city( $city, 300 )
			: Woo_Ozon_Points::search( $query, $city, 40 );

		$points = array();
		foreach ( $rows as $row ) {
			$points[] = array(
				'id'      => (int) $row['point_id'],
				'name'    => (string) $row['name'],
				'address' => (string) $row['address'],
				'city'    => (string) $row['city'],
				'lat'     => isset( $row['latitude'] ) ? (float) $row['latitude'] : 0,
				'lng'     => isset( $row['longitude'] ) ? (float) $row['longitude'] : 0,
			);
		}

		/**
		 * Точки, которые уйдут на карту. Плагины сайта могут поправить координаты.
		 *
		 * @param array<int, array<string, mixed>> $points Точки.
		 * @param string                           $city   Город.
		 */
		$points = apply_filters( 'woo_ozon_map_points', $points, $city );

		wp_send_json_success( array( 'points' => $points ) );
	}


	/**
	 * Запоминает выбранный пункт между пересборками оформления.
	 *
	 * WooCommerce перерисовывает блок оплаты на каждое изменение адреса, и без этого
	 * выбранный пункт пропадал бы из поля.
	 *
	 * @param string $post_data Данные формы в виде строки запроса.
	 */
	public static function remember_point( string $post_data ): void {
		if ( ! WC()->session ) {
			return;
		}

		parse_str( $post_data, $data );

		$point_id = isset( $data['woo_ozon_point_id'] ) ? absint( $data['woo_ozon_point_id'] ) : 0;

		WC()->session->set( 'woo_ozon_point_id', $point_id > 0 ? (string) $point_id : '' );

		$label = isset( $data['woo_ozon_point_label'] ) ? sanitize_text_field( (string) $data['woo_ozon_point_label'] ) : '';
		WC()->session->set( 'woo_ozon_point_label', $label );
	}

	/**
	 * Проверка перед созданием заказа.
	 */
	public static function validate(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce оформления заказа проверяет WooCommerce до вызова этого метода.
		$chosen = isset( $_POST['shipping_method'] ) && is_array( $_POST['shipping_method'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['shipping_method'] ) ) : array();

		$is_ozon = false;
		foreach ( $chosen as $method ) {
			if ( 0 === strpos( $method, 'woo_ozon' ) ) {
				$is_ozon = true;
			}
		}

		if ( ! $is_ozon ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce оформления заказа проверяет WooCommerce до вызова этого метода.
		$point_id = isset( $_POST['woo_ozon_point_id'] ) ? absint( wp_unslash( $_POST['woo_ozon_point_id'] ) ) : 0;

		if ( $point_id <= 0 ) {
			wc_add_notice( __( 'Choose an Ozon pickup point.', 'sovetov-ozon-pickup-points' ), 'error' );
			return;
		}

		$quoted       = WC()->session ? WC()->session->get( Woo_Ozon_Quote::SESSION_KEY ) : null;
		$quoted_point = is_array( $quoted ) ? (string) ( $quoted['point'] ?? '' ) : '';

		if ( $quoted_point !== (string) $point_id ) {
			wc_add_notice(
				__( 'The delivery price does not match the selected point. Reload the page and choose a point again.', 'sovetov-ozon-pickup-points' ),
				'error'
			);

			return;
		}

		if ( null === Woo_Ozon_Points::get( $point_id ) ) {
			wc_add_notice( __( 'This pickup point is no longer available, choose another one.', 'sovetov-ozon-pickup-points' ), 'error' );
		}
	}

	/**
	 * Сохраняет пункт в заказ.
	 *
	 * @param WC_Order             $order Заказ.
	 * @param array<string, mixed> $data Данные оформления.
	 */
	public static function save_to_order( WC_Order $order, array $data ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce оформления заказа проверяет WooCommerce до вызова этого метода.
		$point_id = isset( $_POST['woo_ozon_point_id'] ) ? absint( wp_unslash( $_POST['woo_ozon_point_id'] ) ) : 0;

		if ( $point_id <= 0 ) {
			return;
		}

		$point = Woo_Ozon_Points::get( $point_id );
		if ( null === $point ) {
			return;
		}

		$label = trim( (string) $point['name'] . ', ' . (string) $point['address'] );

		$order->update_meta_data( self::META_POINT_ID, $point_id );
		$order->update_meta_data( self::META_POINT_LABEL, $label );

		if ( WC()->session ) {
			WC()->session->set( 'woo_ozon_point_id', '' );
			WC()->session->set( 'woo_ozon_point_label', '' );
		}
	}

	/**
	 * Показывает пункт в карточке заказа в админке.
	 *
	 * @param WC_Order $order Заказ.
	 */
	public static function render_admin_point( WC_Order $order ): void {
		$label = (string) $order->get_meta( self::META_POINT_LABEL );
		if ( '' === $label ) {
			return;
		}

		printf(
			'<p><strong>%s</strong><br>%s</p>',
			esc_html__( 'Ozon pickup point', 'sovetov-ozon-pickup-points' ),
			esc_html( $label )
		);
	}

	/**
	 * Добавляет пункт в письма о заказе.
	 *
	 * @param array<string, array<string, string>> $fields Поля письма.
	 * @param bool                                 $sent_to_admin Письмо админу.
	 * @param WC_Order                             $order Заказ.
	 * @return array<string, array<string, string>>
	 */
	public static function email_fields( array $fields, bool $sent_to_admin, WC_Order $order ): array {
		$label = (string) $order->get_meta( self::META_POINT_LABEL );

		if ( '' !== $label ) {
			$fields['woo_ozon_point'] = array(
				'label' => __( 'Ozon pickup point', 'sovetov-ozon-pickup-points' ),
				'value' => $label,
			);
		}

		return $fields;
	}
}
