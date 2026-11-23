# Разработка: запуск, API, команды

## Локальная установка (Ubuntu / WSL)

```bash
# 1. Системные пакеты
sudo apt update
sudo apt install -y php-cli php-pgsql php-mbstring composer postgresql postgresql-contrib
sudo systemctl enable --now postgresql

# 2. База данных и пользователь (пароль замените на свой)
sudo -u postgres psql -c "CREATE ROLE camera_catalog LOGIN PASSWORD 'dev_password';"
sudo -u postgres createdb -O camera_catalog camera_catalog
sudo -u postgres psql -d camera_catalog -c "CREATE EXTENSION IF NOT EXISTS pg_trgm;"

# 3. Проект
composer install
cp .env.example .env          # затем впишите DB_PASSWORD
composer migrate              # создать таблицы
composer seed                 # тестовые данные
```

## Запуск

```bash
composer serve                # http://localhost:8000
```

Из Windows сайт открывается по тому же адресу: WSL пробрасывает localhost.

Проверка API:

```bash
curl 'http://localhost:8000/api/cameras?type=mirrorless&sensor=full_frame'
```

## API

`GET /api/cameras`

| Параметр | Пример | |
|---|---|---|
| `brand` | `sony` | слаг бренда |
| `mount` | `sony-e` | слаг байонета |
| `type` | `mirrorless` | `mirrorless` \| `dslr` \| `compact` |
| `sensor` | `full_frame` | `medium_format` \| `full_frame` \| `aps_c` \| `aps_c_canon` \| `m43` \| `one_inch` |
| `limit`, `offset` | `20`, `40` | пагинация, `limit` ≤ 100 |

Ответ имеет вид `{ "data": [...], "meta": { "total", "limit", "offset" } }`, ошибка возвращается как `{ "error": { "status", "message" } }`.

## Импорт данных

```bash
php scripts/import.php --dry-run data/import/    # только проверить
php scripts/import.php data/import/              # импортировать все *.json из папки
php scripts/import.php --force data/import/      # перезаписать и verified-записи
```

Формат и правила описаны в [import-format.md](import-format.md), схема лежит в [../data/schema/catalog.schema.json](../data/schema/catalog.schema.json).

## Прочие команды

```bash
composer migrate              # применить новые миграции
composer migrate -- --status  # какие миграции применены
composer seed                 # загрузить database/seeds/
composer test                 # тесты PHPUnit
PGPASSWORD=dev_password psql -h 127.0.0.1 -U camera_catalog camera_catalog   # консоль БД
```
