<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\HttpException;
use App\Http\JsonResponse;
use App\Repository\CameraRepository;

final class CameraController
{
    private const BODY_TYPES = ['mirrorless', 'dslr', 'compact'];
    private const SENSOR_FORMATS = ['medium_format', 'full_frame', 'aps_c', 'aps_c_canon', 'm43', 'one_inch'];
    private const SLUG_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';
    private const MAX_LIMIT = 100;

    public function __construct(private readonly CameraRepository $cameras)
    {
    }

    /**
     * GET /api/cameras?brand=sony&mount=sony-e&type=mirrorless&sensor=full_frame&limit=20&offset=0
     *
     * @param array<string, mixed> $query
     */
    public function index(array $query): JsonResponse
    {
        $filters = [];

        if (($brand = $this->stringParam($query, 'brand')) !== null) {
            $filters['brand'] = $this->slug($brand, 'brand');
        }
        if (($mount = $this->stringParam($query, 'mount')) !== null) {
            $filters['mount'] = $this->slug($mount, 'mount');
        }
        if (($type = $this->stringParam($query, 'type')) !== null) {
            $filters['body_type'] = $this->oneOf($type, self::BODY_TYPES, 'type');
        }
        if (($sensor = $this->stringParam($query, 'sensor')) !== null) {
            $filters['sensor_format'] = $this->oneOf($sensor, self::SENSOR_FORMATS, 'sensor');
        }

        $limit = $this->intParam($query, 'limit', 50, 1, self::MAX_LIMIT);
        $offset = $this->intParam($query, 'offset', 0, 0, PHP_INT_MAX);

        $result = $this->cameras->search($filters, $limit, $offset);

        return new JsonResponse([
            'data' => $result['items'],
            'meta' => ['total' => $result['total'], 'limit' => $limit, 'offset' => $offset],
        ]);
    }

    // ------------------------------------------------------------------ валидация параметров

    /**
     * @param array<string, mixed> $query
     */
    private function stringParam(array $query, string $name): ?string
    {
        if (!isset($query[$name]) || $query[$name] === '') {
            return null;
        }
        // ?brand[]=x даёт массив — отвергаем
        if (!is_string($query[$name])) {
            throw new HttpException(400, "Parameter \"{$name}\" must be a string");
        }

        return $query[$name];
    }

    private function slug(string $value, string $name): string
    {
        if (strlen($value) > 128 || !preg_match(self::SLUG_PATTERN, $value)) {
            throw new HttpException(400, "Parameter \"{$name}\" must be a slug (a-z, 0-9, -)");
        }

        return $value;
    }

    /**
     * @param list<string> $allowed
     */
    private function oneOf(string $value, array $allowed, string $name): string
    {
        if (!in_array($value, $allowed, true)) {
            throw new HttpException(400, "Parameter \"{$name}\" must be one of: " . implode(', ', $allowed));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function intParam(array $query, string $name, int $default, int $min, int $max): int
    {
        $raw = $this->stringParam($query, $name);
        if ($raw === null) {
            return $default;
        }

        $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
        if ($value === false) {
            throw new HttpException(400, "Parameter \"{$name}\" must be an integer between {$min} and {$max}");
        }

        return $value;
    }
}
