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

namespace Polunich\WpJsonApi\Tests\Unit\Document;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Document\Links;
use Polunich\WpJsonApi\Document\RelationshipObject;
use Polunich\WpJsonApi\Document\ResourceIdentifierObject;

/**
 * @internal
 */
#[CoversClass(RelationshipObject::class)]
final class RelationshipObjectTest extends TestCase
{
    public function testARelationshipWithoutLinkageCarriesNone(): void
    {
        $relationship = RelationshipObject::withoutLinkage(self::links());

        self::assertFalse($relationship->hasLinkage());
        self::assertNull($relationship->linkage());
        self::assertNull($relationship->meta);
    }

    public function testAnEmptyToOneRelationshipDiffersFromAnAbsentOne(): void
    {
        $empty = RelationshipObject::toOne(self::links(), null);
        $absent = RelationshipObject::withoutLinkage(self::links());

        self::assertTrue($empty->hasLinkage());
        self::assertNull($empty->linkage());
        self::assertFalse($absent->hasLinkage());
        self::assertNull($absent->linkage());
    }

    public function testAToOneRelationshipCarriesOneIdentifier(): void
    {
        $identifier = new ResourceIdentifierObject('customers', '9');

        $relationship = RelationshipObject::toOne(self::links(), $identifier);

        self::assertTrue($relationship->hasLinkage());
        self::assertSame($identifier, $relationship->linkage());
    }

    public function testAnEmptyToManyRelationshipCarriesAnEmptyList(): void
    {
        $relationship = RelationshipObject::toMany(self::links(), []);

        self::assertTrue($relationship->hasLinkage());
        self::assertSame([], $relationship->linkage());
    }

    public function testAToManyRelationshipKeepsTheOrderOfTheIdentifiers(): void
    {
        $first = new ResourceIdentifierObject('orders', '5');
        $second = new ResourceIdentifierObject('orders', '12');

        $relationship = RelationshipObject::toMany(self::links(), [$first, $second]);

        self::assertSame([$first, $second], $relationship->linkage());
    }

    /**
     * the meta holds a name of digits, built at runtime so PHPStan cannot narrow it to a string key
     */
    public function testEveryConstructorKeepsTheMeta(): void
    {
        $meta = self::meta(['0', 'count']);

        self::assertSame($meta, RelationshipObject::withoutLinkage(self::links(), $meta)->meta);
        self::assertSame($meta, RelationshipObject::toOne(self::links(), null, $meta)->meta);
        self::assertSame($meta, RelationshipObject::toMany(self::links(), [], $meta)->meta);
    }

    /**
     * @param ?array<array-key, mixed> $meta
     */
    #[DataProvider('emptyMembers')]
    public function testARelationshipObjectWithoutLinksDataAndMetaIsRejected(Links $links, ?array $meta): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a relationship object carries at least one of links, data and meta; this one'
            . ' carries none');

        RelationshipObject::withoutLinkage($links, $meta);
    }

    /**
     * @return iterable<string, array{Links, ?array<array-key, mixed>}>
     */
    public static function emptyMembers(): iterable
    {
        yield 'nothing at all' => [new Links([]), null];
        yield 'an empty meta object, which is not written' => [new Links([]), []];
    }

    #[DataProvider('linksWithoutSelfOrRelated')]
    public function testLinksWithoutSelfOrRelatedAreRejected(Links $links): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the links of a relationship object carry a self or a related link wherever'
            . ' they carry any');

        RelationshipObject::toOne($links, null);
    }

    /**
     * @return iterable<string, array{Links}>
     */
    public static function linksWithoutSelfOrRelated(): iterable
    {
        yield 'a pagination link that does not exist' => [new Links(['next' => null])];
        yield 'pagination links only' => [new Links(['first' => 'f', 'next' => 'n'])];
        yield 'a self link that does not exist' => [new Links(['self' => null])];
        yield 'a self and a related link that do not exist' => [new Links(['self' => null, 'related' => null])];
    }

    #[DataProvider('sufficientMembers')]
    public function testOneOfTheMembersIsEnough(callable $construct): void
    {
        $this->expectNotToPerformAssertions();

        $construct();
    }

    /**
     * @return iterable<string, array{callable(): RelationshipObject}>
     */
    public static function sufficientMembers(): iterable
    {
        yield 'a self link alone' => [static function (): RelationshipObject {
            return RelationshipObject::withoutLinkage(new Links(['self' => 's']));
        }];
        yield 'a related link alone' => [static function (): RelationshipObject {
            return RelationshipObject::withoutLinkage(new Links(['related' => 'r']));
        }];
        yield 'a related link beside a self link that does not exist' => [static function (): RelationshipObject {
            return RelationshipObject::withoutLinkage(new Links(['self' => null, 'related' => 'r']));
        }];
        yield 'linkage alone' => [static function (): RelationshipObject {
            return RelationshipObject::toOne(new Links([]), null);
        }];
        yield 'meta alone' => [static function (): RelationshipObject {
            return RelationshipObject::withoutLinkage(new Links([]), ['count' => 2]);
        }];
    }

    #[DataProvider('constructors')]
    public function testEveryConstructorRefusesPaginationLinksAlone(callable $construct): void
    {
        $this->expectException(InvalidArgumentException::class);

        $construct(new Links(['next' => null]));
    }

    /**
     * @return iterable<string, array{callable(Links): RelationshipObject}>
     */
    public static function constructors(): iterable
    {
        yield 'withoutLinkage' => [static function (Links $links): RelationshipObject {
            return RelationshipObject::withoutLinkage($links);
        }];
        yield 'toOne' => [static function (Links $links): RelationshipObject {
            return RelationshipObject::toOne($links, null);
        }];
        yield 'toMany' => [static function (Links $links): RelationshipObject {
            return RelationshipObject::toMany($links, []);
        }];
    }

    public function testPaginationLinksOfTheRelationshipRouteAreKeptNextToSelfAndRelated(): void
    {
        $links = new Links([
            'self' => 'http://example.com/pets/1/relationships/orders',
            'related' => 'http://example.com/pets/1/orders',
            'prev' => null,
            'next' => 'http://example.com/pets/1/relationships/orders?page[after]=12',
        ]);

        $relationship = RelationshipObject::toMany($links, []);

        self::assertSame(['self', 'related', 'prev', 'next'], array_keys($relationship->links->all()));
    }

    /**
     * @param list<string> $names
     *
     * @return array<array-key, mixed>
     */
    private static function meta(array $names): array
    {
        $meta = [];

        foreach ($names as $name) {
            $meta[$name] = $name;
        }

        return $meta;
    }

    private static function links(): Links
    {
        return new Links([
            'self' => 'http://example.com/pets/1/relationships/owner',
            'related' => 'http://example.com/pets/1/owner',
        ]);
    }
}
