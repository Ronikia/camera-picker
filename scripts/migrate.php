<?php

declare(strict_types=1);

// Применяет ещё не применённые SQL-миграции из database/migrations по порядку имён.
// Каждая миграция выполняется в своей транзакции (DDL в PostgreSQL транзакционный).
// Использование: php scripts/migrate.php [--status]

require __DIR__ . '/../config/bootstrap.php';

use App\Database;

$pdo = Database::connection();

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        version     VARCHAR(255) PRIMARY KEY,
        applied_at  TIMESTAMPTZ NOT NULL DEFAULT now()
    )'
);

$applied = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$applied = array_flip($applied);

$files = glob(__DIR__ . '/../database/migrations/*.sql') ?: [];
sort($files, SORT_STRING);

$statusOnly = in_array('--status', $argv, true);
$ran = 0;

foreach ($files as $file) {
    $version = basename($file, '.sql');

    if (isset($applied[$version])) {
        if ($statusOnly) {
            echo "  [x] {$version}\n";
        }
        continue;
    }

    if ($statusOnly) {
        echo "  [ ] {$version}\n";
        continue;
    }

    echo "Applying {$version} ... ";
    $pdo->beginTransaction();
    try {
        $pdo->exec((string) file_get_contents($file));
        $stmt = $pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
        $stmt->execute(['version' => $version]);
        $pdo->commit();
        echo "OK\n";
        $ran++;
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo "FAILED\n";
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}

if (!$statusOnly) {
    echo $ran === 0 ? "Nothing to migrate.\n" : "Done: {$ran} migration(s) applied.\n";
}
