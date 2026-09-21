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

namespace Polunich\WpJsonApi\Tests\Unit\Execution\Result;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Execution\Result\Page;
use Polunich\WpJsonApi\Execution\Result\RecordGraph;
use Polunich\WpJsonApi\Execution\Result\RecordLinkage;
use Polunich\WpJsonApi\Execution\Result\RelationshipResult;
use Polunich\WpJsonApi\Execution\Result\ResourceRecord;
use Polunich\WpJsonApi\Http\EntityTag;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\Pagination\Strategy;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(RelationshipResult::class)]
final class RelationshipResultTest extends TestCase
{
    public function testTheClassIsFinalAndReadonly(): void
    {
        $class = new ReflectionClass(RelationshipResult::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
    }

    public function testAFetchedRelationshipGets200AndTheDocumentItWasBuiltWith(): void
    {
        $linkage = RecordLinkage::toOne(new ResourceRecord('customers', '9', null));
        $graph = new RecordGraph(true);
        $fields = new SparseFieldsets(['customers' => ['name']]);

        $result = new RelationshipResult('pets', '1', 'owner', 'customers', $linkage, $graph, $fields, null);

        self::assertSame(200, $result->status());
        self::assertSame('pets', $result->parentType);
        self::assertSame('1', $result->parentId);
        self::assertSame('owner', $result->relationship);
        self::assertSame('customers', $result->relatedType);
        self::assertSame($linkage, $result->linkage);
        self::assertSame($graph, $result->graph);
        self::assertSame($fields, $result->fields);
    }

    #[DataProvider('linkages')]
    public function testCarriesTheLinkageOfEitherCardinality(RecordLinkage $linkage): void
    {
        $result = new RelationshipResult(
            'pets',
            '1',
            'orders',
            'orders',
            $linkage,
            new RecordGraph(false),
            new SparseFieldsets([]),
            null,
        );

        self::assertSame(200, $result->status());
        self::assertSame($linkage, $result->linkage);
    }

    /**
     * @return iterable<string, array{RecordLinkage}>
     */
    public static function linkages(): iterable
    {
        yield 'an empty to-one relationship' => [RecordLinkage::toOne(null)];
        yield 'a to-one relationship of one identifier' => [RecordLinkage::toOne(new ResourceRecord(
            'customers',
            '9',
            null,
        ))];
        yield 'an empty to-many relationship' => [RecordLinkage::toMany(self::page([]))];
        yield 'a to-many relationship of one identifier' => [RecordLinkage::toMany(self::page([new ResourceRecord(
            'orders',
            '5',
            null,
        )]))];
    }

    public function testReturnsTheEntityTagOfTheParent(): void
    {
        $tag = new EntityTag('a1b2');

        $result = new RelationshipResult(
            'pets',
            '1',
            'owner',
            'customers',
            RecordLinkage::toOne(null),
            new RecordGraph(false),
            new SparseFieldsets([]),
            $tag,
        );

        self::assertSame($tag, $result->entityTag());
    }

    public function testReturnsNoEntityTagWhereTheLibraryWritesNone(): void
    {
        $result = new RelationshipResult(
            'pets',
            '1',
            'owner',
            'customers',
            RecordLinkage::toOne(null),
            new RecordGraph(false),
            new SparseFieldsets([]),
            null,
        );

        self::assertNull($result->entityTag());
    }

    /**
     * @param list<ResourceRecord> $records
     */
    private static function page(array $records): Page
    {
        return new Page($records, Strategy::PageNumber, 10, 1, null, false, false, false, null, null, null, null);
    }
}
