<?php

declare(strict_types=1);

// Фронт-контроллер. Для встроенного сервера PHP это ещё и router-скрипт:
//   php -S localhost:8000 -t public public/index.php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (PHP_SAPI === 'cli-server') {
    // Существующие статические файлы (css/js/img) отдаёт сам сервер
    if ($path !== '/' && is_file(__DIR__ . $path)) {
        return false;
    }
    // Всё, что не API, — страница каталога
    if (!str_starts_with($path, '/api/')) {
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/index.html');
        return true;
    }
}

require __DIR__ . '/../config/bootstrap.php';

use App\Controller\CameraController;
use App\Database;
use App\Http\HttpException;
use App\Http\JsonResponse;
use App\Http\Router;
use App\Repository\CameraRepository;

$debug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL);

try {
    $router = new Router();

    $router->get('/api/cameras', static fn (array $query): JsonResponse =>
        (new CameraController(new CameraRepository(Database::connection())))->index($query));

    $response = $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path, $_GET);
} catch (HttpException $e) {
    $response = JsonResponse::error($e->status, $e->getMessage());
} catch (Throwable $e) {
    // Детали ошибки — только в лог (и в ответ при APP_DEBUG), клиенту — общий текст
    error_log((string) $e);
    $response = JsonResponse::error(500, $debug ? $e->getMessage() : 'Internal server error');
}

$response->send();
