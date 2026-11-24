<?php
/**
 * Закладка «Координаты» в настройках плагина.
 *
 * Ozon отдаёт в API смещённые координаты части пунктов, а правильные лежат
 * только в Яндекс.Картах. Серверные ключи Яндекса режутся по реферу, поэтому
 * геокодируем в браузере: администратор открывает закладку, скрипт прогоняет
 * пункты пачками и складывает координаты в базу.
 *
 * @package Ozon_Pickup_Points
 */

defined( 'ABSPATH' ) || exit;

/**
 * Экран прогрева координат.
 */
class Woo_Ozon_Geo_Admin {

	/**
	 * Сколько пунктов обрабатывать за один запуск.
	 */
	private const BATCH = 200;

	/**
	 * Подключает хуки.
	 */
	public static function init(): void {
		add_action( 'wp_ajax_woo_ozon_geo_cities', array( __CLASS__, 'ajax_cities' ) );
		add_action( 'wp_ajax_woo_ozon_geo_points', array( __CLASS__, 'ajax_points' ) );
		add_action( 'wp_ajax_woo_ozon_geo_reset', array( __CLASS__, 'ajax_reset' ) );
		add_action( 'admin_post_woo_ozon_geo_csv', array( __CLASS__, 'download_csv' ) );
	}

	/**
	 * Проверка прав для AJAX и выгрузки.
	 */
	private static function guard(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'ozon-pickup-points' ) ), 403 );
		}
	}

	/**
	 * Города, где остались пункты без поправки.
	 */
	public static function ajax_cities(): void {
		self::guard();
		check_ajax_referer( 'woo_ozon_geo', 'nonce' );

		wp_send_json_success( array( 'cities' => Woo_Ozon_Points::geo_cities( 50 ) ) );
	}

	/**
	 * Пункты города без поправки.
	 */
	public static function ajax_points(): void {
		self::guard();
		check_ajax_referer( 'woo_ozon_geo', 'nonce' );

		$city = isset( $_GET['city'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['city'] ) ) : '';

		if ( '' === $city ) {
			wp_send_json_error( array( 'message' => __( 'Город не указан.', 'ozon-pickup-points' ) ) );
		}

		wp_send_json_success( array( 'points' => Woo_Ozon_Points::geo_pending( $city, 40 ) ) );
	}


	/**
	 * Сбрасывает поправки одного города.
	 */
	public static function ajax_reset(): void {
		self::guard();
		check_ajax_referer( 'woo_ozon_geo', 'nonce' );

		$city = isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['city'] ) ) : '';

		if ( '' === $city ) {
			wp_send_json_error( array( 'message' => __( 'Город не указан.', 'ozon-pickup-points' ) ) );
		}

		wp_send_json_success( array( 'reset' => Woo_Ozon_Points::geo_reset_city( $city ) ) );
	}

	/**
	 * Выгрузка поправок в CSV.
	 */
	public static function download_csv(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'ozon-pickup-points' ) );
		}

		check_admin_referer( 'woo_ozon_geo_csv' );

		$rows = Woo_Ozon_Points::geo_export();

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ozon-points-coords.csv"' );

		$out = fopen( 'php://output', 'w' );

		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( 'point_id', 'lat', 'lng', 'address' ) );

		foreach ( $rows as $row ) {
			fputcsv( $out, array( $row['point_id'], $row['geo_lat'], $row['geo_lng'], $row['address'] ) );
		}

		fclose( $out );
		exit;
	}

	/**
	 * Разметка закладки.
	 */
	public static function render(): void {
		$stats  = Woo_Ozon_Points::geo_stats();
		$recent = Woo_Ozon_Points::geo_recent( 10 );
		?>
		<h2><?php echo esc_html__( 'Координаты пунктов', 'ozon-pickup-points' ); ?></h2>

		<p style="max-width:820px">
			<?php echo esc_html__( 'Ozon отдаёт в API координаты пунктов выдачи, но у части из них они смещены — иногда на пару сотен метров. В приложении Ozon тот же пункт стоит на своём месте, а в API приходит старая или приблизительная точка, и забрать правильную через API нельзя.', 'ozon-pickup-points' ); ?>
		</p>
		<p style="max-width:820px">
			<?php echo esc_html__( 'Поэтому плагин уточняет точки сам: адрес пункта прогоняется через геокодер Яндекс.Карт, и на карте метка встаёт по дому, а не у соседнего здания. Геокодирование идёт из браузера администратора — серверные запросы Яндекс отклоняет по ключу.', 'ozon-pickup-points' ); ?>
		</p>
		<p style="max-width:820px">
			<?php echo esc_html__( 'Прогрев запускает человек, потому что в справочнике около 96 тысяч пунктов, а у геокодера есть дневной лимит запросов. Скрипт обрабатывает очередную пачку и останавливается: страницу можно закрыть и продолжить завтра, уже сохранённое повторно не запрашивается.', 'ozon-pickup-points' ); ?>
		</p>

		<table class="widefat striped" style="max-width:520px;margin:16px 0">
			<tbody>
				<tr>
					<td><?php echo esc_html__( 'Всего пунктов', 'ozon-pickup-points' ); ?></td>
					<td><strong><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></strong></td>
				</tr>
				<tr>
					<td><?php echo esc_html__( 'Координаты уточнены', 'ozon-pickup-points' ); ?></td>
					<td><strong><?php echo esc_html( number_format_i18n( $stats['done'] ) ); ?></strong></td>
				</tr>
				<tr>
					<td><?php echo esc_html__( 'Осталось', 'ozon-pickup-points' ); ?></td>
					<td><strong><?php echo esc_html( number_format_i18n( $stats['left'] ) ); ?></strong></td>
				</tr>
			</tbody>
		</table>

		<p>
			<button type="button" class="button button-primary" id="ozon-geo-run"<?php echo $stats['left'] > 0 ? '' : ' disabled'; ?>>
				<?php echo esc_html__( 'Прогреть координаты', 'ozon-pickup-points' ); ?>
			</button>

			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=woo_ozon_geo_csv' ), 'woo_ozon_geo_csv' ) ); ?>">
				<?php echo esc_html__( 'Скачать CSV поправок', 'ozon-pickup-points' ); ?>
			</a>

			<span id="ozon-geo-status" style="margin-left:12px;color:#646970"></span>
		</p>

		<p>
			<input type="text" id="ozon-geo-city" class="regular-text" style="width:220px" placeholder="<?php echo esc_attr__( 'Город', 'ozon-pickup-points' ); ?>">
			<button type="button" class="button" id="ozon-geo-reset">
				<?php echo esc_html__( 'Перегеокодить город', 'ozon-pickup-points' ); ?>
			</button>
			<span class="description">
				<?php echo esc_html__( 'Сбрасывает поправки города, чтобы скрипт собрал их заново — на случай, если адреса в справочнике обновились.', 'ozon-pickup-points' ); ?>
			</span>
		</p>

		<p class="description" style="max-width:820px">
			<?php echo esc_html__( 'Если в адресе пункта нет корпуса, геокодер поставит точку на дом целиком. Для выбора пункта этого достаточно; точность «до входа» даёт только поиск по организациям, а он требует серверного ключа Яндекса.', 'ozon-pickup-points' ); ?>
		</p>

		<h3><?php echo esc_html__( 'Последние уточнённые', 'ozon-pickup-points' ); ?></h3>

		<table class="widefat striped" style="max-width:1000px">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Пункт', 'ozon-pickup-points' ); ?></th>
					<th><?php echo esc_html__( 'Адрес', 'ozon-pickup-points' ); ?></th>
					<th><?php echo esc_html__( 'Координаты', 'ozon-pickup-points' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $recent ) ) : ?>
					<tr><td colspan="3"><?php echo esc_html__( 'Пока ничего не уточнено.', 'ozon-pickup-points' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $recent as $row ) : ?>
						<tr>
							<td><?php echo esc_html( (string) $row['point_id'] ); ?></td>
							<td><?php echo esc_html( (string) $row['address'] ); ?></td>
							<td><code><?php echo esc_html( $row['geo_lat'] . ', ' . $row['geo_lng'] ); ?></code></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<script>
		(function () {
			var runBtn = document.getElementById('ozon-geo-run');
			var status = document.getElementById('ozon-geo-status');
			var canvas = null;

			if (!runBtn) { return; }

			var done = 0;
			var limit = <?php echo (int) self::BATCH; ?>;
			var ajax = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var nonce = <?php echo wp_json_encode( wp_create_nonce( 'woo_ozon_geo' ) ); ?>;
			var key = <?php echo wp_json_encode( Woo_Ozon_Api::map_key() ); ?>;

			function say(text) { status.textContent = text; }

			function api() {
				if (window.ymaps) { return Promise.resolve(); }

				return new Promise(function (resolve, reject) {
					var script = document.createElement('script');
					script.src = 'https://api-maps.yandex.ru/2.1/?apikey=' + encodeURIComponent(key) + '&lang=ru_RU';
					script.onload = function () { window.ymaps.ready(resolve); };
					script.onerror = reject;
					document.head.appendChild(script);
				});
			}

			function get(url) {
				return fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
			}

			function geocode(address) {
				return window.ymaps.geocode(address, { results: 1 }).then(function (result) {
					var first = result.geoObjects.get(0);
					if (!first) { return null; }
					var coords = first.geometry.getCoordinates();
					return { lat: coords[0], lng: coords[1] };
				}).catch(function () { return null; });
			}

			function save(points) {
				var body = new FormData();
				body.append('action', 'woo_ozon_save_geo');
				body.append('nonce', <?php echo wp_json_encode( wp_create_nonce( 'woo_ozon_points' ) ); ?>);
				body.append('points', JSON.stringify(points));
				return fetch(ajax, { method: 'POST', credentials: 'same-origin', body: body });
			}

			function city() {
				return get(ajax + '?action=woo_ozon_geo_cities&nonce=' + encodeURIComponent(nonce))
					.then(function (json) {
						var cities = (json && json.data && json.data.cities) || [];
						return cities.length ? cities[0].city : '';
					});
			}

			function points(name) {
				return get(ajax + '?action=woo_ozon_geo_points&nonce=' + encodeURIComponent(nonce) + '&city=' + encodeURIComponent(name))
					.then(function (json) { return (json && json.data && json.data.points) || []; });
			}

			function step() {
				if (done >= limit) {
					say('Пачка обработана: ' + done + '. Продолжите позже.');
					runBtn.disabled = false;
					return;
				}

				city().then(function (name) {
					if (!name) {
						say('Готово: неразобранных городов не осталось.');
						runBtn.disabled = true;
						return;
					}

					points(name).then(function (list) {
						if (!list.length) {
							// Город без пунктов в выборке: пропускаем, чтобы не зациклиться.
							say('Город ' + name + ': пунктов нет, дальше.');
							done += 1;
							step();
							return;
						}

						var saved = [];
						var index = 0;

						function next() {
							if (index >= list.length) {
								if (saved.length) {
									save(saved).then(function () {
										done += saved.length;
										say('Уточнено ' + done + ' из ' + limit + ' (город ' + name + ')');
										step();
									});
									return;
								}
								step();
								return;
							}

							var point = list[ index++ ];
							geocode(point.address).then(function (coords) {
								if (coords) {
									saved.push({ id: point.id, lat: coords.lat, lng: coords.lng });
								}
								next();
							});
						}

						next();
					});
				});
			}

			var resetBtn = document.getElementById('ozon-geo-reset');
			var cityInput = document.getElementById('ozon-geo-city');

			if (resetBtn && cityInput) {
				resetBtn.addEventListener('click', function () {
					var name = (cityInput.value || '').trim();

					if (!name) {
						say('Укажите город.');
						return;
					}

					if (!window.confirm('Сбросить поправки города «' + name + '»?')) {
						return;
					}

					var body = new FormData();
					body.append('action', 'woo_ozon_geo_reset');
					body.append('nonce', nonce);
					body.append('city', name);

					fetch(ajax, { method: 'POST', credentials: 'same-origin', body: body })
						.then(function (r) { return r.json(); })
						.then(function (json) {
							var count = (json && json.data && json.data.reset) || 0;
							say('Сброшено пунктов: ' + count + '. Обновите страницу и запустите прогрев.');
						});
				});
			}

			runBtn.addEventListener('click', function () {
				runBtn.disabled = true;
				done = 0;
				say('Загружаем карту…');
				api().then(function () { step(); }).catch(function () {
					say('Карта не загрузилась. Проверьте ключ в настройках.');
					runBtn.disabled = false;
				});
			});
		})();
		</script>
		<?php
	}
}
