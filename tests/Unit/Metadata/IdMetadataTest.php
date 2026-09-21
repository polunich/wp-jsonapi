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

namespace Polunich\WpJsonApi\Tests\Unit\Metadata;

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\DigitsCodec;
use Polunich\WpJsonApi\Codec\SegmentCodec;
use Polunich\WpJsonApi\Codec\UuidCodec;
use Polunich\WpJsonApi\Metadata\IdMetadata;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use ReflectionClass;
use ReflectionParameter;
use ReflectionProperty;
use UnexpectedValueException;

/**
 * @internal
 */
#[CoversClass(IdMetadata::class)]
final class IdMetadataTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(IdMetadata::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    public function testTheCodecTheAccessorTheFlagAndIdOfKeepTheirSignatures(): void
    {
        $class = new ReflectionClass(IdMetadata::class);

        self::assertSame(
            [
                ['codec', SegmentCodec::class, true],
                ['accessor', Closure::class, false],
                ['globallyUnique', 'bool', true],
                ['operators', 'array', true],
            ],
            array_map(
                static function (ReflectionProperty $property): array {
                    return [
                        $property->getName(),
                        (string) $property->getType(),
                        $property->isPublic(),
                    ];
                },
                $class->getProperties(),
            ),
        );
        self::assertSame(
            [['record', 'object']],
            array_map(
                static function (ReflectionParameter $parameter): array {
                    return [$parameter->getName(), (string) $parameter->getType()];
                },
                $class->getMethod('idOf')->getParameters(),
            ),
        );
        self::assertSame('string', (string) $class->getMethod('idOf')->getReturnType());
    }

    public function testCarriesTheCodecAndWhetherTheIdsAreGloballyUnique(): void
    {
        $codec = new UuidCodec();
        $id = new IdMetadata($codec, static function (Pet $pet): string {
            return $pet->id;
        }, true);

        self::assertSame($codec, $id->codec);
        self::assertTrue($id->globallyUnique);
        self::assertFalse((new IdMetadata(new DigitsCodec(), static function (Pet $pet): string {
            return $pet->id;
        }, false))->globallyUnique);
    }

    public function testCarriesTheOperatorsItIsFilteredBy(): void
    {
        $accessor = static function (Pet $pet): string {
            return $pet->id;
        };

        self::assertSame([], (new IdMetadata(new DigitsCodec(), $accessor, false))->operators);
        self::assertSame(
            ['@eq', '@in'],
            (new IdMetadata(new DigitsCodec(), $accessor, false, ['@eq', '@in']))->operators,
        );
    }

    public function testRefusesAnOperatorDeclaredTwice(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the id declares operator "@in" twice');

        new IdMetadata(new DigitsCodec(), static function (Pet $pet): string {
            return $pet->id;
        }, false, ['@in', '@eq', '@in']);
    }

    #[DataProvider('ids')]
    public function testReadsTheIdOfARecord(string $value): void
    {
        $id = new IdMetadata(new DigitsCodec(), static function (Pet $pet): string {
            return $pet->id;
        }, false);

        self::assertSame($value, $id->idOf(new Pet($value, 'Rex')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ids(): iterable
    {
        yield 'digits' => ['42'];
        yield 'a UUID' => ['550e8400-e29b-41d4-a716-446655440000'];
        yield 'zero' => ['0'];
    }

    #[DataProvider('impossibleIds')]
    public function testRefusesAnAccessorThatReturnsNoId(mixed $result, string $message): void
    {
        $id = new IdMetadata(new DigitsCodec(), static function (Pet $pet) use ($result): mixed {
            return $result;
        }, false);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        $id->idOf(new Pet('1', 'Rex'));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function impossibleIds(): iterable
    {
        yield 'an int' => [
            42,
            'the id accessor returned int for a record of class ' . Pet::class . ', and an id is a string',
        ];
        yield 'null' => [
            null,
            'the id accessor returned null for a record of class ' . Pet::class . ', and an id is a string',
        ];
        yield 'the empty string' => [
            '',
            'the id accessor returned the empty string for a record of class ' . Pet::class,
        ];
    }
}
