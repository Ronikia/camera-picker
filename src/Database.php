<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Единственное место, где создаётся PDO-подключение.
 */
final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $_ENV['DB_HOST'],
                (int) $_ENV['DB_PORT'],
                $_ENV['DB_NAME'],
            );

            self::$pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASSWORD'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Настоящие подготовленные запросы на стороне сервера, без эмуляции
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }

        return self::$pdo;
    }
}
