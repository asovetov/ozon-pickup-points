<?php
/**
 * Plugin Name:       Ozon Delivery for WooCommerce
 * Plugin URI:        https://github.com/asovetov/woo-ozon-delivery
 * Description:       Доставка Ozon для WooCommerce: пункты выдачи, расчёт стоимости и создание заявки через Ozon Delivery API.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            3D-Club
 * Author URI:        https://3d-club.ru
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       woo-ozon-delivery
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   9.5
 *
 * @package WooOzonDelivery
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'WOO_OZON_VERSION', '0.1.0' );
define( 'WOO_OZON_FILE', __FILE__ );
define( 'WOO_OZON_DIR', plugin_dir_path( __FILE__ ) );
define( 'WOO_OZON_URL', plugin_dir_url( __FILE__ ) );

require_once WOO_OZON_DIR . 'includes/class-ozon-api.php';
require_once WOO_OZON_DIR . 'includes/class-ozon-points.php';

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
 * Загружаем переводы.
 */
add_action(
	'init',
	static function (): void {
		load_plugin_textdomain( 'woo-ozon-delivery', false, dirname( plugin_basename( WOO_OZON_FILE ) ) . '/languages' );
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
			__( 'Ozon Delivery', 'woo-ozon-delivery' ),
			__( 'Ozon Delivery', 'woo-ozon-delivery' ),
			'manage_woocommerce',
			'woo-ozon-delivery',
			'woo_ozon_render_settings_page'
		);
	}
);

/**
 * Страница настроек.
 */
function woo_ozon_render_settings_page(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'Недостаточно прав.', 'woo-ozon-delivery' ) );
	}

	$fields = array(
		'client_id'         => __( 'Client ID', 'woo-ozon-delivery' ),
		'client_secret'     => __( 'Client Secret', 'woo-ozon-delivery' ),
		'token_url'         => __( 'URL токена', 'woo-ozon-delivery' ),
		'api_url'           => __( 'URL API', 'woo-ozon-delivery' ),
		'scope'             => __( 'Scope', 'woo-ozon-delivery' ),
		'default_weight_g'  => __( 'Вес по умолчанию, г', 'woo-ozon-delivery' ),
		'default_length_mm' => __( 'Длина по умолчанию, мм', 'woo-ozon-delivery' ),
		'default_width_mm'  => __( 'Ширина по умолчанию, мм', 'woo-ozon-delivery' ),
		'default_height_mm' => __( 'Высота по умолчанию, мм', 'woo-ozon-delivery' ),
		'markup_percent'    => __( 'Наценка на доставку, %', 'woo-ozon-delivery' ),
	);

	$nonce_action = 'woo_ozon_save_settings';

	if ( isset( $_POST['woo_ozon_nonce'] ) ) {
		$nonce = sanitize_text_field( wp_unslash( (string) $_POST['woo_ozon_nonce'] ) );

		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			wp_die( esc_html__( 'Проверка безопасности не пройдена.', 'woo-ozon-delivery' ) );
		}

		$saved = array();
		foreach ( array_keys( $fields ) as $key ) {
			$raw = isset( $_POST[ $key ] ) ? wp_unslash( (string) $_POST[ $key ] ) : '';

			$saved[ $key ] = in_array( $key, array( 'default_weight_g', 'default_length_mm', 'default_width_mm', 'default_height_mm', 'markup_percent' ), true )
				? (string) max( 0, (int) $raw )
				: sanitize_text_field( $raw );
		}

		update_option( 'woo_ozon_settings', $saved );
		Woo_Ozon_Api::flush_token();

		echo '<div class="notice notice-success"><p>' . esc_html__( 'Настройки сохранены.', 'woo-ozon-delivery' ) . '</p></div>';
	}

	$options = Woo_Ozon_Api::settings();
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Ozon Delivery', 'woo-ozon-delivery' ); ?></h1>
		<p>
			<?php
			echo esc_html__(
				'Ключи выдаёт Ozon в личном кабинете продавца: Настройки → API. Без ключей расчёт и создание заявок не работают, но пункты выдачи можно держать в кэше.',
				'woo-ozon-delivery'
			);
			?>
		</p>
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
			<?php submit_button( __( 'Сохранить', 'woo-ozon-delivery' ) ); ?>
		</form>

		<h2><?php echo esc_html__( 'Пункты выдачи', 'woo-ozon-delivery' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: %d — количество пунктов в кэше */
				esc_html__( 'В кэше: %d пунктов.', 'woo-ozon-delivery' ),
				(int) Woo_Ozon_Points::count()
			);
			?>
		</p>
		<form method="post">
			<?php wp_nonce_field( 'woo_ozon_refresh_points', 'woo_ozon_points_nonce' ); ?>
			<input type="hidden" name="woo_ozon_refresh_points" value="1">
			<?php submit_button( __( 'Обновить пункты выдачи', 'woo-ozon-delivery' ), 'secondary' ); ?>
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
 * Таблица кэша пунктов выдачи.
 */
register_activation_hook(
	WOO_OZON_FILE,
	static function (): void {
		Woo_Ozon_Points::install_table();
	}
);
