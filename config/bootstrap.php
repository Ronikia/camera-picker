<?php

declare(strict_types=1);

// Общая точка инициализации для веба и CLI-скриптов:
// автозагрузка Composer + переменные окружения из .env.

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();
$dotenv->required(['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD']);
$dotenv->required('DB_PORT')->isInteger();
