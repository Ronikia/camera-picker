<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Минимальный роутер: точное совпадение метода и пути.
 * Параметры в пути (/api/cameras/{slug}) добавим, когда появится страница камеры.
 */
final class Router
{
    /** @var array<string, array<string, callable(array<string, mixed>): JsonResponse>> */
    private array $routes = [];

    /**
     * @param callable(array<string, mixed>): JsonResponse $handler получает query-параметры
     */
    public function get(string $path, callable $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    /**
     * @param array<string, mixed> $query
     */
    public function dispatch(string $method, string $path, array $query): JsonResponse
    {
        $path = rtrim($path, '/') ?: '/';

        $handler = $this->routes[$method][$path] ?? null;
        if ($handler !== null) {
            return $handler($query);
        }

        foreach ($this->routes as $routes) {
            if (isset($routes[$path])) {
                return JsonResponse::error(405, 'Method not allowed');
            }
        }

        return JsonResponse::error(404, 'Not found');
    }
}
