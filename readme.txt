=== Delivery to Ozon Pickup Points for WooCommerce ===
Contributors: asovetov
Tags: woocommerce, shipping, ozon, delivery, pickup points
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.4.7
License: GPLv3 or later
Requires Plugins: woocommerce
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Ozon pickup points for WooCommerce: cached points, price from Ozon, checkout selection, shipments and statuses. Built for B2B.

== Description ==

Adds Ozon pickup points as a shipping method in WooCommerce. Built for B2B shipments from legal entities; orders from private customers will most likely work too, but that scenario is untested.

The plugin caches pickup points in its own table, so search at checkout does not call the Ozon API. The delivery price comes from Ozon: after the customer picks a pickup point and enters a phone number, the plugin calls `/v1/order/checkout` (price only, nothing is created) and uses that amount for the shipping rate. Until a point is chosen the shipping method is shown without an amount and with a note that the price will be calculated after selection.

Features:

* Pickup point cache with cursor pagination. Closed points are removed on refresh.
* Delivery price from Ozon for the selected pickup point. The plugin has no rates of its own.
* Point search at checkout by city, name or address. The selection survives the checkout AJAX refresh and is saved to the order, the admin screen and the emails.
* Shipment creation from the order screen with an idempotency key: a second click does not create a second posting.
* Status sync every 30 minutes through a WP-Cron event. Errors go to the order notes.
* Custom order status mapping through the `woo_ozon_status_map` filter.
* HPOS compatible.

The API client handles the Ozon Delivery redirect explicitly: every POST answers `307` with a `__Secure-ETC` cookie and only returns data when the same request is replayed with that cookie. Following redirects automatically loops on `?__rr=N`.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install the zip through Plugins > Add New > Upload Plugin.
2. Activate the plugin.
3. Go to WooCommerce > Ozon Delivery and enter the Client ID and Client Secret from your Ozon account (Settings > API).
4. Click "Refresh pickup points" to fill the cache.
5. Go to WooCommerce > Settings > Shipping, add the "Ozon" method to a zone. No rates to fill in: the price comes from Ozon.

== Frequently Asked Questions ==

= When does the delivery price appear? =

After the customer picks a pickup point and enters a phone number. Ozon calculates delivery for that point and recipient, and the plugin puts the amount into the shipping rate. Until then the method is shown without a sum.

= Do I need a paid Ozon plan? =

You need access to the Ozon Delivery API. Credentials are issued in the Ozon seller account.

= Does it work next to other shipping methods? =

Yes. The plugin is an ordinary WooCommerce shipping method and can sit in the same zone as other methods.

= What is not implemented? =

Label printing, cancellation and returns through the API.

== Screenshots ==

1. Pickup point selection at checkout, next to other shipping methods.

== External services ==

This plugin talks to two third-party services.

= Ozon Delivery API =

* Service: Ozon Delivery API (https://api-delivery.ozon.ru), operated by Ozon.
* What is sent: the pickup point catalogue request, delivery price calculation, and the shipment itself (recipient phone, parcel size, weight and declared value).
* When: when the site syncs the pickup point catalogue, when a customer chooses a point on checkout, and when the shop sends an order.
* Terms: https://docs.ozon.ru/api/ozon-delivery/ and the Ozon seller agreement.

= Yandex Maps =

* Service: Yandex Maps JavaScript API and Geocoder (https://api-maps.yandex.ru), operated by Yandex.
* What is sent: the map is loaded only when the customer opens the pickup point map, and the shop's API key is passed with the request. The city typed on checkout is sent to the map service to centre the map.
* When: only after the customer clicks "Choose a point on the map".
* Terms: https://yandex.ru/legal/maps_api/.

Both services are optional in the sense that the plugin works without the map key: without it the customer picks a point from the list.

== Changelog ==

= 0.4.7 =
* Отказ Ozon по номеру телефона объясняется понятным текстом и выделяется в оформлении: номер не зарегистрирован в Ozon или заблокирован.
* Сообщение об отказе больше не упоминает конкретную службу доставки.

= 0.4.6 =
* Закладки страницы настроек вынесены в фильтр `woo_ozon_settings_tabs`, содержимое — в действие `woo_ozon_settings_tab`.
* Точки для карты можно поправить фильтром `woo_ozon_map_points`.
* После отрисовки карты вызывается событие `woo-ozon:map-drawn` с точками и функцией перерисовки.

= 0.4.3 =
* Requests go to api-delivery.ozon.ru; the old xapi.ozon.ru host answered 402.
* Scopes are the full delivery-api.* list Ozon issues for the app.
* Fixed field names of the Ozon responses: delivery_point_id, full_address, next_cursor, storage_period_days, shipment_method_ids.
* shipment_method_id is stored as BIGINT: Ozon ids exceed the INT range.
* One closed pickup point no longer breaks the whole catalogue sync.
* The settings screen redirect no longer points to the old plugin slug.
* Pickup point search ranks city matches above region matches.

= 0.4.1 =
* Links to the Ozon seller account and API docs right on the settings screen.
* The plugin is documented as built for B2B shipments from legal entities.

= 0.4.0 =
* The delivery price is bound to the selected pickup point.
* Rate calculation limit and redirects restricted to the Ozon host.

= 0.3.0 =
* The weight rate table is removed. The plugin has no rates of its own.
* Until a pickup point is chosen the method shows no amount, only a note.

= 0.2.0 =
* The delivery price is requested from Ozon after the customer picks a pickup point and enters a phone number.

= 0.1.2 =
* Pickup point cards use theme colors; the selected point is outlined.

= 0.1.1 =
* The point selection block stays in place after switching shipping methods.
* Point search matches the city name as well as the address.

= 0.1.0 =
* First release: API client, point cache, weight rates, checkout selection, shipment creation, status sync.

== Upgrade Notice ==

= 0.4.1 =
Settings screen now links to the Ozon seller account, and the plugin is documented as B2B.

= 0.3.0 =
Own rate tables are gone: delivery is priced by Ozon only.
