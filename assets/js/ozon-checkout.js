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

	document.addEventListener('DOMContentLoaded', bind);

	if (window.jQuery) {
		$(document.body).on('updated_checkout', function () {
			lastQuery = '';
			bind();
		});
	}
})(window.jQuery);
