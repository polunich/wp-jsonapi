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

namespace Polunich\WpJsonApi\Tests\Unit\Query;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Query\ParentScope;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(ParentScope::class)]
final class ParentScopeTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(ParentScope::class))->isReadOnly());
    }

    public function testNamesTheResourceTheRelatedCollectionHangsFrom(): void
    {
        $parent = new ParentScope('pets', '42', 'orders');

        self::assertSame('pets', $parent->type);
        self::assertSame('42', $parent->id);
        self::assertSame('orders', $parent->relationship);
    }

    #[DataProvider('ids')]
    public function testTakesEveryIdARouteCanCarry(string $id): void
    {
        self::assertSame($id, (new ParentScope('pets', $id, 'orders'))->id);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ids(): iterable
    {
        yield 'a number' => ['42'];
        yield 'a uuid' => ['018f8b0e-0e0a-7000-8000-000000000000'];
        yield 'a slug' => ['the-first-pet'];
    }

    #[DataProvider('illegalNames')]
    public function testRefusesATypeThatIsNoMemberName(string $type): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no resource type carries it');

        new ParentScope($type, '42', 'orders');
    }

    #[DataProvider('illegalNames')]
    public function testRefusesARelationshipThatIsNoMemberName(string $relationship): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no relationship carries it');

        new ParentScope('pets', '42', $relationship);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function illegalNames(): iterable
    {
        yield 'empty' => [''];
        yield 'a path' => ['pets.orders'];
        yield 'a reserved character' => ['pets,orders'];
        yield 'a space first' => [' pets'];
    }

    public function testRefusesAScopeThatHangsFromNoResource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('whose id is never empty');

        new ParentScope('pets', '', 'orders');
    }

    #[DataProvider('refusalsNamingOutsideValues')]
    public function testEscapesAControlCharacterOfARefusedNameInTheMessage(
        string $type,
        string $relationship,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new ParentScope($type, '42', $relationship);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function refusalsNamingOutsideValues(): iterable
    {
        yield 'a type' => ["pe\nts", 'orders', 'type "pe\nts" is no member name, so no resource type carries it'];
        yield 'a relationship' => [
            'pets',
            "or\tders",
            'relationship "or\tders" is no member name, so no relationship carries it',
        ];
    }
}
