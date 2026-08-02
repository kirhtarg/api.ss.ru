# Partner API Implementation Plan

Docs: yes

- [x] 1. Добавить административный API управления партнёрами.
- [x] 2. Сделать в админке раздел «Partner API» с вкладками: партнёры, API-ключи, заказы, комиссии, выплаты, вебхуки, журнал API, документация.
- [x] 3. Добавить выпуск и ротацию ключа с однократным отображением секрета.
- [x] 4. Добавить тест подключения и тестовый webhook.
- [x] 5. Подключить штатный расчёт цен и резервирование.
- [x] 6. Добавить доставку и создание платежа.
- [x] 7. Добавить OpenAPI 3.1 и интерактивный справочник.
- [x] 8. Написать тесты.

## Эксплуатационная админка Partner API

Docs: yes

- [x] 9. Реализовать списки и карточки партнёрских заказов.
- [x] 10. Добавить журнал комиссий и их статусы.
- [x] 11. Добавить реестр выплат и формирование выплаты партнёру.
- [x] 12. Реализовать журнал webhook с просмотром запроса, ответа и ручным повтором.
- [x] 13. Реализовать журнал API-запросов с фильтрами по партнёру, endpoint, status и request ID.
- [x] 14. Добавить пункт в меню админки.
- [x] 15. Закрыть вопросы безопасности эксплуатационного Partner API.
- [x] 16. Добавить тесты эксплуатационных сценариев.

## Доработка инфраструктуры Partner API

Docs: yes

- [x] 17. Подключить атомарную очистку просроченных резервов к Laravel Scheduler каждые 5 минут с защитой от параллельного запуска.
- [x] 18. Усилить транзакционную целостность комиссий и жизненного цикла выплат с диагностическими HTTP 409.
- [x] 19. Заменить системные confirm/prompt/alert на адаптивные модалки Partner API.
- [x] 20. Расширить HTTP- и интеграционные тесты заказов, резервов, выплат, webhook, ролей и неуспешной оплаты.
- [x] 21. Обновить эксплуатационную документацию и OpenAPI при изменении контрактов.
- [x] 22. Выполнить статические проверки и подготовить команды финальной проверки на тестовом сервере.

## Безопасное развёртывание Partner API на единственном production-сервере

Docs: yes

- [x] 23. Удалить остаточную fallback-заглушку и проверить покрытие всех TabId.
- [x] 24. Добавить fail-closed защиту TestCase от production-БД и статически проверяемый тест guard.
- [x] 25. Добавить изолированный verify-скрипт Partner API без доступа к production credentials и данным.
- [x] 26. Добавить read-only deployment check и явный защищённый режим --deploy.
- [x] 27. Проверить и усилить безопасность трёх миграций Partner API, документировать SQL-план.
- [x] 28. Минимизировать diff routes/api.php и проверить общие классы магазина.
- [x] 29. Разделить последовательный reserve-тест и opt-in concurrency-тест для отдельной *_test БД.
- [x] 30. Добавить production deployment runbook и post-deploy smoke checklist.
- [x] 31. Выполнить только статические проверки, допустимые PROJECT_RULES.md.

## Partner API v1.1 для SportRep

Branch: main
Created: 2026-08-01

### Settings

- Testing: yes, только fail-closed SQLite `:memory:`; opt-in concurrency отдельно от production.
- Logging: verbose structured, без secrets, подписей, платёжных данных и персональных payload.
- Docs: yes.

### Этап 1. Стабильный каталог

- [x] 32. Ввести отдельные Partner API Resources/DTO категорий, товаров, вариаций, изображений, свойств и цен; исключить внутренние поля.
- [x] 33. Добавить стабильную пагинацию каталога по `(updated_at, id)`, `updated_since`, cursor и метаданные следующей страницы без изменения существующих URL.
- [x] 34. Передавать неактивные записи в инкрементальной ленте как `is_active=false` и документировать full reconciliation для физически удалённых записей.

### Этап 2. Quote и доставка

- [x] 35. Выделить безопасный расчёт строк заказа и реализовать `POST /checkout/quote` без создания заказа, резерва или платежа.
- [x] 36. Добавить нормализованные Partner endpoints поиска городов, тарифов, ПВЗ и проверки ПВЗ поверх существующих сервисов доставки с cache/rate-limit/error mapping.
- [x] 37. Повторно проверять quote, цену, остаток и доставку при создании заказа; не доверять клиентским суммам и сохранить идемпотентность.

### Этап 3. Lifecycle, webhook и reconciliation

- [x] 38. Добавить идемпотентные чтение списка/состояния и допустимую отмену партнёрского заказа через отдельный сервис без изменения обычного checkout.
- [x] 39. Зафиксировать реально генерируемый webhook-контракт, безопасные retry/backoff/SSRF guarantees и добавить события только в существующих точках формирования.
- [x] 40. Добавить reconciliation заказов и комиссий по `(updated_at, id)` с scopes `orders:read` и `commissions:read`.
- [x] 41. Обновить scopes и защитить каждый новый endpoint middleware `partner.log`, `partner.auth`, `throttle:partner`, `partner.scope`.

### Этап 4. Эксплуатация и контракт

- [x] 42. Расширить административную диагностику каталога, карточки товара, quote, webhook events, очереди и reconciliation без мутаций магазина.
- [x] 43. Добавить unit/feature/contract тесты DTO, cursor, quote, доставки, lifecycle, webhook, reconciliation, scopes, утечек полей и изоляции checkout.
- [x] 44. Обновить OpenAPI 3.1 без `additionalProperties: true` для каталога, документацию, примеры и production runbook.
- [x] 45. Выполнить разрешённые статические проверки, аудит обратной совместимости и подготовить серверные команды проверки/rollback без deployment.

### Commit plan

- После 32–34: `feat(partner-api): stabilize catalog contract and sync`
- После 35–37: `feat(partner-api): add checkout quote and delivery discovery`
- После 38–41: `feat(partner-api): add reconciliation and lifecycle contracts`
- После 42–45: `feat(partner-api): add diagnostics tests and v1.1 docs`

## Исправления Partner API v1.1 после ревью

Branch: main
Created: 2026-08-01

### Settings

- Testing: yes; только fail-closed `APP_ENV=testing`, SQLite `:memory:`, array cache/queue/session/mail, без production `.env`, MySQL и Redis.
- Logging: structured; DEBUG для безопасных стадий, INFO для итогов, WARN для конфликтов/изменений, ERROR для инвариантов; без secrets, подписей, PII и платёжных payload.
- Docs: yes.

### Этап 1. Каталог, подпись и остатки

- [x] 46. Закрыть detail неактивного товара и сохранить `is_active=false` только в incremental feed с `updated_since`; изменить `PartnerCatalogService`, HTTP-контроллер и тесты. Логировать только product ID и режим выдачи на DEBUG/WARN, без внутренних полей.
- [x] 47. Добавить `PartnerSignatureCanonicalizer`, который разбирает raw query без `parse_str`, нормализует RFC 3986 encoding, сортирует пары key/value и сохраняет дубликаты/массивы/пустые значения; подключить его в `AuthenticatePartner`, тестовые signing helpers, документацию и OpenAPI. Логировать только факт/ошибку проверки, не canonical string, secret или подпись.
- [x] 48. Выделить единую Partner-функцию доступного остатка после резервов и использовать её в Product/Variation Resources, quote и order validation; покрыть активность товара/вариации и сочетания резервов. DEBUG-логирование ограничить идентификаторами и агрегированными количествами.

### Этап 2. Quote и заказ

- [x] 49. Добавить additive таблицу и модель Partner checkout quotes с partner scope, canonical request hash, безопасным snapshot, expiration и consumption; quote не резервирует товар. Добавить scheduler cleanup только Partner quote records и безопасные агрегированные логи.
- [x] 50. Связать `quote_id` с созданием заказа: валидировать владельца, expiration, payload, повторный серверный расчёт цены/остатка/доставки и одноразовую политику с разрешённым replay исходного заказа; возвращать структурированные 409 с актуальным quote при drift. Сохранить legacy-запросы без quote для обратной совместимости v1 и задокументировать v1.1 policy.
- [x] 51. Разделить результат создания заказа и инициализации платежа: созданный заказ всегда возвращается, сбой gateway отражается безопасным payment state, replay не создаёт заказ/платёж, повтор оплаты выполняется отдельным endpoint. Логировать lifecycle без PII и gateway payload.

### Этап 3. Идемпотентная оплата

- [x] 52. Добавить additive Partner payment idempotency ledger и уникальный индекс `(partner_id, idempotency_key)`; не изменять `shop_payment_transactions` и обычный checkout. Хранить request hash и безопасный результат/статус без токенов.
- [x] 53. Требовать валидный `Idempotency-Key` на Partner payment endpoint и атомарно обеспечивать replay/conflict/concurrent protection, reuse пригодной pending transaction, а также запрет платежа для paid/cancelled/completed order. Покрыть HTTP-тестами обоих партнёров, gateway call count, conflict и временный gateway failure/retry.

### Этап 4. Контракт и качество

- [x] 54. Ужесточить OpenAPI для delivery, orders, cancel, commissions, payment, quote и ошибок: закрытые DTO, required/nullable/formats, money/currency/cursor, Idempotency-Key и ответы 401/403/404/409/422/429; добавить contract-тест маршрутов, schemas и запрещённых внутренних полей. Обновить `docs/PARTNER_API.md`, production runbook и deployment checks без секретов.
- [x] 55. Выполнить отдельный code review и strict verification: Partner API suite, релевантные checkout/payment regression tests, `composer validate`, OpenAPI parse, admin build, `git diff --check`, поиск secrets/TODO/FIXME и аудит additive migrations. Production deployment и production migrations не выполнять.

### Commit plan

- После 46–48: `fix(partner-api): secure catalog signature and availability`
- После 49–51: `fix(partner-api): enforce quote and order payment lifecycle`
- После 52–53: `fix(partner-api): make payment creation idempotent`
- После 54–55: `test(partner-api): harden contract and verification`

## Завершение каталожного контракта Partner API

Branch: main
Created: 2026-08-02

### Settings

- Testing: yes; только fail-closed SQLite `:memory:` на сервере через `scripts/verify-partner-api.sh`.
- Logging: безопасное структурированное с префиксом `[FIX:partner-catalog-contract]`, без secrets, HMAC, PII и товарных payload.
- Docs: yes.

- [x] 56. Согласовать Brand DTO с OpenAPI и выбирать без N+1 только активные бренды с активными показываемыми товарами, доступными для обычной покупки или предзаказа.
- [x] 57. Добавить в Product/Variation DTO три исходных остатка и однозначные признаки regular/preorder/unavailable без изменения `available_quantity`.
- [x] 58. Ограничить обычную каталоговую выдачу активными показываемыми товарами, доступными для покупки или предзаказа, сохранив incremental deactivation feed.
- [x] 59. Добавить HTTP- и contract-тесты brands, brand_id, остатков, цен, вариаций, preorder-признаков, list/show parity и отсутствия внутренних полей.
- [x] 60. Обновить OpenAPI и `docs/PARTNER_API.md`, явно отложив Partner preorder checkout в отдельный change set.
- [ ] 61. Провести статическую проверку и повторный code review; серверные PHP-тесты считать обязательным release gate.
- [x] 62. Исправить SQLite fixture вариаций и ввести единое product/variation availability state на основе резервируемого локального остатка.
- [x] 63. Согласовать SQL-фильтрацию каталога и брендов с единым availability service; удалённые склады оставить информационными.
- [x] 64. Добавить HTTP/unit-тесты четырёх сценариев вариаций, удалённых остатков и list/show parity.
- [x] 65. Уточнить семантику availability в OpenAPI и документации, затем выполнить допустимые статические проверки и code review.

### Commit plan

- После 56–60: `feat(partner-api): complete catalog stock and preorder contract`
- После 61: `test(partner-api): verify catalog contract`
