# Ozon Delivery для WooCommerce

Плагин доставки в пункты выдачи Ozon: кэш ПВЗ, тариф по весу в корзине, точный расчёт и создание заявки через Ozon Delivery API.

Написан для реального магазина, который работает на WooCommerce: без подписок, без внешних сервисов, все данные остаются на своём сервере.

**Статус:** 0.1.0. Работает весь путь заказа: покупатель выбирает пункт выдачи в оформлении, менеджер создаёт заявку кнопкой в карточке заказа, статус посылки подтягивается кроном каждые 30 минут и переносится в статус заказа. Не сделано: печать этикеток, отмена и возвраты через API.

## Что умеет

- **Кэш пунктов выдачи.** Ozon отдаёт ПВЗ страницами по курсору — плагин складывает их в свою таблицу и ищет по городу и адресу без обращения к API на каждый запрос.
- **Метод доставки с тарифом по весу.** В корзине Ozon не может посчитать цену: расчёт требует конкретный пункт и телефон получателя. Поэтому в корзине работает тариф по весу, а точная стоимость приходит при создании заявки.
- **Клиент Ozon Delivery API.** OAuth `client_credentials`, кэш токена, повтор запроса после редиректа 307 с cookie `__Secure-ETC` — без этого API отдаёт цикл редиректов вместо ответа.
- **Выбор пункта выдачи в оформлении.** Поиск по кэшу без запросов к Ozon, выбранный пункт сохраняется в заказ и виден в письмах и в админке.
- **Создание заявки с ключом идемпотентности.** Повторное нажатие кнопки не создаёт вторую посылку.
- **Синхронизация статусов.** Крон раз в 30 минут опрашивает `/v1/posting/info` и переводит заказ в «Доставляется», «Готов к выдаче» или «Выполнен»; ошибки API пишутся в заметки заказа.

## Установка

1. Скопируйте папку `woo-ozon-delivery` в `wp-content/plugins/`.
2. Активируйте плагин в разделе «Плагины».
3. WooCommerce → Ozon Delivery: укажите Client ID и Client Secret из личного кабинета Ozon (Настройки → API).
4. Нажмите «Обновить пункты выдачи» — плагин загрузит ПВЗ в кэш.
5. WooCommerce → Настройки → Доставка: добавьте метод «Ozon» в нужную зону и задайте тарифы по весу.

## Настройки

| Параметр | Что делает |
|---|---|
| Client ID / Secret | Доступ к Ozon Delivery API |
| URL токена, URL API, Scope | По умолчанию боевые адреса Ozon |
| Вес и габариты по умолчанию | Подставляются, если у товара не заполнены |
| Наценка на доставку, % | Упаковка и обработка |

Тарифы по весу задаются строками `до_кг:цена`:

```
1:350
3:450
5:600
10:900
```

## Требования

- WordPress 6.4+
- WooCommerce 8.0+
- PHP 8.1+
- Совместимость с HPOS (хранение заказов в отдельных таблицах) объявлена

## Безопасность

- Все формы проверяют nonce, действия в админке требуют `manage_woocommerce`.
- Ввод очищается (`sanitize_text_field`, `absint`), вывод экранируется.
- Запросы к своей таблице идут через `$wpdb->prepare`.
- Ключи API хранятся в опциях WordPress и не попадают в логи и в HTML.

## Лицензия

GPL-3.0-or-later. См. [LICENSE](LICENSE).

---

# Ozon Delivery for WooCommerce (EN)

WooCommerce shipping plugin for Ozon pickup points: pickup point cache, weight-based rates at cart, exact quote and shipment creation through the Ozon Delivery API.

Self-hosted, no subscriptions, no third-party services — all data stays on your server.

**Status:** 0.1.0. The full order flow works: the customer picks a pickup point at checkout, the manager creates the shipment from the order screen, and the posting status is polled every 30 minutes and mapped to the order status. Not implemented: label printing, cancellation and returns via API.

### Why the client looks unusual

Every Ozon Delivery API POST answers `307` with a `__Secure-ETC` cookie and only returns data when the same request is replayed with that cookie. Following redirects automatically loops on `?__rr=N`, so the plugin handles the redirect explicitly and replays the request.

### Requirements

WordPress 6.4+, WooCommerce 8.0+, PHP 8.1+. HPOS compatible.

### License

GPL-3.0-or-later. See [LICENSE](LICENSE).
