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

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\Linkage;
use ReflectionClass;
use ReflectionMethod;

/**
 * @internal
 */
#[CoversClass(Linkage::class)]
final class LinkageTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(Linkage::class))->isReadOnly());
    }

    public function testANonEmptyToOneLinkageNamesOneIdentifier(): void
    {
        $owner = new Identifier('customers', '9');

        $linkage = Linkage::toOne($owner);

        self::assertFalse($linkage->isToMany());
        self::assertSame($owner, $linkage->identifier());
        self::assertSame([$owner], $linkage->identifiers());
    }

    public function testAnEmptyToOneLinkageNamesNoIdentifier(): void
    {
        $linkage = Linkage::toOne(null);

        self::assertFalse($linkage->isToMany());
        self::assertNull($linkage->identifier());
        self::assertSame([], $linkage->identifiers());
    }

    public function testAnEmptyToManyLinkageNamesNoIdentifier(): void
    {
        $linkage = Linkage::toMany([]);

        self::assertTrue($linkage->isToMany());
        self::assertSame([], $linkage->identifiers());
    }

    public function testTheEmptyToOneAndTheEmptyToManyLinkageAreDifferentValues(): void
    {
        self::assertNotEquals(Linkage::toOne(null), Linkage::toMany([]));
    }

    public function testAToManyLinkageOfOneIdentifierIsNoToOneLinkage(): void
    {
        $tag = new Identifier('tags', '2');

        $toMany = Linkage::toMany([$tag]);

        self::assertTrue($toMany->isToMany());
        self::assertNotEquals(Linkage::toOne($tag), $toMany);
    }

    public function testAToManyLinkageKeepsTheOrderAndTheRepetitionOfTheRequest(): void
    {
        $three = new Identifier('tags', '3');
        $two = new Identifier('tags', '2');

        self::assertSame([$three, $two, $three], Linkage::toMany([$three, $two, $three])->identifiers());
    }

    #[DataProvider('toManyLinkages')]
    public function testRefusesToReadAToManyLinkageAsOneIdentifier(Linkage $linkage): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('a to-many linkage names a list of identifiers');

        $linkage->identifier();
    }

    /**
     * @return iterable<string, array{Linkage}>
     */
    public static function toManyLinkages(): iterable
    {
        yield 'empty' => [Linkage::toMany([])];
        yield 'one identifier' => [Linkage::toMany([new Identifier('tags', '2')])];
        yield 'two identifiers' => [Linkage::toMany([new Identifier('tags', '2'), new Identifier('tags', '3')])];
    }

    public function testTheLinkageCarriesNoLid(): void
    {
        $methods = array_map(
            static function (ReflectionMethod $method): string {
                return $method->getName();
            },
            (new ReflectionClass(Linkage::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        sort($methods);

        self::assertSame(['identifier', 'identifiers', 'isToMany', 'toMany', 'toOne'], $methods);
    }
}
