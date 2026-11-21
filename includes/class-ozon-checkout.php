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
		add_action( 'woocommerce_review_order_before_payment', array( __CLASS__, 'render_field' ) );
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
			WOO_OZON_VERSION,
			true
		);

		wp_localize_script(
			'woo-ozon-checkout',
			'WooOzon',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'woo_ozon_points' ),
				'i18n'    => array(
					'empty'   => __( 'Введите город или адрес пункта', 'woo-ozon-delivery' ),
					'loading' => __( 'Ищем пункты…', 'woo-ozon-delivery' ),
					'none'    => __( 'Пунктов не нашлось. Попробуйте другой город.', 'woo-ozon-delivery' ),
					'error'   => __( 'Не удалось получить список пунктов.', 'woo-ozon-delivery' ),
				),
			)
		);

		wp_enqueue_style(
			'woo-ozon-checkout',
			WOO_OZON_URL . 'assets/css/ozon-checkout.css',
			array(),
			WOO_OZON_VERSION
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
	public static function render_field(): void {
		if ( ! self::ozon_selected() ) {
			return;
		}

		$selected = WC()->session ? (string) WC()->session->get( 'woo_ozon_point_id', '' ) : '';
		$selected_label = WC()->session ? (string) WC()->session->get( 'woo_ozon_point_label', '' ) : '';
		$city     = WC()->customer ? (string) WC()->customer->get_billing_city() : '';
		?>
		<div class="woo-ozon-pvz" id="woo-ozon-pvz">
			<h3><?php echo esc_html__( 'Пункт выдачи Ozon', 'woo-ozon-delivery' ); ?></h3>

			<p class="form-row form-row-wide">
				<label for="woo-ozon-query"><?php echo esc_html__( 'Город или адрес', 'woo-ozon-delivery' ); ?></label>
				<input type="search" id="woo-ozon-query" class="input-text" value="<?php echo esc_attr( $city ); ?>" placeholder="<?php echo esc_attr__( 'Например, Санкт-Петербург', 'woo-ozon-delivery' ); ?>" autocomplete="off">
			</p>

			<div class="woo-ozon-pvz__list" id="woo-ozon-list" role="listbox" aria-live="polite"></div>

			<input type="hidden" name="woo_ozon_point_id" id="woo-ozon-point-id" value="<?php echo esc_attr( $selected ); ?>">
			<input type="hidden" name="woo_ozon_point_label" id="woo-ozon-point-label" value="<?php echo esc_attr( $selected_label ); ?>">
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

		$rows = Woo_Ozon_Points::search( $query, $city, 40 );

		$points = array();
		foreach ( $rows as $row ) {
			$points[] = array(
				'id'      => (int) $row['point_id'],
				'name'    => (string) $row['name'],
				'address' => (string) $row['address'],
				'city'    => (string) $row['city'],
			);
		}

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

		$point_id = isset( $_POST['woo_ozon_point_id'] ) ? absint( wp_unslash( $_POST['woo_ozon_point_id'] ) ) : 0;

		if ( $point_id <= 0 ) {
			wc_add_notice( __( 'Выберите пункт выдачи Ozon.', 'woo-ozon-delivery' ), 'error' );
			return;
		}

		if ( null === Woo_Ozon_Points::get( $point_id ) ) {
			wc_add_notice( __( 'Этот пункт выдачи больше недоступен, выберите другой.', 'woo-ozon-delivery' ), 'error' );
		}
	}

	/**
	 * Сохраняет пункт в заказ.
	 *
	 * @param WC_Order $order Заказ.
	 * @param array<string, mixed> $data Данные оформления.
	 */
	public static function save_to_order( WC_Order $order, array $data ): void {
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
			esc_html__( 'Пункт выдачи Ozon', 'woo-ozon-delivery' ),
			esc_html( $label )
		);
	}

	/**
	 * Добавляет пункт в письма о заказе.
	 *
	 * @param array<string, array<string, string>> $fields Поля письма.
	 * @param bool                                  $sent_to_admin Письмо админу.
	 * @param WC_Order                              $order Заказ.
	 * @return array<string, array<string, string>>
	 */
	public static function email_fields( array $fields, bool $sent_to_admin, WC_Order $order ): array {
		$label = (string) $order->get_meta( self::META_POINT_LABEL );

		if ( '' !== $label ) {
			$fields['woo_ozon_point'] = array(
				'label' => __( 'Пункт выдачи Ozon', 'woo-ozon-delivery' ),
				'value' => $label,
			);
		}

		return $fields;
	}
}
