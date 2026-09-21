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

namespace Polunich\WpJsonApi\Tests\Unit\Store;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Store\Slice;
use Polunich\WpJsonApi\Tests\Fixtures\Store\PortSignatureReader;
use Polunich\WpJsonApi\Tests\Fixtures\Store\StoredPet;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;

/**
 * @internal
 */
#[CoversClass(Slice::class)]
final class SliceTest extends TestCase
{
    public function testTheClassIsFinalAndReadonly(): void
    {
        $class = new ReflectionClass(Slice::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
    }

    public function testTheClassIsPublicApi(): void
    {
        self::assertStringNotContainsString('@internal', (string) (new ReflectionClass(Slice::class))->getDocComment());
    }

    /**
     * the parameter names are public API, and so are the properties they promote
     */
    public function testTheConstructorPromotesTheRecordsTheTotalAndTheEstimatedTotal(): void
    {
        $class = new ReflectionClass(Slice::class);

        self::assertSame(
            [['records', 'array', false, null], ['total', '?int', true, null], ['estimatedTotal', '?int', true, null]],
            array_map(
                static function (ReflectionParameter $parameter): array {
                    return [
                        $parameter->getName(),
                        (string) $parameter->getType(),
                        $parameter->isOptional(),
                        $parameter->isOptional() ? $parameter->getDefaultValue() : null,
                    ];
                },
                $class->getConstructor()?->getParameters() ?? [],
            ),
        );
        self::assertSame(
            ['records', 'total', 'estimatedTotal'],
            array_map(
                static function (ReflectionProperty $property): string {
                    return $property->getName();
                },
                $class->getProperties(ReflectionProperty::IS_PUBLIC),
            ),
        );
    }

    public function testTheRecordsAreATemplateOfObject(): void
    {
        $class = new ReflectionClass(Slice::class);

        self::assertSame(['TRecord of object'], PortSignatureReader::tags($class, 'template', true));
        self::assertSame(
            ['list<TRecord>', '?int', '?int'],
            PortSignatureReader::tags(new ReflectionMethod(Slice::class, '__construct'), 'param'),
        );
    }

    public function testCarriesTheRecordsAndTheSizes(): void
    {
        $records = [new StoredPet('1', '1', 'Rex'), new StoredPet('2', '1', 'Whiskers')];

        $slice = new Slice(records: $records, total: 40, estimatedTotal: 50);

        self::assertSame($records, $slice->records);
        self::assertSame(40, $slice->total);
        self::assertSame(50, $slice->estimatedTotal);
    }

    public function testGivesNoSizeUnlessTheStoreDoes(): void
    {
        $slice = new Slice([]);

        self::assertSame([], $slice->records);
        self::assertNull($slice->total);
        self::assertNull($slice->estimatedTotal);
    }

    public function testTakesTheSizesOfAnEmptyCollection(): void
    {
        $slice = new Slice([], 0, 0);

        self::assertSame(0, $slice->total);
        self::assertSame(0, $slice->estimatedTotal);
    }

    #[DataProvider('negativeSizes')]
    public function testRefusesANegativeSize(?int $total, ?int $estimatedTotal, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new Slice([], $total, $estimatedTotal);
    }

    /**
     * @return iterable<string, array{?int, ?int, string}>
     */
    public static function negativeSizes(): iterable
    {
        yield 'a negative total' => [-1, null, 'a collection holds -1 records, which is fewer than none'];
        yield 'the smallest total' => [PHP_INT_MIN, 3, 'a collection holds ' . PHP_INT_MIN . ' records'];
        yield 'a negative estimated total' => [
            null,
            -1,
            'a collection is estimated to hold -1 records, which is fewer than none',
        ];
        yield 'a negative estimated total beside a total' => [3, -2, 'a collection is estimated to hold -2 records'];
    }
}
