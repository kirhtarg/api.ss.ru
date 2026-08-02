[← Вариации Ozon](OZON_SELLER_VARIATIONS.md) · [Назад к README](../README.md)

# Partner API

Partner API позволяет внешним партнёрам читать каталог, рассчитывать заказ штатными сервисами магазина, резервировать остатки, выбирать доставку, создавать оплату и получать изменения через webhook.

Теговые условия товаров применяет сам магазин в `checkout/quote`: максимальная `extra_discount_percent` имеет приоритет над акционной ценой, `disables_registered_discount` запрещает скидку зарегистрированного клиента для строки, `disables_bonuses` исключает строку из начисления бонусов, а максимальная `increased_bonus_percent` задаёт повышенный процент начисления. Partner API возвращает применённую `tag_policy` в строках и агрегат `promotion.bonus.tag_rules`; клиент не должен пересчитывать эти правила самостоятельно.

HMAC-аутентифицированный партнёр может передать `promotion.registration_discount_percent` (0–100). Значение входит в hash quote и обязано совпасть при создании заказа. Это позволяет SportRep задавать процент отдельно для каждого подключённого магазина; клиентский браузер не является источником этого значения.

## Развёртывание

На единственном production-сервере запрещено запускать тесты в рабочем release и использовать production `.env` для тестового процесса. Полная процедура: [PARTNER_API_PRODUCTION_DEPLOYMENT.md](PARTNER_API_PRODUCTION_DEPLOYMENT.md).

Безопасная изолированная проверка выполняется только через временную копию:

```bash
bash ./scripts/verify-partner-api.sh
```

Read-only проверка текущего сервера:

```bash
bash ./scripts/check-partner-api-deployment.sh
```

Применение миграций возможно только отдельным явным режимом после свежего проверенного дампа и интерактивного подтверждения:

```bash
bash ./scripts/check-partner-api-deployment.sh --deploy --backup=/absolute/path/to/fresh-dump.sql.gz
```

Скрипт не переключает release, не очищает cache и не перезапускает PHP-FPM/queue worker. Эти действия выполняются вручную строго по runbook.

Для эксплуатации должны постоянно работать queue worker и ежеминутный Scheduler:

```bash
php artisan queue:work --queue=default --tries=8 --timeout=120
```

```cron
* * * * * cd /path/to/api.ss.ru && php artisan schedule:run >> /dev/null 2>&1
```

После ручного развёртывания в административной панели открыть `Настройки → Partner API`. Создание тестового партнёра, ключа или заказа требует отдельного ручного подтверждения.

Пункт меню создаётся миграцией и ведёт на `/cms/settings/partner-api`.

## Настройка окружения

| Переменная | Значение по умолчанию | Назначение |
|---|---:|---|
| `PARTNER_API_SIGNATURE_TTL` | `300` | Допустимый возраст HMAC-подписи, секунд |
| `PARTNER_API_RATE_LIMIT` | `120` | Запросов партнёра в минуту |
| `PARTNER_RESERVATION_TTL_MINUTES` | `30` | Срок резерва товара, минут |
| `PARTNER_QUOTE_TTL_MINUTES` | `15` | Срок действия предварительного checkout quote, минут |
| `PARTNER_WEBHOOK_TIMEOUT` | `10` | Таймаут доставки webhook, секунд |
| `PARTNER_WEBHOOK_MAX_ATTEMPTS` | `8` | Максимальное число попыток webhook |
| `PARTNER_WEBHOOK_ALLOW_HTTP` | `false` | Разрешить HTTP; только для изолированной разработки |
| `PARTNER_WEBHOOK_ALLOWED_PORTS` | `443` | Разрешённые исходящие порты через запятую |

В production оставляйте только HTTPS и порт `443`.

## Выпуск доступа

1. На вкладке `Партнёры` создать партнёра и задать комиссию.
2. При необходимости указать разрешённые IP и публичный HTTPS webhook URL.
3. На вкладке `API-ключи` выпустить ключ с минимально необходимыми scopes.
4. Скопировать секрет сразу: он отображается только один раз.
5. Передать партнёру `key_id`, секрет, базовый URL и ссылку на OpenAPI.
6. Выполнить `Тест подключения`.

При ротации старый credential отзывается, а новый секрет также показывается один раз. Webhook secret ротируется отдельно.

## Аутентификация запросов

Защищённый запрос содержит заголовки:

```text
X-Partner-Key: pk_...
X-Partner-Timestamp: 1785484800
X-Partner-Nonce: 5ab3b188-1e28-44c0-b6c6-44513b5df290
X-Partner-Signature: <hex sha256 hmac>
```

Каноническая строка собирается через перевод строки:

```text
METHOD
/api/partner/v1/path
raw=query&string=value
sha256(raw_request_body)
timestamp
nonce
```

Подпись — `HMAC-SHA256` канонической строки с выданным секретом. Nonce нельзя использовать повторно, timestamp должен укладываться в настроенный TTL.

## Scopes

| Scope | Возможности |
|---|---|
| `catalog:read` | Категории и товары |
| `checkout:read` | Способы доставки и оплаты |
| `orders:read` | Чтение партнёрских заказов |
| `orders:write` | Создание заказов и резервирование |
| `payments:write` | Создание платежа для заказа |
| `commissions:read` | Reconciliation комиссий партнёра |

## Совместимое расширение v1.1

Версия v1.1 сохраняет базовый URL `/api/partner/v1`, HMAC-контракт и существующие методы. Ответ health сообщает `v1.1`. Старые URL создания/чтения заказа и платежа не изменены.

### Стабильный каталог и синхронизация

Категории, товары, изображения, свойства и вариации сериализуются отдельными Partner Resources. Закупочные цены, поставщик, внутренние заметки, pivots и служебные поля не входят в контракт. Машинный идентификатор свойства берётся из `slug` (fallback `property_<id>`), идентификаторы вариационных атрибутов — `attribute_<id>` и `attribute_value_<id>`.

`GET /catalog/categories` и `GET /catalog/products` поддерживают `page`, `per_page`, `updated_since` и непрозрачный `cursor`. `GET /catalog/brands` возвращает активные бренды доступных товаров. Каталог товаров можно фильтровать сервером через `category_id`, `brand_id` и `q` (название или SKU). Авторизованный административный потребитель может передать `include_unavailable=true`, чтобы получить все активные товары независимо от доступности и флага показа на собственной витрине магазина; их `is_active`, `is_available`, `can_order` и `purchase_mode` остаются фактическими, а checkout не разрешает покупку недоступной позиции. Этот же параметр поддерживает `/catalog/brands`, возвращая бренды полного административного каталога. Административная page-based выдача сначала возвращает товары с активными вариациями, затем остальные товары по названию. Обычная и инкрементальная cursor-выдача сохраняет порядок `(updated_at, id)`. Следующий cursor возвращается в `meta.next_cursor`; его нельзя изменять или собирать на стороне клиента. В инкрементальной выдаче с `updated_since` возвращаются также выключенные записи с партнёрским `is_active=false`. Физически удалённые до внедрения tombstone-журнала записи выявляются периодической полной reconciliation; рекомендуемый интервал — раз в сутки.

Товар и каждая активная вариация возвращают `price`, `old_price`, `sale_price`, `demping_price`, `show_demping`, `final_price`, а также независимые источники остатка `stock_quantity`, `remote_stock_quantity`, `fast_remote_stock_quantity`. Строковые значения удалённого склада вроде `">10"` сохраняются без преобразования. `remote_stock_quantity` и `fast_remote_stock_quantity` пока являются только информационными: они не входят в `available_quantity`, не включают `can_order` и не используются обычным Partner checkout, поскольку Partner API не имеет атомарного механизма их резервирования.

`available_quantity` содержит только реально резервируемый локальный остаток после действующих резервов. Для товара без активных вариаций это доступный остаток самого товара. Для товара с активными вариациями это сумма доступных остатков всех активных вариаций; неактивные вариации не учитываются и не возвращаются. Поэтому `is_available`, `purchase_mode`, `can_order` и `can_preorder` всегда выводятся из той же величины и не противоречат друг другу. `is_available=true` означает возможность обычного Partner-заказа, а `can_preorder=true` — только возможность будущего отдельного сценария предзаказа.

Активность товара определяется только `is_active`. Обычная выдача включает активные товары с резервируемым локальным остатком, товары с `is_preorder=true`, а также товары с `is_show=true`: последний флаг разрешает показывать конкретный товар без наличия и не является флагом активности. Товар только с удалённым остатком без локального резерва, предзаказа и `is_show` в обычную выдачу не входит. Поля `purchase_mode`, `can_order` и `can_preorder` различают обычную покупку, предзаказ и недоступность; `is_available` означает только возможность обычной покупки. Список брендов содержит только активные бренды таких товаров и возвращает абсолютный `logo_url` storefront.

Каталог только сообщает возможность предзаказа. Текущий `POST /orders` принимает исключительно позиции с доступным остатком и не позволяет обходить резервирование через `is_preorder`. Partner endpoint оформления предзаказа, его webhooks и конвертация в заказ будут добавлены отдельным change set; preorder-позиции нельзя отправлять в обычный `/orders`.

Пример:

```http
GET /api/partner/v1/catalog/products?updated_since=2026-08-01T00:00:00Z&per_page=50
GET /api/partner/v1/catalog/products?cursor=<meta.next_cursor>&per_page=50
```

При параллельных обновлениях партнёр сохраняет последний успешно обработанный cursor и дедуплицирует элементы по `id` и `updated_at`.

### Checkout quote и доставка

`POST /checkout/quote` (`checkout:read`) рассчитывает строки, скидки, subtotal, доставку, total, доступность, тарифы и срок действия без создания заказа, резерва или платежа. При `POST /orders` магазин повторно проверяет цену, остаток, доставку и promotion-контекст; если условия изменились, возвращается `409 quote_terms_changed`. После успешной проверки заказ создаётся строго из авторитетного snapshot quote.

```json
{
  "items": [{"good_id": 123, "variation_id": 456, "quantity": 1}],
  "delivery": {"method_id": 2, "city_code": 137, "tariff_code": "136", "pvz_code": "SPB1"},
  "promotion": {
    "customer_reference": {
      "external_id": "sportrep-user-42",
      "registration_status": "registered",
      "birthday_status": "not_today"
    },
    "promo_code": "SUMMER",
    "partner_bonus_spend": 0
  }
}
```

Магазин является единственным ценовым арбитром. Ответ содержит `subtotal_before_discounts`, итоговый `subtotal`, общий `discount_amount`, а также `promotion.decisions` с кодом, результатом и причиной каждого правила. `birthday_status=verified_today` допустим только после проверки SportRep и передаётся внутри HMAC-подписанного запроса. Значение промокода в логах не сохраняется — фиксируется только SHA-256 fingerprint.

`promotion.bonus` содержит предварительное начисление и запрос списания. Пока внешний профиль не связан с подтверждённым бонусным аккаунтом Skate&Snow, `spend_applied` всегда равен `0`, а `spend_reason=verified_partner_bonus_account_required`. Это исключает списание баллов по неподтверждённому клиентскому идентификатору. Точно тот же объект `promotion`, что использовался для quote, необходимо передать в `POST /orders`; иначе возвращается `quote_payload_mismatch`.

Методы поиска доставки используют штатный CDEK-сервис магазина и возвращают нормализованные безопасные поля:

- `GET /delivery/cities?q=Санкт`;
- `POST /delivery/tariffs`;
- `GET /delivery/pickup-points?city_code=137`;
- `POST /delivery/pickup-points/validate`.

Поиск городов и ПВЗ кешируется на 10 минут. Внешняя ошибка возвращается как ошибка Partner API; клиенту следует повторять только безопасные GET-запросы с backoff.

### Lifecycle и reconciliation

Допустимые состояния Partner order: `created → processing → paid → completed`; до оплаты возможен переход в `cancelled`. `POST /orders/{externalOrderId}/cancel` идемпотентен: повтор отменённого заказа возвращает его состояние. Оплаченный или завершённый заказ возвращает `409 Conflict`. Отмена освобождает Partner API reserve и отменяет невыплаченную комиссию; обычные заказы магазина не затрагиваются.

После timeout создание заказа повторяется с тем же `Idempotency-Key` и идентичным телом. Изменённое тело с тем же ключом возвращает `409`. Платёж повторяется через существующий `POST /orders/{externalOrderId}/payment`; второй ShopOrder не создаётся.

Для восстановления после пропущенного webhook:

- `GET /orders?updated_since=...` или `?cursor=...` (`orders:read`);
- `GET /orders/{externalOrderId}` (`orders:read`);
- `GET /commissions?updated_since=...` или `?cursor=...` (`commissions:read`).

### Webhook v1.1

Каждая доставка содержит:

```text
X-Partner-Delivery: <stable delivery UUID>
X-Partner-Event: <event name>
X-Partner-Timestamp: <unix seconds>
X-Partner-Signature: hex(HMAC-SHA256(timestamp + "." + rawBody, webhook_secret))
```

Получатель проверяет подпись постоянным временем, принимает timestamp только в пределах 300 секунд и хранит `X-Partner-Delivery`, чтобы не обрабатывать повтор дважды. Реально формируются события: `test`, `order.created`, legacy `order.updated`, `order.status_changed`, `order.cancelled`, `payment.status_changed`, `payment.succeeded`, `payment.failed`, `delivery.status_changed`, `commission.updated`, `commission.approved`, `commission.reversed`.

Доставка выполняется queue worker, не следует redirect, повторно проверяет DNS/IP непосредственно перед запросом и использует retry/backoff. В журнал не попадают webhook secret, полная подпись или полный клиентский payload.
Для каждой доставки фиксируются delivery ID, событие, число попыток, HTTP status и `duration_ms`. Тело ответа партнёра не сохраняется: журнал содержит только content type, размер и SHA-256 fingerprint.

### Диагностика админки

Вкладка `Диагностика` показывает состояние каталога, безопасный пример DTO, поддерживаемые события, очередь/retry, scopes и последние точки reconciliation. Тест quote не создаёт заказ, резерв или платёж. Тестовый webhook остаётся отдельным явным действием.

## Документация API

- OpenAPI 3.1: `GET /api/partner/v1/openapi.json`.
- Интерактивный справочник: `GET /api/partner/v1/docs`.
- Проверка подписанного подключения: `GET /api/partner/v1/health`.

Контракт запросов, ответов и ошибок берите из OpenAPI-документа. Интерактивная страница защищена Content Security Policy (CSP) и использует зафиксированную версию Scalar.

## Эксплуатационная админка

Раздел содержит вкладки:

- `Партнёры` и `API-ключи` — конфигурация доступа и ротация;
- `Заказы` — список, фильтры и карточка штатного заказа;
- `Комиссии` — начисления и ручные допустимые переходы статуса;
- `Выплаты` — формирование реестра, подтверждение и отмена;
- `Вебхуки` — запрос, ответ, ошибка и ручная повторная доставка;
- `Журнал API` — фильтры по партнёру, endpoint, HTTP status и request ID;
- `Документация` — OpenAPI и интерактивный справочник.

Все административные endpoints требуют Sanctum-аутентификацию и роль `admin`.

## Резервы товаров

Создание заказа атомарно резервирует доступный остаток под блокировкой строк склада. Повтор того же запроса с тем же `Idempotency-Key` возвращает существующий заказ и не создаёт второй резерв. При завершении заказа резерв списывается из фактического остатка, при отмене — освобождается без списания.

Задача `partner-api-release-expired-stock-reservations` запускается Laravel Scheduler каждые 5 минут с `withoutOverlapping()`. Она освобождает просроченные резервы внутри транзакции и пишет в application log только количество освобождённых записей, если оно больше нуля. Без ежеминутного cron для `php artisan schedule:run` автоматическая очистка не выполняется.

Обычный SQLite-тест `test_second_sequential_reservation_cannot_sell_the_last_unit_twice` проверяет двойное последовательное резервирование. Настоящий тест двух параллельных транзакций находится в `tests/Concurrency` и по умолчанию не входит в test suite. Он запускается только вручную на специально созданной MySQL/MariaDB с именем, заканчивающимся `_test`, при `PARTNER_CONCURRENCY_TEST=1`; иначе завершается до подключения. Production-БД для этого теста запрещена.

## План SQL-операций миграций

`2026_07_31_000000_create_partner_api_tables.php`:

- `CREATE TABLE partners`;
- `CREATE TABLE partner_api_credentials`;
- `CREATE TABLE partner_orders` с внешней ссылкой на `shop_orders.id`, без изменения `shop_orders`;
- `CREATE TABLE partner_commission_entries`;
- `CREATE TABLE partner_api_request_logs`;
- `CREATE TABLE partner_webhook_deliveries`;
- создание индексов, unique constraints и внешних ключей только для новых `partner_*` таблиц.

`2026_07_31_010000_create_partner_payouts_table.php`:

- `CREATE TABLE partner_payouts`;
- `ALTER TABLE partner_commission_entries ADD partner_payout_id`;
- добавление внешнего ключа и индекса `partner_commission_payout_lookup` только в Partner API таблице.

`2026_07_31_020000_add_partner_api_admin_menu_item.php`:

- два read-only `SELECT` для поиска страницы `settings` и существующего пункта;
- один `SELECT MAX(order)` только при отсутствии пункта;
- один `INSERT` в `admin_menu_items` только для `href=partner-api` и найденной страницы настроек;
- существующие пункты меню не обновляются и не удаляются, массовых `UPDATE` нет.

`2026_08_01_000000_add_duration_to_partner_webhook_deliveries.php`:

- `ALTER TABLE partner_webhook_deliveries ADD duration_ms INT UNSIGNED NULL`;
- не читает и не обновляет строки;
- не затрагивает таблицы магазина, заказов, каталога, пользователей или платежей.

Миграции не изменяют структуру `shop_orders`, `shop_goods`, `users` или платёжных таблиц. `down()` ограничен созданными Partner API объектами, но на production автоматический `migrate:rollback` запрещён.

## Комиссии и жизненный цикл выплат

Жизненный цикл выплаты: `formed → paid` либо `formed → cancelled`.

- При формировании в выплату атомарно включаются только свободные комиссии партнёра в статусе `recognized`.
- Комиссию с заполненным `partner_payout_id` нельзя вручную перевести в `pending`, `recognized` или `cancelled`. Сначала нужно отменить сформированную выплату.
- Комиссии оплаченной выплаты неизменяемы, а саму оплаченную выплату отменить нельзя.
- Перед переходом в `paid` под `lockForUpdate()` повторно сверяются статус выплаты, наличие и количество комиссий, партнёр, статус `recognized`, ссылка на выплату и сумма с округлением до двух знаков.
- Отмена `formed`-выплаты блокирует выплату и её комиссии, очищает у всех комиссий `partner_payout_id` и сохраняет их статус `recognized`.

При нарушении этих правил административное API возвращает HTTP `409 Conflict`, в частности:

- комиссия уже входит в сформированную или оплаченную выплату;
- выплату пытаются подтвердить или отменить не из статуса `formed`;
- состав выплаты повреждён: количество, партнёр, статус, привязка или сумма комиссий не совпадают с реестром.

При конфликте транзакция откатывается. В лог записываются только внутренние идентификаторы, причина диагностики, количество и агрегированные суммы — без API-ключей, webhook secrets, тел запросов и платёжных реквизитов.

## Безопасность webhook

Webhook URL проверяется при сохранении и перед каждой доставкой:

- разрешены только настроенные схемы и порты;
- блокируются loopback, private, link-local и reserved IP;
- запрещены credentials и fragment в URL;
- HTTP redirects отключены;
- проверенный DNS-адрес фиксируется на время соединения для защиты от DNS rebinding.

В журнале подпись webhook всегда скрыта. Тело входящего Partner API запроса не сохраняется — журнал содержит только SHA-256 fingerprint.

## Проверка

```bash
bash ./scripts/verify-partner-api.sh
bash ./scripts/check-partner-api-deployment.sh
```

Ручной concurrency-тест разрешён только на отдельной БД `*_test`, никогда не на production:

```bash
PARTNER_CONCURRENCY_TEST=1 \
DB_CONNECTION=mysql DB_DATABASE=skateandsnow_partner_test \
DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=partner_test DB_PASSWORD='...' \
vendor/bin/phpunit tests/Concurrency/PartnerStockReservationConcurrencyTest.php
```

## См. также

## Уточнения контракта после review v1.1

Query для HMAC канонизируется из сырой query string без parse_str: каждая пара key/value form-url-decode (плюс и %20 означают пробел), затем RFC 3986 encode; пары сортируются по encoded key и value. Дубликаты, массивы, пустые значения и кириллица сохраняются. Порядок параметров поэтому не влияет на подпись, изменение значения влияет.

Checkout quote хранится только в Partner-контуре, принадлежит одному партнёру и содержит canonical hash исходных items/delivery, безопасный коммерческий snapshot и expires_at. Для полного v1.1 checkout UUID передаётся как quote_id в POST /orders. Сервер под блокировкой проверяет владельца, expiration, payload и повторно рассчитывает цену, доступный остаток после резервов и доставку. Drift и повторное использование возвращают HTTP 409 без создания заказа. Replay уже созданного заказа с исходным Idempotency-Key разрешён. Legacy v1 запрос без quote_id пока принимается для обратной совместимости.

POST /orders/{externalOrderId}/payment требует отдельный Idempotency-Key длиной 8–128 из ASCII A-Z, a-z, 0-9, точки, дефиса, подчёркивания и двоеточия. Ключ уникален для платёжной операции в пределах партнёра и привязан к конкретному PartnerOrder: одинаковый ключ нельзя использовать для другого заказа, даже если способ оплаты совпадает. Один партнёр + заказ + ключ + одинаковый payload возвращает тот же платёж без повторного gateway call; другой заказ или payload возвращает 409 idempotency_conflict. Для retry после failed initialization нужен новый ключ. Ошибка gateway не маскирует созданный заказ: order response содержит payment.status=failed и retryable error. Paid, cancelled и completed orders новый платёж не получают.

Миграция 2026_08_01_010000_create_partner_checkout_quotes_and_payment_idempotencies.php только создаёт partner_checkout_quotes и partner_payment_idempotencies. Она не изменяет shop_orders, shop_payment_transactions и другие таблицы магазина и не выполняет UPDATE существующих данных. Истёкшие неиспользованные quote старше суток удаляет hourly scheduler task partner-api-delete-expired-checkout-quotes с withoutOverlapping().


- [Грузовые места](delivery-packages.md) — серверный расчёт упаковки и доставки.
- [Ozon Seller API](OZON_SELLER_API.md) — другая внешняя интеграция магазина.
- [Вариации Ozon](OZON_SELLER_VARIATIONS.md) — модель внешних офферов.
