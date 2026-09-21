<?php

/**
 * wp-jsonapi: JSON:API 1.1 on the WordPress REST API.
 * Copyright (C) 2026 Polunich
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace Polunich\WpJsonApi\Tests\Unit\Http;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Http\UploadedFile;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * @internal
 */
#[CoversClass(UploadedFile::class)]
final class UploadedFileTest extends TestCase
{
    public function testTheClassIsReadonlyAndPublicApi(): void
    {
        $class = new ReflectionClass(UploadedFile::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertDoesNotMatchRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testTheConstructorDeclaresTheSignatureOfThePublicApi(): void
    {
        $parameters = array_map(
            static function (ReflectionParameter $parameter): array {
                return [$parameter->getName(), (string) $parameter->getType()];
            },
            (new ReflectionMethod(UploadedFile::class, '__construct'))->getParameters(),
        );

        self::assertSame([
            ['clientName', 'string'],
            ['clientMediaType', 'string'],
            ['size', 'int'],
            ['temporaryPath', 'string'],
            ['error', 'int'],
        ], $parameters);
    }

    public function testTakesEveryErrorCodeOfPhp(): void
    {
        foreach ([
            UPLOAD_ERR_INI_SIZE,
            UPLOAD_ERR_FORM_SIZE,
            UPLOAD_ERR_PARTIAL,
            UPLOAD_ERR_NO_FILE,
            UPLOAD_ERR_NO_TMP_DIR,
            UPLOAD_ERR_CANT_WRITE,
            UPLOAD_ERR_EXTENSION,
        ] as $error) {
            self::assertSame($error, (new UploadedFile('', '', 0, '', $error))->error);
        }
    }

    #[DataProvider('refusals')]
    public function testRefusesWhatPhpNeverStores(int $size, int $error, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new UploadedFile('rex.jpg', 'image/jpeg', $size, '', $error);
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function refusals(): iterable
    {
        yield 'a negative size' => [-1, UPLOAD_ERR_OK, 'the size of an uploaded file counts octets, got -1'];
        yield 'an error no code names' => [
            0,
            5,
            'the error of an uploaded file is an UPLOAD_ERR_* code of PHP, got 5',
        ];
    }
}
