<?php

declare(strict_types=1);

// Импорт каталога из JSON-файлов.
//
//   php scripts/import.php [--dry-run] [--force] <file.json|directory> ...
//
//   --dry-run  проверить и показать, что изменится, ничего не записывая
//   --force    перезаписывать и записи с verified = true
//
// Каждый файл импортируется в отдельной транзакции: при любой ошибке файл не пишется целиком.
// Код выхода: 0 — всё хорошо, 1 — были ошибки, 2 — неверные аргументы.

require __DIR__ . '/../config/bootstrap.php';

use App\Database;
use App\Import\CatalogImporter;
use App\Import\SchemaValidator;

$args = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$force = in_array('--force', $args, true);
$paths = array_values(array_filter($args, static fn (string $a): bool => !str_starts_with($a, '--')));

$unknown = array_diff(array_filter($args, static fn (string $a): bool => str_starts_with($a, '--')), ['--dry-run', '--force']);
if ($paths === [] || $unknown !== []) {
    fwrite(STDERR, "Usage: php scripts/import.php [--dry-run] [--force] <file.json|directory> ...\n");
    exit(2);
}

// Каталог разворачиваем в список *.json по алфавиту
$files = [];
foreach ($paths as $path) {
    if (is_dir($path)) {
        $found = glob(rtrim($path, '/') . '/*.json') ?: [];
        sort($found, SORT_STRING);
        array_push($files, ...$found);
    } elseif (is_file($path)) {
        $files[] = $path;
    } else {
        fwrite(STDERR, "Not found: {$path}\n");
        exit(2);
    }
}

$pdo = Database::connection();
$schemaValidator = new SchemaValidator();
$importer = new CatalogImporter($pdo);
$failed = 0;

foreach ($files as $file) {
    echo "== {$file}" . ($dryRun ? ' (dry run)' : '') . "\n";

    try {
        $raw = (string) file_get_contents($file);
        $asObjects = json_decode($raw, false, 512, JSON_THROW_ON_ERROR); // для валидатора схемы
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);       // для импорта
    } catch (JsonException $e) {
        echo "  ERROR invalid JSON: {$e->getMessage()}\n";
        $failed++;
        continue;
    }

    $errors = $schemaValidator->validate($asObjects);
    // Смысловые проверки имеют смысл, только если структура верна
    if ($errors === []) {
        $errors = $importer->validate($data);
    }

    if ($errors !== []) {
        foreach ($errors as $error) {
            echo "  ERROR {$error}\n";
        }
        echo '  ' . count($errors) . " error(s), file skipped\n";
        $failed++;
        continue;
    }

    $pdo->beginTransaction();
    try {
        $stats = $importer->import($data, $force);
        $dryRun ? $pdo->rollBack() : $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo "  ERROR database: {$e->getMessage()}\n";
        $failed++;
        continue;
    }

    foreach ($importer->warnings() as $warning) {
        echo "  WARN  {$warning}\n";
    }
    foreach ($stats as $table => $s) {
        printf(
            "  %-8s inserted %d, updated %d, unchanged %d, skipped %d\n",
            $table, $s['inserted'], $s['updated'], $s['unchanged'], $s['skipped'],
        );
    }
    echo "  OK\n";
}

exit($failed > 0 ? 1 : 0);
