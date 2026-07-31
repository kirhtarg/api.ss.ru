[← Вариации Ozon](OZON_SELLER_VARIATIONS.md) · [Назад к README](../README.md)

# Partner API

Partner API позволяет внешним партнёрам читать каталог, рассчитывать заказ штатными сервисами магазина, резервировать остатки, выбирать доставку, создавать оплату и получать изменения через webhook.

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

- [Грузовые места](delivery-packages.md) — серверный расчёт упаковки и доставки.
- [Ozon Seller API](OZON_SELLER_API.md) — другая внешняя интеграция магазина.
- [Вариации Ozon](OZON_SELLER_VARIATIONS.md) — модель внешних офферов.
