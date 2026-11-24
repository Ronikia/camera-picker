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

        self::assertStringContainsString('cameras[0].body_type: "phone" is not allowed, expected one of: "mirrorless"', $errors);
        self::assertStringContainsString('cameras[0]: Additional object properties are not allowed: weight', $errors);
    }

    public function testMessagesIncludeActualValueAndResolvedPlaceholders(): void
    {
        $data = json_decode(
            '{"brands": [{"slug": "Canon!", "name": "Canon"}],
              "lenses": [{"brand": "sony", "mount": "sony-e", "slug": "x", "model": "X",
                          "focal_min_mm": 16, "focal_max_mm": 50, "aperture_wide": 3.5, "aperture_tele": 5.6,
                          "designed_for_format": "aps_c", "filter_mm": 5}]}',
            false,
        );

        $errors = implode("\n", (new SchemaValidator())->validate($data));

        self::assertStringContainsString('brands[0].slug: The string should match pattern: ^[a-z0-9]+(-[a-z0-9]+)*$ (got "Canon!")', $errors);
        self::assertStringContainsString('lenses[0].filter_mm: Number must be greater than or equal to 10 (got 5)', $errors);
    }

    public function testFractionalFilterSizeIsAllowed(): void
    {
        $data = json_decode(
            '{"lenses": [{"brand": "sony", "mount": "sony-e", "slug": "x", "model": "X",
                          "focal_min_mm": 16, "focal_max_mm": 50, "aperture_wide": 3.5, "aperture_tele": 5.6,
                          "designed_for_format": "aps_c", "filter_mm": 40.5}]}',
            false,
        );

        self::assertSame([], (new SchemaValidator())->validate($data));
    }

    public function testFormatPath(): void
    {
        self::assertSame('cameras[3].weight_g', SchemaValidator::formatPath(['cameras', 3, 'weight_g']));
        self::assertSame('(root)', SchemaValidator::formatPath([]));
    }
}
