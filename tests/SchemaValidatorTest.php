<?php

declare(strict_types=1);

namespace Tests;

use App\Import\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SchemaValidatorTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function bundledFiles(): iterable
    {
        yield 'example' => [__DIR__ . '/../data/schema/example.json'];
        yield 'seeds'   => [__DIR__ . '/../database/seeds/catalog.json'];
    }

    #[DataProvider('bundledFiles')]
    public function testBundledFilesMatchSchema(string $file): void
    {
        $data = json_decode((string) file_get_contents($file), false, 512, JSON_THROW_ON_ERROR);

        self::assertSame([], (new SchemaValidator())->validate($data));
    }

    public function testReportsUnknownFieldsAndBadValuesWithPaths(): void
    {
        $data = json_decode(
            '{"cameras": [{"brand": "sony", "slug": "x", "model": "X", "body_type": "phone",
                           "sensor_format": "full_frame", "weight": 500}]}',
            false,
        );

        $errors = implode("\n", (new SchemaValidator())->validate($data));

        self::assertStringContainsString('cameras[0].body_type', $errors);
        self::assertStringContainsString('cameras[0]: Additional object properties are not allowed: weight', $errors);
    }

    public function testFormatPath(): void
    {
        self::assertSame('cameras[3].weight_g', SchemaValidator::formatPath(['cameras', 3, 'weight_g']));
        self::assertSame('(root)', SchemaValidator::formatPath([]));
    }
}
