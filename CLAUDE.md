# CLAUDE.md

## Проект

Веб-каталог фотокамер и объективов. Главная идея — подбор техники «под случай»
(сценарии съёмки: portrait, landscape, street, sport, wildlife, event, video, travel, macro, astro)
и совместимость объективов с камерами по байонету. Данные собираются с помощью ИИ
и загружаются JSON-файлами через валидирующий импорт.

Владелец — разработчик среднего уровня: ключевые решения объяснять кратко, без воды.

## Стек (не менять без согласия владельца)

- Фронтенд: чистый HTML + CSS + JS (без фреймворков и сборщиков), `fetch` к API.
- Бэкенд: PHP 8.2+ (локально 8.5), простой REST API (JSON), без фреймворков. Composer, PSR-4 `App\` → `src/`.
- БД: PostgreSQL, только PDO с подготовленными запросами. Поиск средствами PostgreSQL (`pg_trgm`, FTS).
- Зависимости: `vlucas/phpdotenv`, `opis/json-schema`; dev: `phpunit/phpunit`.
- Окружение: Ubuntu в WSL, Postgres нативно через apt (без Docker).

## Структура

```
public/              веб-корень: index.php (фронт-контроллер и router для php -S), index.html, css/, js/, img/
src/Database.php     единственное место создания PDO
src/Http/            Router, JsonResponse, HttpException (ошибки 4xx, которые можно показать клиенту)
src/Controller/      разбор и валидация параметров запроса → вызов репозитория → JsonResponse
src/Repository/      весь SQL; NUMERIC из PDO приводить к float при выдаче
src/Import/          SchemaValidator (JSON Schema) + CatalogImporter (смысловые проверки, upsert)
config/bootstrap.php автозагрузка + .env; подключается вебом и всеми скриптами
database/migrations/ NNN_name.sql, применяются по порядку имён скриптом scripts/migrate.php
database/seeds/      тестовые данные в формате импорта
data/schema/         catalog.schema.json (формат импорта) и example.json
data/import/         JSON от ИИ для импорта
scripts/             migrate.php, import.php
tests/               PHPUnit
docs/                development.md (запуск, API, команды), import-format.md, roadmap.md
```

## Команды

```bash
composer serve                          # dev-сервер http://localhost:8000
composer migrate                        # применить новые миграции
composer migrate -- --status
composer seed                           # загрузить database/seeds/
php scripts/import.php [--dry-run] [--force] <file|dir> ...
composer test
PGPASSWORD=dev_password psql -h 127.0.0.1 -U camera_catalog camera_catalog   # консоль БД
```

## Модель данных (кратко)

`brands`, `mounts`, `sensor_formats` (справочник с crop_factor), `cameras`, `lenses`, `use_cases`,
`camera_use_cases` / `lens_use_cases` (оценка пригодности `score` 1–5).

- Естественные ключи: brand/mount/use_case — `slug`; camera — `(brand_id, slug)`; lens — `(brand_id, mount_id, slug)`.
- У компакта `mount_id IS NULL` (CHECK). `system_type` байонета должен совпадать с `body_type` камеры (проверяет импорт).
- Объектив: `focal_min_mm = focal_max_mm` у фикса; `aperture_wide/tele` — f-число на концах; `designed_for_format` — для совместимости/кропа.
- У основных таблиц: `source_url`, `verified`, `created_at`, `updated_at` (триггер `set_updated_at`).
- `price_usd` — цена на старте продаж, целое. Деньги и характеристики — `INTEGER`/`NUMERIC`, не float.

## Импорт (важные правила)

- Поле отсутствует в JSON → не трогаем; `null` → очищаем. `use_cases` заменяют набор целиком.
- `verified = true` не перезаписывается без `--force`; изменение импортом сбрасывает `verified` в false.
- Файл импортируется в одной транзакции; любая ошибка → файл не пишется. Ошибки с путём `cameras[3].weight_g`.
- При добавлении поля в БД: миграция + `catalog.schema.json` + `*_COLUMNS` в CatalogImporter + docs/import-format.md.

## Правила кода

- Код, имена, сообщения API/CLI — на английском. Комментарии в коде и объяснения владельцу — на русском.
- `declare(strict_types=1);`, `final` классы, типизированные свойства и сигнатуры.
- Только подготовленные запросы. Имена колонок/таблиц в SQL — только из whitelist-констант в коде, никогда из ввода.
- Все входные параметры API валидировать в контроллере (whitelist значений, слаги по regex, лимиты); ошибка → `HttpException(400)`.
- Клиенту не отдавать детали исключений (только при `APP_DEBUG=1`); 500 → `error_log`.
- Фронтенд: вывод данных только через `textContent` / `createElement`, без `innerHTML` с данными.
- Схема БД меняется только новой миграцией; старые миграции не редактировать после коммита.
- Не делать лишнего сверх задачи; идеи на будущее — в `docs/roadmap.md`.

## Порядок работы

Сначала план и уточняющие вопросы, код — после «ок». Работать поэтапно: после шага кратко сказать,
что сделано и как проверить. Изменения схемы БД показывать до создания миграции.
