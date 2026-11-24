<?php
/**
 * Клиент Ozon Delivery API.
 *
 * Особенность API: каждый POST сначала отвечает 307 с cookie `__Secure-ETC`, и только повтор
 * того же запроса с этой cookie отдаёт результат. Поэтому редиректы обрабатываются вручную:
 * обычное следование за редиректом зацикливается на `?__rr=N`.
 *
 * @package WooOzonDelivery
 */

declare( strict_types=1 );

// Тексты исключений попадают в лог и в заметки заказа, в HTML не выводятся,
// поэтому экранирование вывода к ним не применимо.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

defined( 'ABSPATH' ) || exit;

/**
 * Ошибка Ozon.
 */
class Woo_Ozon_Error extends Exception {

	/**
	 * Код ответа HTTP.
	 *
	 * @var int
	 */
	public int $status_code;

	/**
	 * Тело ответа.
	 *
	 * @var mixed
	 */
	public $details;

	/**
	 * Конструктор.
	 *
	 * @param string $message Текст ошибки.
	 * @param int    $status_code Код HTTP.
	 * @param mixed  $details Тело ответа.
	 */
	public function __construct( string $message, int $status_code = 0, $details = null ) {
		parent::__construct( $message );
		$this->status_code = $status_code;
		$this->details     = $details;
	}
}

/**
 * HTTP-клиент Ozon.
 */
class Woo_Ozon_Api {

	private const TOKEN_TRANSIENT = 'woo_ozon_access_token';
	private const TOKEN_TTL       = 3000;
	private const SCOPES          = 'delivery-api.delivery delivery-api.delivery-point delivery-api.order delivery-api.posting delivery-api.return';
	private const TOKEN_URL       = 'https://xapi.ozon.ru/oauth/token';
	private const API_URL         = 'https://api-delivery.ozon.ru';
	private const REDIRECT_CODES  = array( 301, 302, 307, 308 );
	private const MAX_REDIRECTS   = 4;

	/**
	 * Настройки плагина с значениями по умолчанию.
	 *
	 * @return array<string, string>
	 */
	public static function settings(): array {
		$defaults = array(
			'client_id'         => '',
			'client_secret'     => '',
			'token_url'         => self::TOKEN_URL,
			'api_url'           => self::API_URL,
			'scope'             => self::SCOPES,
			'default_weight_g'  => '1000',
			'default_length_mm' => '220',
			'default_width_mm'  => '220',
			'default_height_mm' => '70',
			'markup_percent'    => '0',
			'map_key'           => '',
		);

		$saved = get_option( 'woo_ozon_settings', array() );

		$saved = is_array( $saved ) ? array_filter( $saved, 'is_scalar' ) : array();

		$settings = array_merge( $defaults, array_map( 'strval', $saved ) );

		// Адреса и права задаёт Ozon: значения из базы игнорируем, иначе старые настройки
		// уводят запросы на xapi.ozon.ru — это старый Rocket API, он отвечает 402.
		$settings['scope']     = self::SCOPES;
		$settings['token_url'] = self::TOKEN_URL;
		$settings['api_url']   = self::API_URL;

		return $settings;
	}


	/**
	 * Ключ JavaScript API Яндекс.Карт.
	 *
	 * Свои настройки в приоритете: у магазина без СДЭК брать ключ неоткуда.
	 */
	public static function map_key(): string {
		$settings = self::settings();

		if ( '' !== trim( (string) $settings['map_key'] ) ) {
			return trim( (string) $settings['map_key'] );
		}

		return trim( (string) get_option( 'club3d_yandex_key', '' ) );
	}

	/**
	 * Сбрасывает закэшированный токен (после смены ключей).
	 */
	public static function flush_token(): void {
		delete_transient( self::TOKEN_TRANSIENT );
	}

	/**
	 * Настроен ли плагин.
	 */
	public static function is_configured(): bool {
		$options = self::settings();

		return '' !== $options['client_id'] && '' !== $options['client_secret'];
	}

	/**
	 * Токен доступа. Живёт час, кэшируем на 50 минут.
	 *
	 * @throws Woo_Ozon_Error Если ключи не заданы или Ozon отказал.
	 */
	private static function token(): string {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$options = self::settings();
		if ( '' === $options['client_id'] || '' === $options['client_secret'] ) {
			throw new Woo_Ozon_Error( 'Ozon: не заданы Client ID и Client Secret' );
		}

		$response = wp_remote_post(
			$options['token_url'],
			array(
				'timeout'     => 30,
				'redirection' => 0,
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => wp_json_encode(
					array(
						'client_id'     => $options['client_id'],
						'client_secret' => $options['client_secret'],
						'grant_type'    => 'client_credentials',
						'scope'         => preg_split( '/[\s,]+/', (string) $options['scope'], -1, PREG_SPLIT_NO_EMPTY ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Woo_Ozon_Error( 'Ozon недоступен: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$message = is_array( $body ) && isset( $body['message'] ) ? (string) $body['message'] : '';
			throw new Woo_Ozon_Error( 'Ozon отказал в авторизации: ' . $code . ( $message ? ' ' . $message : '' ), $code, $body );
		}

		$token = is_array( $body ) && isset( $body['access_token'] ) ? (string) $body['access_token'] : '';
		if ( '' === $token ) {
			throw new Woo_Ozon_Error( 'Ozon не вернул токен', $code, $body );
		}

		// Ozon отдаёт в expires_in то секунды, то абсолютное время истечения.
		$expires = (int) ( $body['expires_in'] ?? 0 );
		$ttl     = $expires > 1000000000 ? $expires - time() : ( $expires > 0 ? $expires : self::TOKEN_TTL );
		$ttl     = max( 60, $ttl - 300 );

		set_transient( self::TOKEN_TRANSIENT, $token, $ttl );

		return $token;
	}

	/**
	 * POST-запрос с повторной отправкой после 307.
	 *
	 * @param string               $path Путь вида /v1/order/checkout.
	 * @param array<string, mixed> $payload Тело запроса.
	 * @param string               $idempotency_key Ключ идемпотентности для мутаций.
	 * @return array<string, mixed>
	 * @throws Woo_Ozon_Error При ошибке сети или ответе 4xx/5xx.
	 */
	public static function post( string $path, array $payload, string $idempotency_key = '' ): array {
		$options = self::settings();
		$url     = rtrim( $options['api_url'], '/' ) . $path;
		$token   = self::token();

		$headers = array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
		);

		if ( '' !== $idempotency_key ) {
			$headers['Idempotency-Key'] = $idempotency_key;
		}

		$cookie = '';
		$seen   = array();

		for ( $attempt = 0; $attempt <= self::MAX_REDIRECTS; $attempt++ ) {
			if ( isset( $seen[ $url ] ) ) {
				throw new Woo_Ozon_Error( 'Ozon: зациклились на редиректах' );
			}
			$seen[ $url ] = true;

			$request_headers = $headers;
			if ( '' !== $cookie ) {
				$request_headers['Cookie'] = $cookie;
			}

			$response = wp_remote_post(
				$url,
				array(
					'timeout'     => 40,
					'redirection' => 0,
					'headers'     => $request_headers,
					'body'        => wp_json_encode( $payload ),
				)
			);

			if ( is_wp_error( $response ) ) {
				throw new Woo_Ozon_Error( 'Ozon недоступен: ' . $response->get_error_message() );
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			// 429 и 5xx — временные: одна повторная попытка через паузу.
			if ( in_array( $code, array( 429, 500, 502, 503, 504 ), true ) && $attempt < 1 ) {
				sleep( 2 );
				continue;
			}

			if ( ! in_array( $code, self::REDIRECT_CODES, true ) ) {
				$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

				if ( $code >= 400 ) {
					$suffix = '';
					if ( is_array( $body ) ) {
						if ( isset( $body['error']['message'] ) ) {
							$suffix = ': ' . (string) $body['error']['message'];
						} elseif ( isset( $body['message'] ) ) {
							$suffix = ': ' . (string) $body['message'];
						}
					}

					throw new Woo_Ozon_Error( 'Ozon API: ' . $code . $suffix, $code, $body );
				}

				return is_array( $body ) ? $body : array();
			}

			$location = wp_remote_retrieve_header( $response, 'location' );
			if ( ! is_string( $location ) || '' === $location ) {
				throw new Woo_Ozon_Error( 'Ozon: редирект без Location', $code );
			}

			// Сохраняем cookie из ответа: именно она открывает следующий запрос.
			// Разбираем регуляркой, а не explode(','): в Expires тоже стоит запятая
			// («Expires=Wed, 21 Oct 2026»), и наивный разбор режет cookie пополам.
			$set_cookie = wp_remote_retrieve_header( $response, 'set-cookie' );
			if ( is_array( $set_cookie ) ) {
				$set_cookie = implode( "\n", $set_cookie );
			}
			if ( is_string( $set_cookie ) && '' !== $set_cookie ) {
				$pairs = array();
				if ( preg_match_all( '/(?:^|[\n,;]\s*)([A-Za-z0-9_\-]+)=([^;,\n]*)/', $set_cookie, $matches, PREG_SET_ORDER ) ) {
					foreach ( $matches as $match ) {
						$pairs[ $match[1] ] = $match[1] . '=' . $match[2];
					}
				}
				$cookie = implode( '; ', array_values( $pairs ) );
			}

			$url = self::resolve_url( $url, $location );
		}

		throw new Woo_Ozon_Error( 'Ozon: слишком много редиректов' );
	}

	/**
	 * Склеивает относительный Location с текущим адресом.
	 *
	 * @param string $base Текущий URL.
	 * @param string $location Значение заголовка Location.
	 */
	private static function resolve_url( string $base, string $location ): string {
		if ( preg_match( '#^https?://#i', $location ) ) {
			// В запросе едет Bearer-токен: на чужой хост по редиректу не идём.
			$base_host = wp_parse_url( $base, PHP_URL_HOST );
			$next_host = wp_parse_url( $location, PHP_URL_HOST );

			if ( $base_host && $next_host && strcasecmp( (string) $base_host, (string) $next_host ) !== 0 ) {
				throw new Woo_Ozon_Error( 'Ozon: редирект на посторонний хост отклонён' );
			}

			return $location;
		}

		$parts = wp_parse_url( $base );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return $location;
		}

		$scheme = $parts['scheme'] ?? 'https';
		$host   = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );

		return $scheme . '://' . $host . '/' . ltrim( $location, '/' );
	}

	/**
	 * Список пунктов выдачи (страница).
	 *
	 * @param string $cursor Курсор пагинации.
	 * @param int    $limit Размер страницы, 1..1000.
	 * @return array<string, mixed>
	 */
	public static function list_delivery_points( string $cursor = '', int $limit = 1000 ): array {
		return self::post(
			'/v1/delivery-point/list',
			array(
				'pagination' => array(
					'cursor' => '' !== $cursor ? $cursor : null,
					'limit'  => max( 1, min( $limit, 1000 ) ),
				),
			)
		);
	}

	/**
	 * Детали пунктов выдачи (не больше 100 за раз).
	 *
	 * @param int[] $ids Идентификаторы пунктов.
	 * @return array<int, array<string, mixed>>
	 */
	public static function delivery_point_info( array $ids ): array {
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$data = self::post( '/v1/delivery-point/info', array( 'delivery_point_ids' => array_slice( $ids, 0, 100 ) ) );

		return isset( $data['delivery_points'] ) && is_array( $data['delivery_points'] ) ? $data['delivery_points'] : array();
	}

	/**
	 * Можно ли доставить на этот номер.
	 *
	 * @param string $phone Номер телефона.
	 */
	public static function check_client( string $phone ): bool {
		$data = self::post( '/v1/delivery/check-client', array( 'phone_number' => $phone ) );

		return ! empty( $data['can_be_delivered'] );
	}

	/**
	 * Принимает ли пункт эту посылку.
	 *
	 * @param int[]                $point_ids Пункты.
	 * @param int                  $shipment_method_id Способ доставки.
	 * @param array<string, mixed> $posting Посылка.
	 * @return array<int, array<string, mixed>>
	 */
	public static function check_availability( array $point_ids, int $shipment_method_id, array $posting ): array {
		$data = self::post(
			'/v1/delivery-point/check-availability',
			array(
				'delivery_point_ids'  => array_values( array_map( 'absint', $point_ids ) ),
				'shipment_method_id'  => $shipment_method_id,
				'postings'            => array( $posting ),
			)
		);

		return isset( $data['results'] ) && is_array( $data['results'] ) ? $data['results'] : array();
	}

	/**
	 * Расчёт стоимости. Заявку не создаёт.
	 *
	 * @param string               $phone Телефон получателя.
	 * @param int                  $point_id Пункт выдачи.
	 * @param array<string, mixed> $posting Посылка.
	 * @return array<string, mixed>
	 */
	public static function checkout( string $phone, int $point_id, array $posting ): array {
		return self::post(
			'/v1/order/checkout',
			array(
				'recipient' => array( 'phone_number' => $phone ),
				'postings'  => array( $posting ),
				'delivery'  => array( 'delivery_point' => array( 'delivery_point_id' => $point_id ) ),
			)
		);
	}

	/**
	 * Создание заявки. Мутация, требует ключа идемпотентности.
	 *
	 * @param array<string, mixed> $payload Тело заявки.
	 * @param string               $idempotency_key Ключ идемпотентности.
	 * @return array<string, mixed>
	 */
	public static function create_order( array $payload, string $idempotency_key ): array {
		return self::post( '/v1/order/create', $payload, $idempotency_key );
	}

	/**
	 * Объект посылки для расчёта и создания заявки.
	 *
	 * @param int    $request_id Номер посылки в заявке.
	 * @param int    $shipment_method_id Способ доставки пункта.
	 * @param string $cutoff_at Время отсечки.
	 * @param string $declared_value Объявленная стоимость.
	 * @param int    $weight_g Вес, г.
	 * @param int    $length_mm Длина, мм.
	 * @param int    $width_mm Ширина, мм.
	 * @param int    $height_mm Высота, мм.
	 * @return array<string, mixed>
	 */
	public static function build_posting(
		int $request_id,
		int $shipment_method_id,
		string $declared_value,
		int $weight_g,
		int $length_mm,
		int $width_mm,
		int $height_mm
	): array {
		// cutoff_at не отправляем: Ozon возвращает свой в ответе на checkout, а строка
		// в чужом формате валит весь запрос («JSON value could not be converted»).
		return array(
			'request_id'        => $request_id,
			'shipment_method_id' => $shipment_method_id,
			'declared_value'    => array(
				'amount'        => $declared_value,
				'currency_code' => 'RUB',
			),
			'dimensions'        => array(
				'weight_g'   => $weight_g,
				'length_mm'  => $length_mm,
				'width_mm'   => $width_mm,
				'height_mm'  => $height_mm,
			),
		);
	}

	/**
	 * Время отсечки: завтра в полдень UTC.
	 */
	public static function default_cutoff(): string {
		return gmdate( 'Y-m-d\T12:00:00\Z', time() + DAY_IN_SECONDS );
	}
}
