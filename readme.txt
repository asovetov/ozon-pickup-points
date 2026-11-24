=== Ozon Delivery for WooCommerce ===
Contributors: asovetov
Tags: woocommerce, shipping, ozon, delivery, pickup points
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.4.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Shipping to Ozon pickup points for WooCommerce, built for B2B shipments to legal entities: point cache, rates from Ozon, point selection at checkout, shipment creation and status sync.

== Description ==

Adds Ozon pickup points as a shipping method in WooCommerce. Built for B2B shipments to legal entities; orders from private customers will most likely work too, but that scenario is untested.

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
5. Go to WooCommerce > Settings > Shipping, add the "Ozon" method to a zone and set the weight rates.

== Frequently Asked Questions ==

= Why is the cart price different from the final cost? =

Ozon calculates delivery for a specific pickup point and recipient phone number. The cart uses a weight-based rate, and the exact cost is stored in the order after the shipment is created.

= Do I need a paid Ozon plan? =

You need access to the Ozon Delivery API. Credentials are issued in the Ozon seller account.

= Does it work next to other shipping methods? =

Yes. The plugin is an ordinary WooCommerce shipping method and can sit in the same zone as other methods.

= What is not implemented? =

Label printing, cancellation and returns through the API.

== Screenshots ==

1. Pickup point selection at checkout, next to other shipping methods.

== Changelog ==

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

= 0.3.0 =
Own rate tables are gone: delivery is priced by Ozon only.
