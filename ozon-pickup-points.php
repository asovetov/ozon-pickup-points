<?php
/**
 * Plugin Name:       Delivery to Ozon Pickup Points for WooCommerce
 * Plugin URI:        https://github.com/asovetov/woo-ozon-delivery
 * Description:       Доставка в пункты выдачи Ozon для WooCommerce: кэш ПВЗ, стоимость от Ozon, создание заявки и статусы. Рассчитан на отправления юридическим лицам.
 * Version:           0.4.1
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            3D-Club
 * Author URI:        https://3d-club.ru
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       ozon-pickup-points
 * WC requires at least: 8.0
 * WC tested up to:   11.3
 * Requires Plugins:  woocommerce
 *
 * @package WooOzonDelivery
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'WOO_OZON_VERSION', '0.4.0' );
define( 'WOO_OZON_FILE', __FILE__ );
define( 'WOO_OZON_DIR', plugin_dir_path( __FILE__ ) );
define( 'WOO_OZON_URL', plugin_dir_url( __FILE__ ) );

require_once WOO_OZON_DIR . 'includes/class-ozon-api.php';
require_once WOO_OZON_DIR . 'includes/class-ozon-points.php';
require_once WOO_OZON_DIR . 'includes/class-ozon-quote.php';
require_once WOO_OZON_DIR . 'includes/class-ozon-checkout.php';
require_once WOO_OZON_DIR . 'includes/class-ozon-order.php';

/**
 * Поднимаем части плагина после загрузки WooCommerce.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		Woo_Ozon_Quote::init();
		Woo_Ozon_Checkout::init();
		Woo_Ozon_Order::init();
	}
);

/**
 * Объявляем совместимость с HPOS: заказы хранятся в своих таблицах WooCommerce.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WOO_OZON_FILE, true );
		}
	}
);

/**
 * Регистрируем метод доставки в WooCommerce.
 *
 * @param array<string, string> $methods Список методов.
 * @return array<string, string>
 */
function woo_ozon_register_shipping_method( array $methods ): array {
	require_once WOO_OZON_DIR . 'includes/class-ozon-shipping-method.php';

	$methods['woo_ozon'] = 'Woo_Ozon_Shipping_Method';

	return $methods;
}
add_filter( 'woocommerce_shipping_methods', 'woo_ozon_register_shipping_method' );

/**
 * Настройки плагина: доступы к API и параметры по умолчанию.
 */
add_action(
	'admin_menu',
	static function (): void {
		add_submenu_page(
			'woocommerce',
			__( 'Ozon Delivery', 'ozon-pickup-points' ),
			__( 'Ozon Delivery', 'ozon-pickup-points' ),
			'manage_woocommerce',
			'ozon-pickup-points',
			'woo_ozon_render_settings_page'
		);
	}
);


/**
 * Сохраняет настройки и возвращает на страницу: иначе F5 отправлял бы форму повторно.
 */
add_action(
	'admin_init',
	static function (): void {
		if ( ! isset( $_POST['woo_ozon_nonce'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$nonce = sanitize_text_field( wp_unslash( (string) $_POST['woo_ozon_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'woo_ozon_save_settings' ) ) {
			return;
		}

		$keys = array(
			'client_id',
			'client_secret',
			'token_url',
			'api_url',
			'scope',
			'default_weight_g',
			'default_length_mm',
			'default_width_mm',
			'default_height_mm',
			'markup_percent',
		);

		$numeric = array( 'default_weight_g', 'default_length_mm', 'default_width_mm', 'default_height_mm', 'markup_percent' );
		$saved   = array();

		foreach ( $keys as $key ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- санитизация ниже: ссылки через esc_url_raw, остальное через sanitize_text_field.
			$raw = isset( $_POST[ $key ] ) ? wp_unslash( (string) $_POST[ $key ] ) : '';

			if ( in_array( $key, $numeric, true ) ) {
				$saved[ $key ] = (string) max( 0, (int) $raw );
				continue;
			}

			// Адреса API проверяем как ссылки, остальное — как обычный текст.
			$saved[ $key ] = in_array( $key, array( 'token_url', 'api_url' ), true )
				? esc_url_raw( $raw )
				: sanitize_text_field( $raw );
		}

		update_option( 'woo_ozon_settings', $saved );
		Woo_Ozon_Api::flush_token();

		wp_safe_redirect( add_query_arg( 'woo-ozon-saved', '1', wp_get_referer() ?: admin_url( 'admin.php?page=woo-ozon-delivery' ) ) );
		exit;
	}
);

/**
 * Страница настроек.
 */
function woo_ozon_render_settings_page(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'Недостаточно прав.', 'ozon-pickup-points' ) );
	}

	$fields = array(
		'client_id'         => __( 'Client ID', 'ozon-pickup-points' ),
		'client_secret'     => __( 'Client Secret', 'ozon-pickup-points' ),
		'token_url'         => __( 'URL токена', 'ozon-pickup-points' ),
		'api_url'           => __( 'URL API', 'ozon-pickup-points' ),
		'scope'             => __( 'Scope', 'ozon-pickup-points' ),
		'default_weight_g'  => __( 'Вес по умолчанию, г', 'ozon-pickup-points' ),
		'default_length_mm' => __( 'Длина по умолчанию, мм', 'ozon-pickup-points' ),
		'default_width_mm'  => __( 'Ширина по умолчанию, мм', 'ozon-pickup-points' ),
		'default_height_mm' => __( 'Высота по умолчанию, мм', 'ozon-pickup-points' ),
		'markup_percent'    => __( 'Наценка на доставку, %', 'ozon-pickup-points' ),
	);

	$nonce_action = 'woo_ozon_save_settings';

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- только флаг для показа уведомления, данных не меняет.
	if ( isset( $_GET['woo-ozon-saved'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Настройки сохранены.', 'ozon-pickup-points' ) . '</p></div>';
	}

	$options = Woo_Ozon_Api::settings();
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Ozon Delivery', 'ozon-pickup-points' ); ?></h1>
		<p>
			<?php
			echo esc_html__(
				'Ключи выдаёт Ozon в личном кабинете продавца: Настройки → API ключи. Без ключей расчёт и создание заявок не работают, но пункты выдачи можно держать в кэше.',
				'ozon-pickup-points'
			);
			?>
		</p>
		<p><?php esc_html_e( 'Куда идти за ключами и настройками доставки:', 'ozon-pickup-points' ); ?></p>
		<ul class="ul-disc">
			<li>
				<a href="https://seller.ozon.ru/app/settings/api-keys" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Кабинет продавца → API ключи', 'ozon-pickup-points' ); ?>
				</a>
				— Client ID и Client Secret, вкладка Seller API
			</li>
			<li>
				<a href="https://seller.ozon.ru/" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Кабинет продавца', 'ozon-pickup-points' ); ?>
				</a>
				— раздел FBS → Управление логистикой: склады и методы доставки
			</li>
			<li>
				<a href="https://seller-edu.ozon.ru/libra/rfbs/logistics-settings" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Справка Ozon: склады и методы доставки', 'ozon-pickup-points' ); ?>
				</a>
			</li>
			<li>
				<a href="https://docs.ozon.ru/api/seller/" target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Документация Seller API', 'ozon-pickup-points' ); ?>
				</a>
			</li>
		</ul>
		<form method="post">
			<?php wp_nonce_field( $nonce_action, 'woo_ozon_nonce' ); ?>
			<table class="form-table" role="presentation">
				<?php foreach ( $fields as $key => $label ) : ?>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
						<td>
							<input
								class="regular-text"
								id="<?php echo esc_attr( $key ); ?>"
								name="<?php echo esc_attr( $key ); ?>"
								type="<?php echo 'client_secret' === $key ? 'password' : 'text'; ?>"
								value="<?php echo esc_attr( (string) ( $options[ $key ] ?? '' ) ); ?>"
								autocomplete="off"
							>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<?php submit_button( __( 'Сохранить', 'ozon-pickup-points' ) ); ?>
		</form>

		<h2><?php echo esc_html__( 'Пункты выдачи', 'ozon-pickup-points' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: %d — количество пунктов в кэше */
				esc_html__( 'В кэше: %d пунктов.', 'ozon-pickup-points' ),
				(int) Woo_Ozon_Points::count()
			);
			?>
		</p>
		<form method="post">
			<?php wp_nonce_field( 'woo_ozon_refresh_points', 'woo_ozon_points_nonce' ); ?>
			<input type="hidden" name="woo_ozon_refresh_points" value="1">
			<?php submit_button( __( 'Обновить пункты выдачи', 'ozon-pickup-points' ), 'secondary' ); ?>
		</form>
	</div>
	<?php
}

/**
 * Обновление кэша пунктов выдачи по кнопке.
 */
add_action(
	'admin_init',
	static function (): void {
		if ( empty( $_POST['woo_ozon_refresh_points'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$nonce = isset( $_POST['woo_ozon_points_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['woo_ozon_points_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'woo_ozon_refresh_points' ) ) {
			return;
		}

		$result = Woo_Ozon_Points::refresh();

		add_action(
			'admin_notices',
			static function () use ( $result ): void {
				$class = $result['ok'] ? 'notice-success' : 'notice-error';
				printf(
					'<div class="notice %s"><p>%s</p></div>',
					esc_attr( $class ),
					esc_html( $result['message'] )
				);
			}
		);
	}
);

/**
 * Своё расписание крона: раз в 30 минут.
 *
 * @param array<string, array<string, mixed>> $schedules Расписания.
 * @return array<string, array<string, mixed>>
 */
function woo_ozon_cron_schedules( array $schedules ): array {
	$schedules['woo_ozon_half_hour'] = array(
		'interval' => 1800,
		'display'  => __( 'Каждые 30 минут', 'ozon-pickup-points' ),
	);

	return $schedules;
}
add_filter( 'cron_schedules', 'woo_ozon_cron_schedules' );

/**
 * Страховка: если расписание потерялось (смена темы, импорт базы), заводим заново.
 */
add_action(
	'init',
	static function (): void {
		if ( ! wp_next_scheduled( Woo_Ozon_Order::CRON_HOOK ) ) {
			wp_schedule_event( time() + 600, 'woo_ozon_half_hour', Woo_Ozon_Order::CRON_HOOK );
		}
	}
);

/**
 * Таблица кэша пунктов выдачи и расписание синхронизации.
 */
register_activation_hook(
	WOO_OZON_FILE,
	static function (): void {
		Woo_Ozon_Points::install_table();

		if ( ! wp_next_scheduled( Woo_Ozon_Order::CRON_HOOK ) ) {
			wp_schedule_event( time() + 600, 'woo_ozon_half_hour', Woo_Ozon_Order::CRON_HOOK );
		}
	}
);

/**
 * Снимаем расписание при отключении.
 */
register_deactivation_hook(
	WOO_OZON_FILE,
	static function (): void {
		$timestamp = wp_next_scheduled( Woo_Ozon_Order::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, Woo_Ozon_Order::CRON_HOOK );
		}
	}
);

/**
 * Сообщение об ошибке создания заявки в карточке заказа.
 */
add_action(
	'admin_notices',
	static function (): void {
		$screen = get_current_screen();
		if ( ! $screen || 'shop_order' !== $screen->id && 'woocommerce_page_wc-orders' !== $screen->id ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- только чтение id заказа для кнопки, действие защищено своим nonce.
		$order_id = isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0;
		if ( $order_id <= 0 ) {
			return;
		}

		$error = get_transient( 'woo_ozon_error_' . $order_id );
		if ( ! is_string( $error ) || '' === $error ) {
			return;
		}

		delete_transient( 'woo_ozon_error_' . $order_id );

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html( 'Ozon: ' . $error )
		);
	}
);
