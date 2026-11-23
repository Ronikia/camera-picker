<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class CameraRepository
{
    /** NUMERIC приходит из PDO строкой — приводим к числу для JSON */
    private const FLOAT_COLUMNS = ['megapixels', 'ibis_stops', 'burst_fps'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{brand?: string, mount?: string, body_type?: string, sensor_format?: string} $filters
     *        значения уже провалидированы контроллером
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function search(array $filters, int $limit, int $offset): array
    {
        $where = [];
        $params = [];

        if (isset($filters['brand'])) {
            $where[] = 'b.slug = :brand';
            $params['brand'] = $filters['brand'];
        }
        if (isset($filters['mount'])) {
            $where[] = 'm.slug = :mount';
            $params['mount'] = $filters['mount'];
        }
        if (isset($filters['body_type'])) {
            $where[] = 'c.body_type = :body_type';
            $params['body_type'] = $filters['body_type'];
        }
        if (isset($filters['sensor_format'])) {
            $where[] = 'c.sensor_format = :sensor_format';
            $params['sensor_format'] = $filters['sensor_format'];
        }

        $from = 'FROM cameras c
                 JOIN brands b ON b.id = c.brand_id
                 LEFT JOIN mounts m ON m.id = c.mount_id
                 JOIN sensor_formats sf ON sf.code = c.sensor_format'
              . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where));

        $count = $this->pdo->prepare("SELECT count(*) {$from}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $stmt = $this->pdo->prepare(
            "SELECT c.slug, c.model,
                    b.slug AS brand_slug, b.name AS brand_name,
                    m.slug AS mount_slug, m.name AS mount_name,
                    c.body_type, c.sensor_format, sf.name AS sensor_format_name,
                    c.megapixels, c.ibis, c.ibis_stops, c.video_max_resolution, c.video_max_fps,
                    c.iso_max, c.burst_fps, c.weather_sealed, c.weight_g, c.price_usd,
                    c.release_year, c.verified
             {$from}
             ORDER BY b.name, c.model
             LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $name => $value) {
            $stmt->bindValue(":{$name}", $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = array_map($this->mapRow(...), $stmt->fetchAll());

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        foreach (self::FLOAT_COLUMNS as $column) {
            if ($row[$column] !== null) {
                $row[$column] = (float) $row[$column];
            }
        }

        return [
            'slug'          => $row['slug'],
            'model'         => $row['model'],
            'brand'         => ['slug' => $row['brand_slug'], 'name' => $row['brand_name']],
            'mount'         => $row['mount_slug'] === null
                ? null
                : ['slug' => $row['mount_slug'], 'name' => $row['mount_name']],
            'body_type'     => $row['body_type'],
            'sensor_format' => ['code' => $row['sensor_format'], 'name' => $row['sensor_format_name']],
        ] + array_diff_key($row, array_flip([
            'slug', 'model', 'brand_slug', 'brand_name', 'mount_slug', 'mount_name',
            'body_type', 'sensor_format', 'sensor_format_name',
        ]));
    }
}
