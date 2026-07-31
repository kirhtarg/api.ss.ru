# Безопасное развёртывание Partner API на единственном production-сервере

Этот runbook рассчитан на сервер без отдельного staging. Проверки Partner API выполняются только в одноразовой копии с SQLite `:memory:`. Production `.env`, БД, `storage`, `bootstrap/cache` и работающие процессы в тестовый контур не копируются.

## 1. До развёртывания

1. Зафиксировать оператора, время работ и текущий commit:

   ```bash
   date -u
   git status --short
   git rev-parse HEAD
   git log -1 --oneline
   ```

   Рабочее дерево текущего release не должно содержать неожиданных изменений.

2. Проверить наличие обязательных env-ключей без вывода значений:

   ```bash
   for key in PARTNER_API_SIGNATURE_TTL PARTNER_API_RATE_LIMIT PARTNER_RESERVATION_TTL_MINUTES PARTNER_WEBHOOK_TIMEOUT PARTNER_WEBHOOK_MAX_ATTEMPTS PARTNER_WEBHOOK_ALLOW_HTTP PARTNER_WEBHOOK_ALLOWED_PORTS; do
     awk -F= -v key="$key" '$1 == key { found=1 } END { printf "%s: %s\n", key, found ? "present" : "missing"; exit(found ? 0 : 1) }' .env
   done
   ```

   Не использовать `cat .env`, `printenv`, `phpinfo()` или команды, печатающие значения секретов.

3. Создать свежий дамп MySQL вне release-каталога и сохранить код завершения:

   ```bash
   umask 077
   backup="/secure/backups/skateandsnow-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"
   mysqldump --single-transaction --quick --routines --triggers --events "$DB_DATABASE" | gzip -c > "$backup"
   test ${PIPESTATUS[0]} -eq 0
   test ${PIPESTATUS[1]} -eq 0
   test -s "$backup"
   gzip -t "$backup"
   stat --format='%n %s bytes %y' "$backup"
   ```

   Credentials передавать через защищённый MySQL option file, не через аргументы процесса и не записывать в deployment log.

4. Проверить ресурсы и runtime:

   ```bash
   df -h
   free -h
   php -v
   php -m
   crontab -l | grep 'artisan schedule:run'
   pgrep -af 'artisan (queue:work|horizon)'
   ```

5. Запустить read-only preflight из текущего release:

   ```bash
   bash ./scripts/check-partner-api-deployment.sh
   ```

## 2. Подготовка release

1. Создать новый отдельный release-каталог. Не собирать поверх работающего release.
2. Развернуть в него заранее проверенный commit и повторно зафиксировать `git rev-parse HEAD`.
3. Установить backend dependencies без изменения lock-файла:

   ```bash
   composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
   ```

   `composer update` запрещён.

4. Собрать админку в отдельном admin release-каталоге по lock-файлу. Не изменять текущую работающую сборку:

   ```bash
   npm ci
   npm run build
   ```

5. До подключения production `.env` выполнить из backend release:

   ```bash
   bash ./scripts/verify-partner-api.sh
   ```

   Скрипт сам создаёт временную копию, копирует отдельный `vendor`, создаёт временные `storage`/cache и `.env.testing`, очищает PHP cache-файлы только внутри временной копии и запускает только Partner API tests. Если копирование невозможно, тесты не стартуют.

6. Не копировать production `.env` в тестовый каталог. Подключить production `.env` к новому release только после успешной изолированной проверки.
7. До переключения выполнить в новом release:

   ```bash
   php artisan migrate --pretend
   php artisan route:list --path=api/partner
   php artisan schedule:list
   php -r 'json_decode(file_get_contents("resources/openapi/partner-v1.json"), true, 512, JSON_THROW_ON_ERROR); echo "OpenAPI OK\n";'
   ```

## 3. Развёртывание

1. Объявить короткое окно обслуживания только для миграций и атомарного переключения release.
2. Ещё раз проверить свежий дамп и точный список pending migrations:

   ```bash
   bash ./scripts/check-partner-api-deployment.sh --deploy --backup=/secure/backups/<fresh-dump>.sql.gz
   ```

   Скрипт потребует дамп не старше 24 часов, покажет pending migrations, запросит точную фразу подтверждения, запишет UTC-время и выполнит `php artisan migrate --force` только с тремя явными `--path` Partner API. Посторонние pending migrations этим скриптом не применяются.

3. Проверить `php artisan migrate:status`. Не выполнять `migrate:rollback`.
4. Атомарно переключить symlink backend/admin на новые release-каталоги.
5. Проверить конфигурацию PHP-FPM (`php-fpm -t` или команда соответствующего пакета), затем выполнить управляемый reload/restart.
6. Выполнить управляемый `php artisan queue:restart` и подтвердить появление worker через Supervisor/systemd.
7. Только после успешного запуска нового release прогреть необходимые production cache штатными командами проекта.
8. Проверить `/up`, затем выполнить smoke checklist ниже.
9. Убедиться, что таблицы `partners` и `partner_api_credentials` пусты. Не создавать активного партнёра или ключ автоматически.

## 4. Post-deploy smoke checklist без реальных заказов и платежей

- [ ] Главная страница магазина отвечает без ошибок.
- [ ] Каталог открывается и возвращает существующие товары.
- [ ] Карточка существующего товара открывается.
- [ ] В корзину можно локально добавить товар без оформления заказа.
- [ ] Endpoint способов доставки отвечает, но доставка не оформляется.
- [ ] Endpoint способов оплаты отвечает, но платёж не создаётся.
- [ ] Вход в админку работает.
- [ ] Существующий список заказов читается.
- [ ] Существующие настройки платежей читаются и не изменены.
- [ ] `/up` отвечает успешно.
- [ ] `/api/partner/v1/openapi.json` отвечает и содержит OpenAPI 3.1.
- [ ] В `Настройки → Partner API` нет активных партнёров и API-ключей.
- [ ] `php artisan schedule:list` содержит `partner-api-release-expired-stock-reservations`.
- [ ] Cron содержит ежеминутный `schedule:run`.
- [ ] Queue worker активен.
- [ ] В новых строках `storage/logs/laravel.log` нет ошибок и чувствительных данных.

Тестовый партнёр, ключ, webhook и тестовый заказ создаются только после отдельного ручного подтверждения владельца системы.

## 5. Rollback

1. Зафиксировать UTC-время, причину, симптомы, текущий и предыдущий commit в отдельном incident/deployment log.
2. Не выполнять автоматический `php artisan migrate:rollback` или `migrate:refresh` на production.
3. Если новые таблицы не мешают старому приложению, атомарно вернуть symlink предыдущего backend/admin release.
4. Проверить конфигурацию PHP-FPM и выполнить управляемый reload/restart.
5. Перезапустить queue workers на код предыдущего release и проверить `/up` и основной smoke checklist.
6. Оставить новые additive Partner API таблицы на месте до отдельного анализа. Старый release их не использует.
7. Восстанавливать БД из проверенного дампа только как последний вариант после отдельного решения владельца, остановки записывающих процессов и документирования ожидаемой потери данных после момента дампа.
8. После восстановления повторить проверки целостности магазина, заказов и платежей и сохранить полный журнал действий.

## Классификация команд

Read-only: `git status`, `git rev-parse`, `df`, `free`, `php -v`, `php -m`, `crontab -l`, `pgrep`, `artisan about`, `migrate:status`, `migrate --pretend`, `route:list`, `schedule:list`, проверка OpenAPI и `scripts/check-partner-api-deployment.sh` без аргументов.

Изменяют состояние: создание дампа, `composer install` и `npm ci/build` внутри нового release, `check-partner-api-deployment.sh --deploy`, `migrate --force`, переключение symlink, cache warmup, reload PHP-FPM и `queue:restart`. Каждая из них выполняется вручную в указанной фазе и журналируется.
