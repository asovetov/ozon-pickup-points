/**
 * Выбор пункта выдачи Ozon в оформлении заказа.
 *
 * Список тянем из кэша плагина, а не из Ozon: поиск по городу не должен ждать внешний API.
 */
(function ($) {
	'use strict';

	var timer = null;
	var lastQuery = '';

	function list() {
		return document.getElementById('woo-ozon-list');
	}

	function setStatus(text) {
		var box = document.getElementById('woo-ozon-quote-status');
		if (box) {
			box.textContent = text || '';
		}
	}

	/* Ozon считает доставку для конкретного пункта и телефона. Спрашиваем цену сразу
	   после выбора пункта и обновляем итоги оформления. */
	function quote(pointId) {
		if (!pointId || !window.WooOzon || !WooOzon.quoteNonce) {
			return;
		}

		var phone = document.getElementById('billing_phone');
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
				if (json && json.success) {
					setStatus(WooOzon.i18n.quote.replace('%s', json.data.formatted));
					if (window.jQuery) {
						$(document.body).trigger('update_checkout');
					}
					return;
				}

				var reason = (json && json.data && json.data.message) || WooOzon.i18n.error;
				setStatus(WooOzon.i18n.quoteFail.replace('%s', reason));
			})
			.catch(function () {
				setStatus(WooOzon.i18n.quoteFail.replace('%s', WooOzon.i18n.error));
			});
	}

	function setPoint(id, label) {
		var idField = document.getElementById('woo-ozon-point-id');
		var labelField = document.getElementById('woo-ozon-point-label');

		if (!idField) {
			return;
		}

		idField.value = id ? String(id) : '';
		if (labelField) {
			labelField.value = label || '';
		}

		if (window.jQuery) {
			$(document.body).trigger('update_checkout');
		}

		quote(id);
	}

	function render(points, query) {
		var box = list();
		if (!box) {
			return;
		}

		box.innerHTML = '';

		if (!points.length) {
			var empty = document.createElement('p');
			empty.className = 'woo-ozon-pvz__empty';
			empty.textContent = query ? WooOzon.i18n.none : WooOzon.i18n.empty;
			box.appendChild(empty);
			return;
		}

		var selected = (document.getElementById('woo-ozon-point-id') || {}).value || '';

		points.forEach(function (point) {
			var button = document.createElement('button');
			button.type = 'button';
			button.className = 'woo-ozon-pvz__item' + (String(point.id) === String(selected) ? ' is-selected' : '');
			button.setAttribute('role', 'option');
			button.setAttribute('aria-selected', String(point.id) === String(selected) ? 'true' : 'false');

			var title = document.createElement('span');
			title.className = 'woo-ozon-pvz__name';
			title.textContent = point.name || 'Пункт выдачи';

			var address = document.createElement('span');
			address.className = 'woo-ozon-pvz__address';
			address.textContent = point.address || '';

			button.appendChild(title);
			button.appendChild(address);

			button.addEventListener('click', function () {
				Array.prototype.forEach.call(box.querySelectorAll('.woo-ozon-pvz__item'), function (node) {
					node.classList.remove('is-selected');
					node.setAttribute('aria-selected', 'false');
				});

				button.classList.add('is-selected');
				button.setAttribute('aria-selected', 'true');

				setPoint(point.id, (point.name || '') + (point.address ? ', ' + point.address : ''));
			});

			box.appendChild(button);
		});
	}

	function search(query) {
		var box = list();
		if (!box || !window.WooOzon) {
			return;
		}

		if (query === lastQuery) {
			return;
		}
		lastQuery = query;

		box.innerHTML = '<p class="woo-ozon-pvz__empty">' + WooOzon.i18n.loading + '</p>';

		var url = WooOzon.ajaxUrl
			+ '?action=woo_ozon_points'
			+ '&nonce=' + encodeURIComponent(WooOzon.nonce)
			+ '&q=' + encodeURIComponent(query);

		fetch(url, { credentials: 'same-origin' })
			.then(function (response) { return response.json(); })
			.then(function (data) {
				if (!data || !data.success) {
					throw new Error('bad response');
				}
				render(data.data.points || [], query);
			})
			.catch(function () {
				box.innerHTML = '<p class="woo-ozon-pvz__empty">' + WooOzon.i18n.error + '</p>';
			});
	}

	function bind() {
		var input = document.getElementById('woo-ozon-query');
		if (!input || input.dataset.bound === '1') {
			return;
		}

		input.dataset.bound = '1';

		// Разметка перерисовалась — прошлый запрос больше не действует,
		// иначе повторный поиск по тому же городу молча ничего не делает.
		lastQuery = '';

		input.addEventListener('input', function () {
			clearTimeout(timer);
			var value = input.value.trim();

			timer = setTimeout(function () {
				if (value.length >= 2) {
					search(value);
				}
			}, 350);
		});

		if (input.value.trim().length >= 2) {
			search(input.value.trim());
		}
	}

	/* Показываем блок выбора ПВЗ только когда выбран способ доставки Ozon. */
	function syncVisibility() {
		var box = document.getElementById('woo-ozon-pvz');
		if (!box) {
			return;
		}

		var chosen = document.querySelector('#shipping_method input:checked');
		var isOzon = !!chosen && chosen.value.indexOf('woo_ozon') === 0;

		if (isOzon) {
			box.removeAttribute('hidden');
		} else {
			box.setAttribute('hidden', 'hidden');
		}
	}

	var lastQuotedTotal = null;

	function subtotal() {
		var el = document.querySelector('.cart-subtotal .amount, .woocommerce-checkout-review-order-table .cart-subtotal td');
		if (!el) {
			return '';
		}
		return el.textContent.replace(/\s+/g, ' ').trim();
	}

	function quoteIfNeeded() {
		var chosen = document.getElementById('woo-ozon-point-id');
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

	document.addEventListener('DOMContentLoaded', function () {
		bind();
		syncVisibility();
		// Пункт мог сохраниться в сессии с прошлого захода: цену всё равно надо получить.
		quoteIfNeeded();
	});

	document.addEventListener('change', function (event) {
		var target = event.target;
		if (target && target.name && target.name.indexOf('shipping_method') === 0) {
			syncVisibility();
		}
	}, true);

	/* Телефон участвует в расчёте: изменился номер — пересчитываем цену. */
	var phoneTimer = null;
	document.addEventListener('input', function (event) {
		var target = event.target;
		if (!target || target.id !== 'billing_phone') {
			return;
		}

		var chosen = document.getElementById('woo-ozon-point-id');
		if (!chosen || !chosen.value) {
			return;
		}

		clearTimeout(phoneTimer);
		phoneTimer = setTimeout(function () { quote(chosen.value); }, 700);
	});

	if (window.jQuery) {
		$(document.body).on('updated_checkout', function () {
			lastQuery = '';
			bind();
			syncVisibility();
			quoteIfNeeded();
		});
	}
})(window.jQuery);
