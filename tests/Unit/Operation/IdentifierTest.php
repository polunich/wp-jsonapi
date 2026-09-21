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
use Polunich\WpJsonApi\Operation\Identifier;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;

/**
 * @internal
 */
#[CoversClass(Identifier::class)]
final class IdentifierTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(Identifier::class))->isReadOnly());
    }

    public function testIdentifiesAResourceByTypeAndId(): void
    {
        $identifier = new Identifier(type: 'customers', id: '9');

        self::assertSame('customers', $identifier->type);
        self::assertSame('9', $identifier->id);
    }

    public function testCarriesNoLid(): void
    {
        $class = new ReflectionClass(Identifier::class);

        self::assertSame(
            [['type', 'string'], ['id', 'string']],
            array_map(
                static function (ReflectionParameter $parameter): array {
                    return [$parameter->getName(), (string) $parameter->getType()];
                },
                $class->getConstructor()?->getParameters() ?? [],
            ),
        );
        self::assertSame(
            [['type', 'string'], ['id', 'string']],
            array_map(
                static function (ReflectionProperty $property): array {
                    return [$property->getName(), (string) $property->getType()];
                },
                $class->getProperties(),
            ),
        );
        self::assertSame(
            ['__construct'],
            array_map(static function (ReflectionMethod $method): string {
                return $method->getName();
            }, $class->getMethods()),
        );
    }

    /**
     * only the empty string is refused; `0`, falsy in PHP, is an id
     */
    #[DataProvider('ids')]
    public function testTakesEveryNonEmptyId(string $id): void
    {
        self::assertSame($id, (new Identifier('customers', $id))->id);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ids(): iterable
    {
        yield 'zero' => ['0'];
        yield 'a number' => ['42'];
        yield 'a uuid' => ['550e8400-e29b-41d4-a716-446655440000'];
        yield 'a space' => [' '];
    }

    public function testRefusesAnEmptyType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('an identifier names the type of its resource, and a type is never empty');

        new Identifier(type: '', id: '9');
    }

    public function testRefusesAnEmptyId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('an identifier of type "customers" carries an empty id, which identifies no'
            . ' resource');

        new Identifier(type: 'customers', id: '');
    }
    public function testEscapesAControlCharacterOfTheTypeInTheMessage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('an identifier of type "cust\tomers" carries an empty id, which identifies no'
            . ' resource');

        new Identifier(type: "cust\tomers", id: '');
    }
}
