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
use Polunich\WpJsonApi\Execution\Result\CollectionResult;
use Polunich\WpJsonApi\Execution\Result\Page;
use Polunich\WpJsonApi\Execution\Result\RecordGraph;
use Polunich\WpJsonApi\Execution\Result\ResourceRecord;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\Pagination\Strategy;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use ReflectionClass;
use ReflectionParameter;

/**
 * @internal
 */
#[CoversClass(CollectionResult::class)]
final class CollectionResultTest extends TestCase
{
    public function testTheClassIsFinalAndReadonly(): void
    {
        $class = new ReflectionClass(CollectionResult::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
    }

    public function testAFetchedCollectionGets200AndTheDocumentItWasBuiltWith(): void
    {
        $page = self::page([self::record('1'), self::record('2')]);
        $graph = new RecordGraph(true);
        $fields = new SparseFieldsets(['pets' => ['name']]);

        $result = CollectionResult::paged('pets', $page, $graph, $fields);

        self::assertSame(200, $result->status());
        self::assertSame('pets', $result->type);
        self::assertSame($page, $result->page);
        self::assertSame($page->records, $result->records);
        self::assertSame($graph, $result->graph);
        self::assertSame($fields, $result->fields);
    }

    public function testAWholeListGets200AndNoPage(): void
    {
        $records = [self::record('1'), self::record('2')];
        $graph = new RecordGraph(false);
        $fields = new SparseFieldsets([]);

        $result = CollectionResult::whole('pets', $records, $graph, $fields);

        self::assertSame(200, $result->status());
        self::assertSame('pets', $result->type);
        self::assertSame($records, $result->records);
        self::assertNull($result->page);
        self::assertSame($graph, $result->graph);
        self::assertNull($result->entityTag());
    }

    #[DataProvider('pages')]
    public function testAPageOfNoRecordIsRespondedToLikeAnyOtherPage(Page $page): void
    {
        $result = CollectionResult::paged('pets', $page, new RecordGraph(false), new SparseFieldsets([]));

        self::assertSame(200, $result->status());
        self::assertSame($page, $result->page);
    }

    /**
     * @return iterable<string, array{Page}>
     */
    public static function pages(): iterable
    {
        yield 'no record' => [self::page([])];
        yield 'one record' => [self::page([self::record('1')])];
    }

    public function testReturnsNoEntityTagByConstruction(): void
    {
        $constructor = (new ReflectionClass(CollectionResult::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
        self::assertSame(
            ['type' => 'string', 'records' => 'array', 'page' => '?'
                . Page::class, 'graph' => RecordGraph::class, 'fields' => SparseFieldsets::class],
            array_combine(
                array_map(static function (ReflectionParameter $parameter): string {
                    return $parameter->getName();
                }, $constructor->getParameters()),
                array_map(static function (ReflectionParameter $parameter): string {
                    return (string) $parameter->getType();
                }, $constructor->getParameters()),
            ),
        );
        self::assertNull((CollectionResult::paged(
            'pets',
            self::page([]),
            new RecordGraph(false),
            new SparseFieldsets([]),
        ))->entityTag());
    }

    /**
     * @param list<ResourceRecord> $records
     */
    private static function page(array $records): Page
    {
        return new Page($records, Strategy::PageNumber, 10, 1, null, false, false, false, null, null, null, null);
    }

    private static function record(string $id): ResourceRecord
    {
        return new ResourceRecord('pets', $id, new Pet($id, 'Rex'));
    }
}
