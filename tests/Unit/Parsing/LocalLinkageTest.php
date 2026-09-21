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

namespace Polunich\WpJsonApi\Tests\Unit\Parsing;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\Linkage;
use Polunich\WpJsonApi\Parsing\LocalIdentifier;
use Polunich\WpJsonApi\Parsing\LocalLinkage;

/**
 * @internal
 */
#[CoversClass(LocalLinkage::class)]
final class LocalLinkageTest extends TestCase
{
    public function testAToOneLinkageNamesOneIdentifierOrNone(): void
    {
        $identifier = LocalIdentifier::ofId('customers', '9');
        $empty = LocalLinkage::toOne(null);
        $linkage = LocalLinkage::toOne($identifier);

        self::assertFalse($empty->isToMany());
        self::assertSame([], $empty->identifiers());
        self::assertFalse($linkage->isToMany());
        self::assertSame([$identifier], $linkage->identifiers());
    }

    public function testAToManyLinkageNamesItsIdentifiersInTheOrderOfTheRequest(): void
    {
        $first = LocalIdentifier::ofId('tags', '2');
        $second = LocalIdentifier::ofId('tags', '3');
        $linkage = LocalLinkage::toMany([$first, $second]);

        self::assertTrue($linkage->isToMany());
        self::assertSame([$first, $second], $linkage->identifiers());
        self::assertTrue(LocalLinkage::toMany([])->isToMany());
    }

    /**
     * a to-many linkage of one identifier stays to-many
     */
    public function testALinkageOfIdsBecomesTheLinkageAnOperationCarries(): void
    {
        self::assertEquals(
            Linkage::toOne(new Identifier('customers', '9')),
            LocalLinkage::toOne(LocalIdentifier::ofId('customers', '9'))->toLinkage(),
        );
        self::assertEquals(Linkage::toOne(null), LocalLinkage::toOne(null)->toLinkage());
        self::assertEquals(
            Linkage::toMany([new Identifier('tags', '2')]),
            LocalLinkage::toMany([LocalIdentifier::ofId('tags', '2')])->toLinkage(),
        );
        self::assertEquals(Linkage::toMany([]), LocalLinkage::toMany([])->toLinkage());
    }

    public function testALinkageReplacesEveryLidItNamesByItsId(): void
    {
        $linkage = LocalLinkage::toMany([
            LocalIdentifier::ofId('tags', '2'),
            LocalIdentifier::ofLid('tags', 'fresh'),
        ]);

        $built = $linkage->toLinkage(static function (string $type, string $lid): string {
            return $type . '-' . $lid;
        });

        self::assertEquals(
            Linkage::toMany([new Identifier('tags', '2'), new Identifier('tags', 'tags-fresh')]),
            $built,
        );
    }

    #[DataProvider('cardinalities')]
    public function testALinkageOfALidRefusesToBecomeALinkageWithoutTheIdOfItsLid(bool $toMany): void
    {
        $identifier = LocalIdentifier::ofLid('tags', 'fresh');
        $linkage = $toMany ? LocalLinkage::toMany([$identifier]) : LocalLinkage::toOne($identifier);

        $this->expectException(LogicException::class);

        $linkage->toLinkage();
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function cardinalities(): array
    {
        return [
            'to-one' => [false],
            'to-many' => [true],
        ];
    }
}
