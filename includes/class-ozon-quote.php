<?php
/**
 * Расчёт стоимости доставки через Ozon.
 *
 * Ozon считает цену для конкретного пункта выдачи и телефона получателя. Как только
 * покупатель выбрал пункт, плагин спрашивает цену у Ozon (/v1/order/checkout — только
 * расчёт, заявку не создаёт) и подставляет её в способ доставки. Тариф по весу остаётся
 * запасным вариантом: он нужен, пока пункт не выбран или если API не ответил.
 *
 * @package WooOzonDelivery
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Расчёт и хранение цены.
 */
class Woo_Ozon_Quote {

	/**
	 * Ключ сессии WooCommerce.
	 */
	public const SESSION_KEY = 'woo_ozon_quote';

	/**
	 * Сколько секунд считать расчёт свежим.
	 */
	private const TTL = 600;

	/**
	 * Хуки.
	 */
	public static function init(): void {
		add_action( 'wp_ajax_woo_ozon_quote', array( __CLASS__, 'handle' ) );
		add_action( 'wp_ajax_nopriv_woo_ozon_quote', array( __CLASS__, 'handle' ) );
	}

	/**
	 * Посчитанная стоимость для текущей корзины, если она есть.
	 *
	 * @return float|null
	 */
	public static function current(): ?float {
		if ( ! WC()->session || ! WC()->cart ) {
			return null;
		}

		$stored = WC()->session->get( self::SESSION_KEY );

		if ( ! is_array( $stored ) || empty( $stored['cost'] ) || ! isset( $stored['total'] ) ) {
			return null;
		}

		// Цена посчитана для конкретного пункта. Если выбран другой, расчёт не годится:
		// иначе можно получить цену дешёвого ПВЗ и оформить заказ на дорогой.
		$chosen_point = (string) ( WC()->session->get( 'woo_ozon_point_id' ) ?? '' );

		if ( '' === $chosen_point || (string) ( $stored['point'] ?? '' ) !== $chosen_point ) {
			return null;
		}

		// Сумма корзины изменилась — расчёт устарел.
		if ( abs( (float) $stored['total'] - (float) WC()->cart->get_subtotal() ) > 0.01 ) {
			return null;
		}

		if ( (int) ( $stored['at'] ?? 0 ) + self::TTL < time() ) {
			return null;
		}

		return (float) $stored['cost'];
	}

	/**
	 * AJAX: посчитать стоимость для выбранного пункта.
	 */
	public static function handle(): void {
		check_ajax_referer( 'woo_ozon_quote', 'nonce' );

		// Точка публичная: считаем вызовы с адреса, иначе аноним выжигает квоту Ozon
		// и держит воркеры PHP долгими запросами.
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$limit_key   = 'woo_ozon_q_' . md5( $remote_addr );
		$hits      = (int) get_transient( $limit_key );

		if ( $hits >= 30 ) {
			wp_send_json_error( array( 'message' => __( 'Слишком много запросов расчёта. Подождите пару минут.', 'ozon-pickup-points' ) ) );
		}

		set_transient( $limit_key, $hits + 1, 10 * MINUTE_IN_SECONDS );

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			wp_send_json_error( array( 'message' => __( 'Корзина недоступна.', 'ozon-pickup-points' ) ) );
		}

		$point_id = isset( $_POST['point_id'] ) ? absint( wp_unslash( $_POST['point_id'] ) ) : 0;
		$phone    = isset( $_POST['phone'] ) ? self::normalize_phone( sanitize_text_field( wp_unslash( $_POST['phone'] ) ) ) : '';

		if ( $point_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Пункт выдачи не выбран.', 'ozon-pickup-points' ) ) );
		}

		if ( '' === $phone ) {
			wp_send_json_error( array( 'message' => __( 'Укажите телефон: Ozon считает доставку по номеру получателя.', 'ozon-pickup-points' ) ) );
		}

		$point = Woo_Ozon_Points::get( $point_id );

		if ( null === $point ) {
			wp_send_json_error( array( 'message' => __( 'Пункт выдачи не найден.', 'ozon-pickup-points' ) ) );
		}

		if ( WC()->cart->is_empty() ) {
			wp_send_json_error( array( 'message' => __( 'Корзина пуста.', 'ozon-pickup-points' ) ) );
		}

		$settings = Woo_Ozon_Api::settings();

		$posting = Woo_Ozon_Api::build_posting(
			1,
			(int) $point['shipment_method_id'],
			(string) wc_format_decimal( (float) WC()->cart->get_subtotal(), 2 ),
			self::cart_weight_g( (int) $settings['default_weight_g'] ),
			(int) $settings['default_length_mm'],
			(int) $settings['default_width_mm'],
			(int) $settings['default_height_mm']
		);

		/**
		 * Позволяет подменить ответ Ozon: тесты, локальный стенд без ключей.
		 *
		 * @param array<string, mixed>|null $response Заранее известный ответ.
		 * @param string                    $phone Телефон получателя.
		 * @param int                       $point_id Пункт выдачи.
		 * @param array<string, mixed>      $posting Посылка.
		 */
		$response = apply_filters( 'woo_ozon_quote_api_response', null, $phone, $point_id, $posting );

		if ( null === $response ) {
			try {
				$response = Woo_Ozon_Api::checkout( $phone, $point_id, $posting );
			} catch ( Woo_Ozon_Error $e ) {
				wp_send_json_error( array( 'message' => $e->getMessage() ) );
			}
		}

		$cost = self::extract_price( $response );

		if ( null === $cost ) {
			$reason = (string) ( $response['results'][0]['error']['message'] ?? '' );
			$kind   = self::reason_kind( $reason );

			wp_send_json_error(
				array(
					'message'  => self::reason_text( $reason ),
					'code'     => $kind,
					'response' => $response,
				)
			);
		}

		WC()->session->set(
			self::SESSION_KEY,
			array(
				'cost'  => $cost,
				'point' => $point_id,
				'total' => (float) WC()->cart->get_subtotal(),
				'at'    => time(),
			)
		);

		// WooCommerce кэширует ставки в сессии: без сброса новая цена не доедет до оформления.
		foreach ( array_keys( WC()->cart->get_shipping_packages() ) as $package_key ) {
			WC()->session->set( 'shipping_for_package_' . $package_key, null );
		}

		wp_send_json_success(
			array(
				'cost'     => $cost,
				'formatted' => wp_strip_all_tags( wc_price( $cost ) ),
				'point'    => $point_id,
			)
		);
	}

	/**
	 * Вес корзины в граммах.
	 *
	 * @param int $default_g Вес по умолчанию, если у товара не заполнен.
	 */
	private static function cart_weight_g( int $default_g ): int {
		$grams = 0.0;

		foreach ( WC()->cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;

			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			$weight = (float) $product->get_weight();

			if ( $weight <= 0 ) {
				$weight = $default_g > 0 ? $default_g / 1000 : 0.3;
			}

			$grams += $weight * (int) $item['quantity'];
		}

		return max( 1, (int) round( $grams * 1000 ) );
	}

	/**
	 * Телефон в формате Ozon: только цифры, без плюса.
	 *
	 * @param string $phone Как ввёл покупатель.
	 */
	private static function normalize_phone( string $phone ): string {
		$digits = preg_replace( '/\D+/', '', $phone );

		if ( ! is_string( $digits ) ) {
			return '';
		}

		if ( 11 === strlen( $digits ) && str_starts_with( $digits, '8' ) ) {
			$digits = '7' . substr( $digits, 1 );
		}

		return strlen( $digits ) >= 10 ? $digits : '';
	}

	/**
	 * Достаёт стоимость из ответа Ozon.
	 *
	 * Точный вид ответа на /v1/order/checkout без боевых ключей не проверить, поэтому
	 * смотрим известные места, а затем ищем первое число рядом с «price» или «amount».
	 *
	 * @param array<string, mixed> $data Ответ API.
	 */
	private static function extract_price( array $data ): ?float {
		$item = $data['results'][0] ?? ( $data['postings'][0] ?? null );

		// Пункт отказался принимать посылку: Ozon кладёт причину в error этого элемента.
		if ( is_array( $item ) && isset( $item['error'] ) ) {
			return null;
		}

		$paths = array(
			array( 'results', 0, 'posting', 'estimated_delivery_cost', 'amount' ),
			array( 'results', 0, 'posting', 'estimated_delivery_cost', 'price' ),
			array( 'results', 0, 'estimated_delivery_cost', 'amount' ),
			array( 'postings', 0, 'delivery', 'amount' ),
			array( 'postings', 0, 'delivery', 'price' ),
			array( 'delivery', 'amount' ),
			array( 'delivery_price' ),
			array( 'total_price' ),
		);

		$cost = null;

		foreach ( $paths as $path ) {
			$value = self::dig( $data, $path );

			if ( is_scalar( $value ) && is_numeric( $value ) ) {
				$cost = (float) $value;
				break;
			}
		}

		if ( null === $cost ) {
			return null;
		}

		// Страховка обязательна и приходит отдельной строкой: покупатель платит обе.
		$insurance = self::dig( $data, array( 'results', 0, 'posting', 'estimated_insurance_cost', 'amount' ) );

		if ( is_scalar( $insurance ) && is_numeric( $insurance ) ) {
			$cost += (float) $insurance;
		}

		return $cost;
	}

	/**
	 * Достаёт значение по пути.
	 *
	 * @param array<string, mixed> $data Ответ API.
	 * @param array<int, string|int> $path Путь.
	 * @return mixed
	 */
	private static function dig( array $data, array $path ) {
		$node = $data;

		foreach ( $path as $key ) {
			if ( ! is_array( $node ) || ! array_key_exists( $key, $node ) ) {
				return null;
			}

			$node = $node[ $key ];
		}

		return $node;
	}

	/**
	 * Что за отказ: телефон или общая причина.
	 *
	 * @param string $reason Текст ошибки от Ozon.
	 */
	private static function reason_kind( string $reason ): string {
		$reason = mb_strtolower( $reason );

		if ( false !== mb_strpos( $reason, 'невозможно доставить заказ' ) || false !== mb_strpos( $reason, 'phone' ) ) {
			return 'phone';
		}

		return 'other';
	}

	/**
	 * Понятный покупателю текст отказа.
	 *
	 * @param string $reason Текст ошибки от Ozon.
	 */
	private static function reason_text( string $reason ): string {
		if ( 'phone' === self::reason_kind( $reason ) ) {
			return __( 'На этот номер телефона Ozon не доставляет: номер не зарегистрирован в Ozon или заблокирован. Укажите другой номер или выберите доставку другим способом.', 'ozon-pickup-points' );
		}

		if ( '' === trim( $reason ) ) {
			return __( 'Ozon не вернул стоимость доставки. Попробуйте другой пункт или выберите другой способ доставки.', 'ozon-pickup-points' );
		}

		return sprintf(
			/* translators: %s: причина отказа от Ozon */
			__( 'Ozon не доставит этот заказ в выбранный пункт: %s', 'ozon-pickup-points' ),
			$reason
		);
	}
}
