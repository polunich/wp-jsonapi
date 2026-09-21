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

namespace Polunich\WpJsonApi\Tests\Unit\Operation;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Operation\Precondition;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;

/**
 * @internal
 */
#[CoversClass(Precondition::class)]
final class PreconditionTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(Precondition::class))->isReadOnly());
    }

    /**
     * the private constructor keeps an empty list of versions out
     */
    public function testIsBuiltByItsTwoFactoriesOnly(): void
    {
        $class = new ReflectionClass(Precondition::class);
        $methods = array_map(
            static function (ReflectionMethod $method): string {
                return $method->getName();
            },
            $class->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        sort($methods);

        self::assertSame(['admits', 'anyVersion', 'listedVersions'], $methods);
        self::assertTrue($class->getConstructor()?->isPrivate());
    }

    public function testVersionsTheTwoFactoriesAndAdmitsKeepTheirSignatures(): void
    {
        $class = new ReflectionClass(Precondition::class);
        $signature = static function (string $method) use ($class): array {
            return array_map(
                static function (ReflectionParameter $parameter): array {
                    return [$parameter->getName(), (string) $parameter->getType()];
                },
                $class->getMethod($method)->getParameters(),
            );
        };

        self::assertSame(
            [['versions', '?array', true]],
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
        self::assertSame([], $signature('anyVersion'));
        self::assertSame([['versions', 'array']], $signature('listedVersions'));
        self::assertSame([['version', 'string']], $signature('admits'));
        self::assertSame('bool', (string) $class->getMethod('admits')->getReturnType());
    }

    #[DataProvider('versions')]
    public function testAnyVersionAdmitsEveryVersion(string $version): void
    {
        $precondition = Precondition::anyVersion();

        self::assertNull($precondition->versions);
        self::assertTrue($precondition->admits($version));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function versions(): iterable
    {
        yield 'a number' => ['42'];
        yield 'a timestamp' => ['2026-09-16 10:00:00'];
        yield 'the empty string' => [''];
    }

    public function testListedVersionsAdmitOnlyTheListedVersions(): void
    {
        $precondition = Precondition::listedVersions(versions: ['v7', 'v8']);

        self::assertSame(['v7', 'v8'], $precondition->versions);
        self::assertTrue($precondition->admits('v7'));
        self::assertTrue($precondition->admits('v8'));
        self::assertFalse($precondition->admits('v9'));
        self::assertFalse($precondition->admits('V7'));
        self::assertFalse($precondition->admits(''));
    }

    /**
     * `assertSame` pins the keys too: `array_unique()` alone would leave a gap
     *
     * @param list<string> $versions
     * @param list<string> $kept
     */
    #[DataProvider('repeatedVersions')]
    public function testListedVersionsKeepEachVersionOnceInOrder(array $versions, array $kept): void
    {
        self::assertSame($kept, Precondition::listedVersions($versions)->versions);
    }

    /**
     * @return iterable<string, array{list<string>, list<string>}>
     */
    public static function repeatedVersions(): iterable
    {
        yield 'a repeated first version' => [['v7', 'v7', 'v8'], ['v7', 'v8']];
        yield 'a version repeated after another' => [['v8', 'v7', 'v8', 'v7'], ['v8', 'v7']];
        yield 'numeric strings that differ as strings' => [['10', '1e1', '010', '10'], ['10', '1e1', '010']];
        yield 'one version' => [['v7'], ['v7']];
    }

    public function testComparesVersionsAsStrings(): void
    {
        $precondition = Precondition::listedVersions(['10']);

        self::assertTrue($precondition->admits('10'));
        self::assertFalse($precondition->admits('1e1'));
        self::assertFalse($precondition->admits('010'));
        self::assertFalse($precondition->admits('10.0'));
    }

    public function testTheEmptyStringIsAVersion(): void
    {
        $precondition = Precondition::listedVersions(['']);

        self::assertSame([''], $precondition->versions);
        self::assertTrue($precondition->admits(''));
        self::assertFalse($precondition->admits('0'));
    }

    public function testRefusesAListOfNoVersion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a listed precondition names one or more versions, and this one names none');

        Precondition::listedVersions([]);
    }
}
