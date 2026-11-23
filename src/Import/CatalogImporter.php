<?php

declare(strict_types=1);

namespace App\Import;

use PDO;
use PDOStatement;

/**
 * Импорт каталога из уже разобранного JSON (assoc-массив, прошедший SchemaValidator).
 *
 * Правила:
 *  - сущности ищутся по естественному ключу (slug), поэтому повторный импорт не создаёт дублей;
 *  - поле отсутствует в JSON -> значение в БД не трогаем; поле = null -> очищаем;
 *  - записи с verified = true не перезаписываются без $force (защита ручной проверки);
 *  - если импорт изменил данные записи, verified сбрасывается в false (кроме явного verified в JSON);
 *  - use_cases у элемента, если указаны, заменяют весь набор связей этого элемента.
 */
final class CatalogImporter
{
    /** Колонки, которые разрешено писать из JSON (имена колонок подставляются в SQL — только из этого списка). */
    private const BRAND_COLUMNS = ['name', 'country', 'website', 'source_url', 'verified'];
    private const MOUNT_COLUMNS = ['name', 'flange_distance_mm', 'system_type', 'source_url', 'verified'];
    private const CAMERA_COLUMNS = [
        'model', 'body_type', 'sensor_format', 'megapixels', 'ibis', 'ibis_stops',
        'video_max_resolution', 'video_max_fps', 'iso_max', 'burst_fps', 'weather_sealed',
        'weight_g', 'price_usd', 'release_year', 'description', 'source_url', 'verified',
    ];
    private const LENS_COLUMNS = [
        'model', 'focal_min_mm', 'focal_max_mm', 'aperture_wide', 'aperture_tele',
        'designed_for_format', 'autofocus', 'stabilization', 'weather_sealed', 'min_focus_m',
        'max_magnification', 'filter_mm', 'weight_g', 'price_usd', 'release_year',
        'description', 'source_url', 'verified',
    ];

    /** @var array<string, array{inserted:int, updated:int, unchanged:int, skipped:int}> */
    private array $stats = [];

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(private readonly PDO $pdo)
    {
    }

    // ------------------------------------------------------------------ validation

    /**
     * Смысловые проверки: ссылки на бренды/байонеты/сценарии, дубли внутри файла,
     * согласованность полей. Схему (типы/диапазоны) проверяет SchemaValidator до этого.
     *
     * @param array<string, mixed> $data
     * @return list<string>
     */
    public function validate(array $data): array
    {
        $errors = [];

        $dbBrands = array_flip($this->pdo->query('SELECT slug FROM brands')->fetchAll(PDO::FETCH_COLUMN));
        $dbMounts = $this->pdo->query('SELECT slug, system_type FROM mounts')->fetchAll(PDO::FETCH_KEY_PAIR);
        $useCases = array_flip($this->pdo->query('SELECT slug FROM use_cases')->fetchAll(PDO::FETCH_COLUMN));

        // Бренды и байонеты из файла тоже считаются существующими
        $brands = $dbBrands;
        $seen = [];
        foreach ($data['brands'] ?? [] as $i => $brand) {
            $this->checkDuplicate($seen, $brand['slug'], "brands[{$i}]", $errors);
            $brands[$brand['slug']] = true;
        }

        $mounts = $dbMounts;
        $seen = [];
        foreach ($data['mounts'] ?? [] as $i => $mount) {
            $path = "mounts[{$i}]";
            $this->checkDuplicate($seen, $mount['slug'], $path, $errors);
            if (isset($mount['brand']) && !isset($brands[$mount['brand']])) {
                $errors[] = "{$path}.brand: unknown brand \"{$mount['brand']}\"";
            }
            $mounts[$mount['slug']] = $mount['system_type'];
        }

        $seen = [];
        foreach ($data['cameras'] ?? [] as $i => $camera) {
            $path = "cameras[{$i}]";
            $this->checkDuplicate($seen, "{$camera['brand']}/{$camera['slug']}", $path, $errors);

            if (!isset($brands[$camera['brand']])) {
                $errors[] = "{$path}.brand: unknown brand \"{$camera['brand']}\"";
            }

            $mount = $camera['mount'] ?? null;
            if ($camera['body_type'] === 'compact') {
                if ($mount !== null) {
                    $errors[] = "{$path}.mount: compact cameras have no interchangeable mount, use null";
                }
            } elseif ($mount === null) {
                $errors[] = "{$path}.mount: required for body_type \"{$camera['body_type']}\"";
            } elseif (!isset($mounts[$mount])) {
                $errors[] = "{$path}.mount: unknown mount \"{$mount}\"";
            } elseif ($mounts[$mount] !== $camera['body_type']) {
                $errors[] = "{$path}.mount: \"{$mount}\" is a {$mounts[$mount]} mount, but body_type is \"{$camera['body_type']}\"";
            }

            if (($camera['ibis'] ?? null) === false && ($camera['ibis_stops'] ?? 0) > 0) {
                $errors[] = "{$path}.ibis_stops: set, but ibis is false";
            }

            $this->checkUseCases($camera['use_cases'] ?? [], $useCases, $path, $errors);
        }

        $seen = [];
        foreach ($data['lenses'] ?? [] as $i => $lens) {
            $path = "lenses[{$i}]";
            $this->checkDuplicate($seen, "{$lens['brand']}/{$lens['mount']}/{$lens['slug']}", $path, $errors);

            if (!isset($brands[$lens['brand']])) {
                $errors[] = "{$path}.brand: unknown brand \"{$lens['brand']}\"";
            }
            if (!isset($mounts[$lens['mount']])) {
                $errors[] = "{$path}.mount: unknown mount \"{$lens['mount']}\"";
            }
            if ($lens['focal_max_mm'] < $lens['focal_min_mm']) {
                $errors[] = "{$path}.focal_max_mm: must be >= focal_min_mm";
            }
            if ($lens['aperture_tele'] < $lens['aperture_wide']) {
                $errors[] = "{$path}.aperture_tele: must be >= aperture_wide (f-number at the long end)";
            }
            if ($lens['focal_min_mm'] == $lens['focal_max_mm'] && $lens['aperture_tele'] != $lens['aperture_wide']) {
                $errors[] = "{$path}.aperture_tele: prime lens must have aperture_tele == aperture_wide";
            }

            $this->checkUseCases($lens['use_cases'] ?? [], $useCases, $path, $errors);
        }

        return $errors;
    }

    /**
     * @param array<string, true> $seen
     * @param list<string> $errors
     */
    private function checkDuplicate(array &$seen, string $key, string $path, array &$errors): void
    {
        if (isset($seen[$key])) {
            $errors[] = "{$path}: duplicate of {$seen[$key]} (\"{$key}\")";
        } else {
            $seen[$key] = $path;
        }
    }

    /**
     * @param array<string, int> $items
     * @param array<string, int> $known
     * @param list<string> $errors
     */
    private function checkUseCases(array $items, array $known, string $path, array &$errors): void
    {
        foreach (array_keys($items) as $slug) {
            if (!isset($known[$slug])) {
                $errors[] = "{$path}.use_cases: unknown use case \"{$slug}\"";
            }
        }
    }

    // ------------------------------------------------------------------ import

    /**
     * Записывает данные. Транзакцией управляет вызывающий код (так проще сделать --dry-run).
     *
     * @param array<string, mixed> $data
     * @return array<string, array{inserted:int, updated:int, unchanged:int, skipped:int}>
     */
    public function import(array $data, bool $force = false): array
    {
        $this->stats = [];
        $this->warnings = [];

        foreach ($data['brands'] ?? [] as $brand) {
            $this->upsert('brands', ['slug' => $brand['slug']], $this->pick($brand, self::BRAND_COLUMNS), $force, $brand['slug']);
        }

        $brandIds = $this->pdo->query('SELECT slug, id FROM brands')->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach ($data['mounts'] ?? [] as $mount) {
            $row = $this->pick($mount, self::MOUNT_COLUMNS);
            if (array_key_exists('brand', $mount)) {
                $row['brand_id'] = $mount['brand'] === null ? null : $brandIds[$mount['brand']];
            }
            $this->upsert('mounts', ['slug' => $mount['slug']], $row, $force, $mount['slug']);
        }

        $mountIds = $this->pdo->query('SELECT slug, id FROM mounts')->fetchAll(PDO::FETCH_KEY_PAIR);
        $useCaseIds = $this->pdo->query('SELECT slug, id FROM use_cases')->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach ($data['cameras'] ?? [] as $camera) {
            $row = $this->pick($camera, self::CAMERA_COLUMNS);
            // mount_id всегда пишем явно: у компакта он обязан быть NULL (CHECK в БД)
            $row['mount_id'] = isset($camera['mount']) ? $mountIds[$camera['mount']] : null;

            $id = $this->upsert(
                'cameras',
                ['brand_id' => $brandIds[$camera['brand']], 'slug' => $camera['slug']],
                $row,
                $force,
                "{$camera['brand']}/{$camera['slug']}",
            );
            if ($id !== null && isset($camera['use_cases'])) {
                $this->syncUseCases('camera_use_cases', 'camera_id', $id, $camera['use_cases'], $useCaseIds);
            }
        }

        foreach ($data['lenses'] ?? [] as $lens) {
            $id = $this->upsert(
                'lenses',
                [
                    'brand_id' => $brandIds[$lens['brand']],
                    'mount_id' => $mountIds[$lens['mount']],
                    'slug'     => $lens['slug'],
                ],
                $this->pick($lens, self::LENS_COLUMNS),
                $force,
                "{$lens['brand']}/{$lens['mount']}/{$lens['slug']}",
            );
            if ($id !== null && isset($lens['use_cases'])) {
                $this->syncUseCases('lens_use_cases', 'lens_id', $id, $lens['use_cases'], $useCaseIds);
            }
        }

        return $this->stats;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * Только поля, которые есть в JSON (array_key_exists, чтобы явный null тоже попал).
     *
     * @param array<string, mixed> $item
     * @param list<string> $columns
     * @return array<string, mixed>
     */
    private function pick(array $item, array $columns): array
    {
        $row = [];
        foreach ($columns as $column) {
            if (array_key_exists($column, $item)) {
                $row[$column] = $item[$column];
            }
        }

        return $row;
    }

    /**
     * INSERT ... ON CONFLICT DO UPDATE по естественному ключу.
     * Обновляет только если данные реально изменились и запись не verified (или $force).
     *
     * @param array<string, mixed> $key   колонки уникального ключа
     * @param array<string, mixed> $data  остальные колонки
     * @param string               $label читаемое имя записи для предупреждений
     * @return int|null id записи, или null если запись пропущена (verified)
     */
    private function upsert(string $table, array $key, array $data, bool $force, string $label): ?int
    {
        $this->stats[$table] ??= ['inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];

        $row = $key + $data;
        $columns = array_keys($row);
        $placeholders = array_map(static fn (string $c): string => ":{$c}", $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s) ON CONFLICT (%s) DO ',
            $table,
            implode(', ', $columns),
            implode(', ', $placeholders),
            implode(', ', array_keys($key)),
        );

        $updateColumns = array_keys($data);
        if ($updateColumns === []) {
            $sql .= 'NOTHING';
        } else {
            $set = array_map(static fn (string $c): string => "{$c} = EXCLUDED.{$c}", $updateColumns);
            // Данные изменились не человеком -> снимаем отметку проверки (если файл не задал её явно)
            if (!array_key_exists('verified', $data)) {
                $set[] = 'verified = false';
            }
            $old = array_map(static fn (string $c): string => "{$table}.{$c}", $updateColumns);
            $new = array_map(static fn (string $c): string => "EXCLUDED.{$c}", $updateColumns);
            // ROW(...) нужен, чтобы сравнение работало и для одной колонки
            $sql .= sprintf(
                'UPDATE SET %s WHERE ROW(%s) IS DISTINCT FROM ROW(%s)%s',
                implode(', ', $set),
                implode(', ', $old),
                implode(', ', $new),
                $force ? '' : " AND {$table}.verified = false",
            );
        }
        // xmax = 0 у только что вставленной строки — так отличаем INSERT от UPDATE
        $sql .= ' RETURNING id, (xmax = 0) AS inserted';

        $stmt = $this->pdo->prepare($sql);
        $this->bindAll($stmt, $row);
        $stmt->execute();
        $result = $stmt->fetch();

        if ($result !== false) {
            $this->stats[$table][$result['inserted'] ? 'inserted' : 'updated']++;
            return (int) $result['id'];
        }

        // Строка не вернулась: либо данные не изменились, либо запись verified
        $where = implode(' AND ', array_map(static fn (string $c): string => "{$c} = :{$c}", array_keys($key)));
        $stmt = $this->pdo->prepare("SELECT id, verified FROM {$table} WHERE {$where}");
        $this->bindAll($stmt, $key);
        $stmt->execute();
        $existing = $stmt->fetch();

        if ($existing['verified'] && !$force && $this->differs($table, (int) $existing['id'], $data)) {
            $this->stats[$table]['skipped']++;
            $this->warnings[] = "{$table} {$label}: verified record has different data, not updated (use --force)";
            return null;
        }

        $this->stats[$table]['unchanged']++;
        return (int) $existing['id'];
    }

    /**
     * Отличаются ли данные от записи в БД (сравнение делает PostgreSQL, с учётом типов).
     *
     * @param array<string, mixed> $data
     */
    private function differs(string $table, int $id, array $data): bool
    {
        if ($data === []) {
            return false;
        }
        $columns = array_keys($data);
        $sql = sprintf(
            'SELECT ROW(%s) IS DISTINCT FROM ROW(%s) FROM %s WHERE id = :id',
            implode(', ', $columns),
            // Явное приведение к типу колонки: иначе PostgreSQL не знает тип параметра в ROW()
            implode(', ', array_map(
                fn (string $c): string => sprintf('CAST(:%s AS %s)', $c, $this->columnType($table, $c)),
                $columns,
            )),
            $table,
        );
        $stmt = $this->pdo->prepare($sql);
        $this->bindAll($stmt, $data + ['id' => $id]);
        $stmt->execute();

        return (bool) $stmt->fetchColumn();
    }

    /** @var array<string, array<string, string>> */
    private array $columnTypes = [];

    private function columnType(string $table, string $column): string
    {
        // Тип колонки достаём из каталога один раз на таблицу
        if (!isset($this->columnTypes[$table])) {
            $stmt = $this->pdo->prepare(
                'SELECT a.attname, format_type(a.atttypid, a.atttypmod)
                   FROM pg_attribute a
                  WHERE a.attrelid = CAST(:table AS regclass) AND a.attnum > 0 AND NOT a.attisdropped'
            );
            $stmt->execute(['table' => $table]);
            $this->columnTypes[$table] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        }

        return $this->columnTypes[$table][$column];
    }

    /**
     * @param array<string, int> $scores     use_case slug => score
     * @param array<string, int> $useCaseIds use_case slug => id
     */
    private function syncUseCases(string $table, string $fk, int $id, array $scores, array $useCaseIds): void
    {
        $delete = $this->pdo->prepare("DELETE FROM {$table} WHERE {$fk} = :id");
        $delete->execute(['id' => $id]);

        $insert = $this->pdo->prepare(
            "INSERT INTO {$table} ({$fk}, use_case_id, score) VALUES (:id, :use_case_id, :score)"
        );
        foreach ($scores as $slug => $score) {
            $insert->execute(['id' => $id, 'use_case_id' => $useCaseIds[$slug], 'score' => $score]);
        }
    }

    /**
     * Привязка с явными типами: PDO по умолчанию шлёт bool как "1"/"", что PostgreSQL отвергает.
     *
     * @param array<string, mixed> $params
     */
    private function bindAll(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $name => $value) {
            $type = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_bool($value) => PDO::PARAM_BOOL,
                is_int($value)  => PDO::PARAM_INT,
                default         => PDO::PARAM_STR,
            };
            $stmt->bindValue(":{$name}", is_float($value) ? (string) $value : $value, $type);
        }
    }
}
