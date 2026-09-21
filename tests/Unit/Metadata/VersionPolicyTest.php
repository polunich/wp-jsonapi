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
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Metadata\VersionPolicy;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use ReflectionClass;
use ReflectionParameter;
use ReflectionProperty;
use RuntimeException;
use UnexpectedValueException;

/**
 * @internal
 */
#[CoversClass(VersionPolicy::class)]
final class VersionPolicyTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(VersionPolicy::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    public function testTheAccessorTheFlagAndVersionOfKeepTheirSignatures(): void
    {
        $class = new ReflectionClass(VersionPolicy::class);

        self::assertSame(
            [['accessor', Closure::class, false], ['preconditionsRequired', 'bool', true]],
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
                $class->getMethod('versionOf')->getParameters(),
            ),
        );
        self::assertSame('string', (string) $class->getMethod('versionOf')->getReturnType());
    }

    #[DataProvider('versions')]
    public function testReadsTheVersionOfARecord(string $version): void
    {
        $policy = new VersionPolicy(static function (Pet $pet): string {
            return $pet->version;
        }, false);

        self::assertSame($version, $policy->versionOf(new Pet('1', 'Rex', null, $version)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function versions(): iterable
    {
        yield 'a revision number' => ['42'];
        yield 'a timestamp' => ['2026-09-16 10:00:00'];
        yield 'the empty string' => [''];
    }

    #[DataProvider('requirements')]
    public function testCarriesWhetherPreconditionsAreRequired(bool $required): void
    {
        self::assertSame($required, (new VersionPolicy(static function (Pet $pet): string {
            return $pet->version;
        }, $required))->preconditionsRequired);
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function requirements(): iterable
    {
        yield 'required' => [true];
        yield 'not required' => [false];
    }

    #[DataProvider('nonStrings')]
    public function testRefusesAnAccessorThatReturnsNoString(mixed $result, string $type): void
    {
        $policy = new VersionPolicy(static function (Pet $pet) use ($result): mixed {
            return $result;
        }, false);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage(sprintf(
            'the version accessor returned %s for a record of class %s, and a version is a string',
            $type,
            Pet::class,
        ));

        $policy->versionOf(new Pet('1', 'Rex'));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function nonStrings(): iterable
    {
        yield 'an int' => [42, 'int'];
        yield 'null' => [null, 'null'];
        yield 'an object' => [new DateTimeImmutable('2026-09-17'), DateTimeImmutable::class];
    }

    public function testLeavesAnExceptionOfTheAccessorAsItIs(): void
    {
        $failure = new RuntimeException('the revision table is locked');
        $policy = new VersionPolicy(static function (Pet $pet) use ($failure): string {
            throw $failure;
        }, false);

        try {
            $policy->versionOf(new Pet('1', 'Rex'));
            self::fail('the exception of the accessor was not thrown');
        } catch (RuntimeException $exception) {
            self::assertSame($failure, $exception);
        }
    }
}
