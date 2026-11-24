<?php

declare(strict_types=1);

namespace App\Import;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Errors\ValidationError;
use Opis\JsonSchema\Validator;

/**
 * Структурная проверка файла импорта по data/schema/catalog.schema.json:
 * типы, обязательные поля, диапазоны, допустимые значения, лишние поля.
 */
final class SchemaValidator
{
    private const SCHEMA_ID = 'https://camera-picker.local/schema/catalog.schema.json';

    private Validator $validator;

    public function __construct(?string $schemaPath = null)
    {
        $schemaPath ??= dirname(__DIR__, 2) . '/data/schema/catalog.schema.json';

        $this->validator = new Validator();
        $this->validator->setMaxErrors(100);
        $this->validator->setStopAtFirstError(false);
        $this->validator->resolver()->registerFile(self::SCHEMA_ID, $schemaPath);
    }

    /**
     * @param mixed $data результат json_decode() БЕЗ assoc (объекты нужны валидатору)
     * @return list<string> сообщения вида "cameras[3].weight_g: ..."; пустой массив = валидно
     */
    public function validate(mixed $data): array
    {
        $result = $this->validator->validate($data, self::SCHEMA_ID);
        if ($result->isValid()) {
            return [];
        }

        $formatter = new ErrorFormatter();
        $formatted = $formatter->format(
            $result->error(),
            true,
            // formatErrorMessage подставляет {pattern}, {min} и т.п. в текст библиотеки
            static fn (ValidationError $e, ?string $message = null): string
                => self::describe($e, $formatter->formatErrorMessage($e, $message)),
            static fn (ValidationError $e): string => self::formatPath($e->data()->fullPath()),
        );

        $messages = [];
        foreach ($formatted as $path => $errors) {
            foreach (array_unique($errors) as $message) {
                $messages[] = "{$path}: {$message}";
            }
        }

        return $messages;
    }

    /**
     * Сообщение с фактическим значением из файла — чтобы ошибку было видно без поиска по JSON.
     *   enum:   "1080p" is not allowed, expected one of: "hd", "fhd", ...
     *   прочие: <сообщение библиотеки> (got 40.5)
     */
    private static function describe(ValidationError $error, string $message): string
    {
        $value = $error->data()->value();

        if ($error->keyword() === 'enum') {
            $allowed = $error->schema()->info()->data()->enum ?? [];
            return sprintf(
                '%s is not allowed, expected one of: %s',
                self::show($value),
                implode(', ', array_map(self::show(...), $allowed)),
            );
        }

        // Для объектов/массивов значение слишком длинное — сообщения библиотеки достаточно
        if ($value === null || is_scalar($value)) {
            return sprintf('%s (got %s)', $message, self::show($value));
        }

        return $message;
    }

    private static function show(mixed $value): string
    {
        $json = (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return mb_strlen($json) > 60 ? mb_substr($json, 0, 57) . '..."' : $json;
    }

    /**
     * ["cameras", 3, "weight_g"] -> "cameras[3].weight_g"
     *
     * @param array<int|string> $segments
     */
    public static function formatPath(array $segments): string
    {
        $path = '';
        foreach ($segments as $segment) {
            $path .= is_int($segment) ? "[{$segment}]" : ($path === '' ? '' : '.') . $segment;
        }

        return $path === '' ? '(root)' : $path;
    }
}
