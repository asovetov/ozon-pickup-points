=== 3D-Club Delivery to Ozon Pickup Points for WooCommerce ===
Contributors: asovetov
Tags: woocommerce, shipping, ozon, delivery, pickup points
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.4.10
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

This plugin talks to two third-party services. Both are operated by their owners, not by the plugin author.

= Ozon Delivery API =

* Service: Ozon Delivery API at https://api-delivery.ozon.ru, with the OAuth token endpoint at https://xapi.ozon.ru/oauth/token. Operated by Ozon. It is used to load the pickup point catalogue, to calculate the delivery price and to create shipments.
* What is sent: the store's API credentials (Client ID and Client Secret) to obtain an access token; the recipient's phone number; the ID of the selected pickup point; the declared value of the parcel; the parcel dimensions and weight; and the order number inside the parcel description.
* When: when the store owner refreshes the pickup point catalogue; at checkout, after the customer picks a point and enters a phone number (price calculation, no order is created); when the store owner creates a shipment from the order screen; and during the automatic status sync every 30 minutes.
* Conditions: nothing is sent to Ozon until the store owner enters API credentials and a customer selects an Ozon pickup point. Browsing the catalogue and the cart does not contact Ozon, because the pickup point list is cached in the store database.
* Terms of use: https://docs.ozon.ru/legal/terms-of-use/ozon-delivery/
* Privacy policy: https://docs.ozon.ru/legal/personal-data/privacy-policy/

= Yandex Maps =

* Service: Yandex Maps JavaScript API and Geocoder at https://api-maps.yandex.ru, operated by Yandex. It is used to show pickup points on a map.
* What is sent: the store's Yandex API key and the city typed at checkout, so the map opens on that city.
* When: only after the customer clicks "Choose a point on the map".
* Conditions: the map is optional. Without a Yandex API key the customer picks a point from the list and no request is made to Yandex.
* Terms of use: https://yandex.ru/legal/maps_api/
* Privacy policy: https://yandex.ru/legal/confidential/

== Changelog ==

= 0.4.10 =
* External services section now names both Ozon hosts the plugin calls: api-delivery.ozon.ru for the API and xapi.ozon.ru for the OAuth token.

= 0.4.9 =
* Renamed to "3D-Club Delivery to Ozon Pickup Points for WooCommerce" and moved to a new slug; text domain updated.
* External services section now lists the exact data sent to Ozon and Yandex, with links to their terms of use and privacy policies.
* Admin notices are dismissible.

= 0.4.8 =
* Кнопка выбора пункта больше не появляется в корзине: там ещё нет города, и карта открывалась пустой.
* Если город не заполнен, вместо пустой карты показывается подсказка и фокус уходит в поле города.

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
