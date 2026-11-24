/**
 * Выбор пункта выдачи Ozon: кнопка в строке тарифа и карта Яндекс.
 *
 * Список адресов не рисуем — на карте есть поиск, по нему покупатель находит
 * свою улицу и видит пункты рядом. Кэш пунктов живёт в таблице плагина.
 */
(function ($) {
	'use strict';

	function field(id) {
		return document.getElementById(id);
	}

	function setStatus(text, isError) {
		var box = field('woo-ozon-quote-status');

		if (!box) {
			return;
		}

		box.textContent = text || '';
		box.classList.toggle('woo-ozon-quote--error', !!isError);
	}

	/* Ozon считает доставку для конкретного пункта и телефона. Спрашиваем цену сразу
	   после выбора пункта и обновляем итоги оформления. */
	function quote(pointId) {
		if (!pointId || !window.WooOzon || !WooOzon.quoteNonce) {
			return;
		}

		var phone = field('billing_phone');
		if (!phone || !phone.value.trim()) {
			setStatus(WooOzon.i18n.needPhone);
			return;
		}

		setStatus(WooOzon.i18n.quoting);

		var body = new FormData();
		body.append('action', 'woo_ozon_quote');
		body.append('nonce', WooOzon.quoteNonce);
		body.append('point_id', pointId);
		body.append('phone', phone.value.trim());

		fetch(WooOzon.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (response) { return response.json(); })
			.then(function (json) {
				if (json && json.success && json.data) {
					setStatus(WooOzon.i18n.quote.replace('%s', json.data.formatted));
					if (window.jQuery) {
						$(document.body).trigger('update_checkout');
					}
					return;
				}

				var data = (json && json.data) || {};
				var reason = data.message || WooOzon.i18n.error;

				// Отказ по телефону Ozon объясняет своими словами — префикс тут только мешает.
				setStatus('phone' === data.code ? reason : WooOzon.i18n.quoteFail.replace('%s', reason), true);
			})
			.catch(function () {
				setStatus(WooOzon.i18n.quoteFail.replace('%s', WooOzon.i18n.error), true);
			});
	}

	function setPoint(id, label) {
		var idField = field('woo-ozon-point-id');
		var labelField = field('woo-ozon-point-label');
		var chosen = field('woo-ozon-chosen');

		if (!idField) {
			return;
		}

		idField.value = id ? String(id) : '';
		if (labelField) {
			labelField.value = label || '';
		}

		if (chosen) {
			chosen.textContent = label || '';
			if (label) {
				chosen.removeAttribute('hidden');
			} else {
				chosen.setAttribute('hidden', 'hidden');
			}
		}

		// Запоминаем город, в котором выбран пункт: при смене города пункт сбрасываем.
		var cityField = field('woo-ozon-point-city');
		if (cityField) {
			cityField.value = label ? cityValue() : '';
		}

		if (window.jQuery) {
			$(document.body).trigger('update_checkout');
		}

		quote(id);
	}

	/* --- карта пунктов --- */
	/*
	 * Яндекс.Карты, а не OpenStreetMap: OSM рисует Крым украинским, для российского
	 * магазина это недопустимо. Ключ берём из настроек СДЭК, он там уже есть.
	 */
	var mapState = {
		map: null,
		clusterer: null,
		api: null,
		city: ''
	};

	function cityValue() {
		var cityField = field('billing_city');
		if (cityField && cityField.value.trim()) {
			return cityField.value.trim();
		}
		var hidden = field('woo-ozon-city');
		return hidden ? hidden.value.trim() : '';
	}

	function loadApi() {
		if (window.ymaps) {
			return Promise.resolve();
		}
		if (mapState.api) {
			return mapState.api;
		}

		mapState.api = new Promise(function (resolve, reject) {
			var script = document.createElement('script');
			script.src = 'https://api-maps.yandex.ru/2.1/?apikey=' + encodeURIComponent(WooOzon.mapKey || '') + '&lang=ru_RU';
			script.onload = function () { window.ymaps.ready(resolve); };
			script.onerror = function () { reject(new Error('map api')); };
			document.head.appendChild(script);
		});

		return mapState.api;
	}

	function fetchPoints(city) {
		var url = WooOzon.ajaxUrl
			+ '?action=woo_ozon_points&map=1&nonce=' + encodeURIComponent(WooOzon.nonce)
			+ '&city=' + encodeURIComponent(city);

		return fetch(url, { credentials: 'same-origin' })
			.then(function (response) { return response.json(); })
			.then(function (json) { return (json && json.data && json.data.points) || []; });
	}

	function draw(points, modal) {
		if (mapState.clusterer) {
			mapState.map.geoObjects.remove(mapState.clusterer);
		}

		mapState.clusterer = new window.ymaps.Clusterer({ preset: 'islands#blueClusterIcons', groupByCoordinates: false });

		var marks = points.map(function (point) {
			var placemark = new window.ymaps.Placemark(
				[point.lat, point.lng],
				{
					hintContent: point.name,
					balloonContent: '<strong>' + point.name + '</strong><br>' + point.address
				},
				{ preset: 'islands#blueIcon' }
			);

			placemark.events.add('click', function () {
				setPoint(point.id, point.name + ', ' + point.address);
				modal.setAttribute('hidden', 'hidden');
			});

			return placemark;
		});

		mapState.clusterer.add(marks);
		mapState.map.geoObjects.add(mapState.clusterer);

		if (points.length) {
			mapState.map.setCenter([points[0].lat, points[0].lng], 11, { duration: 200 });
		}

		// Точка расширения: плагины сайта могут дорисовать точки по-своему.
		mapState.redraw = function (next) { draw(next, modal); };

		document.dispatchEvent(new CustomEvent('woo-ozon:map-drawn', {
			detail: { points: points, redraw: mapState.redraw }
		}));
	}


	function show(city, modal) {
		var cityLabel = field('woo-ozon-map-city');

		mapState.city = city;
		if (cityLabel) {
			cityLabel.textContent = city || WooOzon.i18n.mapNoCity;
		}

		loadApi()
			.then(function () {
				if (!mapState.map) {
					mapState.map = new window.ymaps.Map('woo-ozon-map-canvas', {
						center: [55.751244, 37.618423],
						zoom: 10,
						controls: ['zoomControl', 'searchControl', 'geolocationControl']
					});
				} else {
					// Контейнер могли перерисовать: сообщаем карте новые размеры.
					mapState.map.container.fitToViewport();
				}
				return fetchPoints(city);
			})
			.then(function (points) {
				draw(points, modal);
				if (!points.length) {
					window.ymaps.geocode(city).then(function (result) {
						var first = result.geoObjects.get(0);
						if (first) {
							mapState.map.setCenter(first.geometry.getCoordinates(), 11);
						}
					});
				}
			})
			.catch(function () {
				if (cityLabel) {
					cityLabel.textContent = WooOzon.i18n.mapFail;
				}
			});
	}

	function initMap() {
		var openBtn = field('woo-ozon-map-open');

		if (!openBtn) {
			return;
		}

		// Окно живёт в body: WooCommerce перерисовывает строку тарифа по AJAX и плодит
		// копии. Оставляем ровно один экземпляр, иначе карта рисуется в скрытой копии
		// и выглядит пустым листом.
		var modals = document.querySelectorAll('#woo-ozon-map-modal');
		var modal = null;

		modals.forEach(function (node) {
			if (!modal && node.parentNode === document.body) {
				modal = node;
			}
		});

		if (!modal && modals.length) {
			modal = modals[0];
			document.body.appendChild(modal);
		}

		modals.forEach(function (node) {
			if (node !== modal) {
				node.remove();
			}
		});

		var closeBtn = modal ? modal.querySelector('#woo-ozon-map-close') : null;

		if (!modal || openBtn.dataset.bound === '1') {
			return;
		}

		openBtn.dataset.bound = '1';

		openBtn.addEventListener('click', function (event) {
			event.preventDefault();
			modal.removeAttribute('hidden');
			// Карта в скрытом контейнере получает нулевой размер, поэтому даём браузеру
			// пересчитать разметку. requestAnimationFrame в фоновой вкладке не срабатывает
			// вовсе, поэтому таймер, а не кадр.
			window.setTimeout(function () {
				show(cityValue(), modal);
			}, 50);
		});

		if (closeBtn) {
			closeBtn.addEventListener('click', function () {
				modal.setAttribute('hidden', 'hidden');
			});
		}

		/* Город поменяли — карта и пункты переезжают за ним. */
		document.addEventListener('change', function (event) {
			var target = event.target;
			if (!target || target.id !== 'billing_city') {
				return;
			}
			var city = cityValue();
			if (!modal.hasAttribute('hidden') && city && city !== mapState.city) {
				show(city, modal);
			}
		});
	}

	var lastQuotedTotal = null;

	function subtotal() {
		var el = document.querySelector('.cart-subtotal .amount, .woocommerce-checkout-review-order-table .cart-subtotal td');
		return el ? el.textContent.replace(/\s+/g, ' ').trim() : '';
	}

	function quoteIfNeeded() {
		var chosen = field('woo-ozon-point-id');
		if (!chosen || !chosen.value) {
			return;
		}

		var current = subtotal();
		if (current && current === lastQuotedTotal) {
			return;
		}

		lastQuotedTotal = current;
		quote(chosen.value);
	}

	/* Пункт выбран в другом городе — после смены города он недействителен. */
	document.addEventListener('change', function (event) {
		var target = event.target;
		if (!target || target.id !== 'billing_city') {
			return;
		}

		var idField = field('woo-ozon-point-id');
		var cityField = field('woo-ozon-point-city');

		if (!idField || !idField.value || !cityField) {
			return;
		}

		if (cityField.value && cityField.value !== cityValue()) {
			setPoint('', '');
			setStatus(WooOzon.i18n.cityChanged);
		}
	});


	/* Блок выбора пункта показываем только когда выбран способ Ozon — как у СДЭК. */
	function syncVisibility() {
		var block = field('woo-ozon-pvz');
		if (!block) {
			return;
		}

		var checked = document.querySelector('input[name^="shipping_method"]:checked');
		var isOzon = !!checked && checked.value.indexOf('woo_ozon') === 0;

		if (isOzon) {
			block.removeAttribute('hidden');
			return;
		}

		block.setAttribute('hidden', 'hidden');

		var modal = document.querySelector('#woo-ozon-map-modal');
		if (modal && !modal.hasAttribute('hidden')) {
			modal.setAttribute('hidden', 'hidden');
		}
	}

	document.addEventListener('change', function (event) {
		var target = event.target;
		if (target && target.name && target.name.indexOf('shipping_method') === 0) {
			syncVisibility();
		}
	}, true);

	document.addEventListener('DOMContentLoaded', function () {
		initMap();
		syncVisibility();
		// Пункт мог сохраниться в сессии с прошлого захода: цену всё равно надо получить.
		quoteIfNeeded();
	});

	/* Телефон участвует в расчёте: изменился номер — пересчитываем цену. */
	var phoneTimer = null;
	document.addEventListener('input', function (event) {
		var target = event.target;
		if (!target || target.id !== 'billing_phone') {
			return;
		}

		var chosen = field('woo-ozon-point-id');
		if (!chosen || !chosen.value) {
			return;
		}

		clearTimeout(phoneTimer);
		phoneTimer = setTimeout(function () { quote(chosen.value); }, 700);
	});

	if (window.jQuery) {
		$(document.body).on('updated_checkout', function () {
			initMap();
			syncVisibility();
			quoteIfNeeded();
		});
	}
})(window.jQuery);
