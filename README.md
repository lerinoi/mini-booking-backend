# Mini Booking — backend

Демонстрационный JSON API бронирования одной переговорной комнаты. Расписание: ежедневно, включая выходные, с 09:00 до 18:00, по одному часу на встречу.

## Стек и требования

Laravel 12, PHP 8.3, Composer 2, SQLite, PHPUnit 11. PHP-расширения, включая `pdo_sqlite` и `intl`, устанавливаются Dockerfile. Нужны Git и Docker с Compose v2. Локальные PHP, Composer и Node не требуются.

На Windows используйте Docker Desktop с WSL 2 и включённой интеграцией Ubuntu. Команды ниже выполняйте в терминале WSL; репозитории рекомендуется хранить в Linux-файловой системе.

## Размещение и запуск

Два независимых репозитория должны располагаться рядом под именами `backend` и `frontend`:

```text
mini-booking/
├── backend/     # этот репозиторий; compose.yaml
└── frontend/    # Vue-приложение; отдельный репозиторий
```

Из каталога `backend`:

```sh
docker compose config --quiet
docker compose up -d --build --wait
docker compose exec backend php artisan db:seed --force
```

Адреса: API — http://localhost:8000/api/slots, интерфейс — http://localhost:5173. Порты 8000 и 5173 должны быть свободны. Compose собирает frontend из `../frontend`; Vite проксирует `/api` на `http://backend:8000` внутри Docker.

При запуске backend автоматически создаёт `.env` из `.env.example`, генерирует отсутствующий `APP_KEY`, создаёт SQLite-файл, устанавливает зависимости из `composer.lock` и применяет миграции. Frontend выполняет `npm ci` по `package-lock.json`. Seeder запускается отдельной командой выше: до него расписание пустое.

Исходники подключены через bind mounts. `vendor` и `node_modules` хранятся в отдельных Docker volumes. Правки кода не требуют пересборки; после изменения Dockerfile выполните запуск с `--build`.

```sh
docker compose logs --tail=50
docker compose restart
docker compose down
```

`down` без `-v` сохраняет volumes. SQLite хранится в `database/database.sqlite` на хосте, а не в volume зависимостей. Не удаляйте файл базы, не используйте `migrate:fresh` для рабочей базы.

## Окружение и данные

В `.env.example` заданы `DB_CONNECTION=sqlite`, `APP_TIMEZONE=Europe/Moscow`, `APP_URL=http://localhost:8000`. Сессии и кеш используют файлы, очередь — `sync`; Redis и отдельная СУБД не нужны. Настройки меняются в локальном `.env`; после изменения перезапустите backend. `.env`, ключи, SQLite, зависимости и логи исключены из Git.

```sh
docker compose exec backend php artisan migrate --force
docker compose exec backend php artisan db:seed --force
docker compose exec backend php artisan db:table slots
docker compose exec backend php artisan db:table bookings
```

Seeder добавляет 63 слота на семь календарных дней от сегодняшнего дня в `config('app.timezone')`. Повторный запуск не создаёт дубликаты; запуск на следующий день добавляет недостающий день. Старые слоты и бронирования остаются. Автоматического ежедневного запуска нет: для расширения расписания повторите `db:seed`.

`starts_at`, `ends_at` и `cancelled_at` хранятся в UTC. API выдаёт время слотов в ISO 8601 со смещением часового пояса расписания и полем `timezone`. Если начало слота меньше либо равно текущему времени, его статус — `past`, независимо от бронирования.

## API

Отправляйте `Accept: application/json`, для POST также `Content-Type: application/json`.

| Метод и путь | Ответ |
|---|---|
| `GET /api/slots` | `200`: массив всех слотов, отсортированный по `starts_at` |
| `POST /api/bookings` | `201`: `{"id": 1}`; `422`: ошибки полей; `409`: слот занят или прошёл |
| `DELETE /api/bookings/{id}` | `204` без тела; `404`: бронирование отсутствует или уже отменено |
| `GET /api/health` | `200`: `{"status":"ok"}` |
| `GET /up` | проверка состояния Laravel |

Тело POST:

```json
{"slot_id": 1, "customer_name": "Иван Петров", "customer_email": "ivan@example.com"}
```

Обязательны существующий целочисленный `slot_id`, имя и корректный email, строки до 255 символов. Ошибки `422` содержат объект `errors` с массивами сообщений по именам полей.

Слот содержит `id`, `starts_at`, `ends_at`, `timezone`, `status` (`available`, `booked`, `past`) и `active_booking_id`. Имена и email в расписание не попадают. Связанное активное бронирование загружается без N+1.

На `slots.starts_at` стоит уникальный индекс, `bookings.slot_id` связан внешним ключом со слотом. Частичный уникальный индекс `bookings(slot_id) WHERE cancelled_at IS NULL` гарантирует одно активное бронирование, включая конкурентные запросы. Создание выполняется в транзакции с захватом SQLite write-lock до чтения слота, `busy_timeout=5000` и ограниченными повторными попытками. Ожидаемый конфликт уникальности возвращает `409`; длительная блокировка при создании — `503` с `Retry-After: 1`. Остальные ошибки БД не маскируются под конфликт.

Отмена заполняет `cancelled_at` и сохраняет запись в истории. Будущий слот снова доступен; прошедший остаётся `past`.

## Структура и проверки

- `app/Models` — Eloquent-модели.
- `app/Http/Controllers`, `app/Http/Requests` — API и валидация.
- `app/Casts/UtcDateTime.php` — преобразование дат UTC.
- `database/migrations`, `database/seeders` — схема и расписание.
- `routes/api.php` — маршруты; `tests/Feature` — проверки сценариев.

```sh
docker compose exec backend php artisan test
docker compose exec backend php artisan route:list --path=api
docker compose exec backend composer validate --strict
docker compose exec frontend node --test tests/bookings.test.js
docker compose exec frontend npm run build
```

PHPUnit использует отдельную SQLite `:memory:` и фиксированное время; рабочая база не очищается.

## Ограничения демо

Одна комната, без авторизации и проверки владельца: любой пользователь может отменить любое активное бронирование. Нет админки, Scheduler, cron и фоновых задач. Все исторические слоты возвращаются одним списком, без пагинации. Интерфейс обновляет данные при действиях и вручную, без push-уведомлений между клиентами. При потерянном ответе POST клиент сверяет расписание; серверный ключ идемпотентности не реализован.

Compose использует сервер разработки Laravel и Vite, а `.env.example` включает отладку. Это локальное демо, а не конфигурация публичного production-сервера. Публикация исходников на GitHub не означает готовность открывать API в интернет. Стандартные миграции Laravel для пользователей, кеша и очереди сохранены в истории, но интерфейс авторизации и фоновые обработчики не запускаются.
