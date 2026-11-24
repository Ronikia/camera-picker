# Формат импорта каталога (JSON)

Формальная схема: [`data/schema/catalog.schema.json`](../data/schema/catalog.schema.json).
Пример: [`data/schema/example.json`](../data/schema/example.json).

## Структура

```json
{
  "brands":  [ ... ],
  "mounts":  [ ... ],
  "cameras": [ ... ],
  "lenses":  [ ... ]
}
```

Все разделы необязательны. Можно отдельно импортировать файл только с объективами,
если бренды и байонеты уже есть в базе.

## Ссылки и ключи

Записи ссылаются друг на друга **слагами**, а не id (`"brand": "sony"`, `"mount": "sony-e"`).
Слаг состоит из латиницы в нижнем регистре, цифр и одиночных дефисов: `fe-85mm-f1-8`.

| Сущность | Уникальный ключ |
|---|---|
| brand / mount | `slug` |
| camera | `brand` + `slug` |
| lens | `brand` + `mount` + `slug` (один объектив под разные байонеты хранится как разные записи) |

Повторный импорт того же файла ничего не дублирует. Записи, у которых совпал ключ, обновляются.

## Правила обновления

- Поле **отсутствует** в JSON: значение в базе не трогается.
- Поле задано как `null`: значение в базе очищается. Если характеристика неизвестна, пишите `null` и не выдумывайте значение.
- Записи с `verified = true` (проверены человеком) без `--force` не перезаписываются, импорт выдаст `WARN`.
- Если импорт изменил данные записи, `verified` сбрасывается в `false`, если в файле не указан явно.
- Поле `use_cases` у элемента **заменяет** весь набор его сценариев.

## Значения

| Поле | Значения |
|---|---|
| `body_type` | `mirrorless`, `dslr`, `rangefinder`, `compact` (у компакта `mount: null`) |
| `system_type` (mount) | `mirrorless`, `dslr`, `rangefinder`; должен совпадать с `body_type` камеры |
| `sensor_format`, `designed_for_format` | `medium_format`, `full_frame`, `aps_c`, `aps_c_canon`, `m43`, `one_inch` |
| `video_max_resolution` | `hd` (720p), `fhd` (1080p), `4k`, `5k`, `6k`, `7k`, `8k` |
| `use_cases` | `{ "portrait": 5, ... }`, оценка 1–5; слаги из таблицы `use_cases` |
| `price_usd` | цена на старте продаж, целое число USD |
| `filter_mm` | диаметр фильтра, может быть дробным (`40.5`) |
| `aperture_wide` / `aperture_tele` | f-число на широком и длинном конце; у фикса и постоянного зума они равны |

Сценарии: `portrait`, `landscape`, `street`, `sport`, `wildlife`, `event`, `video`, `travel`, `macro`, `astro`.

Неизвестные поля считаются ошибкой. Так ловятся опечатки ИИ вроде `weight` вместо `weight_g`.

Частые ошибки ИИ: `1080p` вместо `fhd`, `micro_four_thirds` вместо `m43`, `1_inch` вместо `one_inch`.
Импорт показывает фактическое значение и список допустимых: `"1080p" is not allowed, expected one of: ...`.

## Запуск

```bash
php scripts/import.php --dry-run data/import/          # проверить, ничего не записывая
php scripts/import.php data/import/sony-lenses.json    # импорт одного файла
php scripts/import.php --force data/import/            # перезаписать и verified-записи
```

Каждый файл импортируется в своей транзакции. Если в файле есть хоть одна ошибка, он не записывается целиком.
Ошибки выводятся с путём к полю: `cameras[3].weight_g: Number must be greater than 0`.

## Подсказка для промпта ИИ

> Верни JSON строго по JSON Schema ниже (приложить `catalog.schema.json`). Неизвестные
> характеристики указывай как `null`, не угадывай. Для каждой записи заполни `source_url`:
> ссылку на страницу, откуда взяты данные. Не ставь `verified`.
