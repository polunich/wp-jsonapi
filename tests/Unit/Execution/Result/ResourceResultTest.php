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

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Execution\Result\RecordGraph;
use Polunich\WpJsonApi\Execution\Result\RecordLinkage;
use Polunich\WpJsonApi\Execution\Result\ResourceRecord;
use Polunich\WpJsonApi\Execution\Result\ResourceResult;
use Polunich\WpJsonApi\Http\EntityTag;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(ResourceResult::class)]
final class ResourceResultTest extends TestCase
{
    public function testTheClassIsFinalAndReadonly(): void
    {
        $class = new ReflectionClass(ResourceResult::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
    }

    public function testAFetchedResourceGets200AndTheDocumentItWasBuiltWith(): void
    {
        $resource = self::record();
        $graph = new RecordGraph(true, [], ['pets' => ['1' => ['owner' => RecordLinkage::toOne(null)]]]);
        $fields = new SparseFieldsets(['pets' => ['name']]);

        $result = new ResourceResult('pets', $resource, $graph, $fields, 200, null);

        self::assertSame(200, $result->status());
        self::assertSame('pets', $result->type);
        self::assertSame($resource, $result->resource);
        self::assertSame($graph, $result->graph);
        self::assertSame($fields, $result->fields);
    }

    public function testAFinishedCreateGets201AndTheResourceItMade(): void
    {
        $resource = self::record();

        $result = new ResourceResult('pets', $resource, new RecordGraph(false), new SparseFieldsets([]), 201, null);

        self::assertSame(201, $result->status());
        self::assertSame($resource, $result->resource);
    }

    public function testTheRelatedRouteOfAnEmptyToOneRelationshipCarriesTheRelatedTypeAndNoResource(): void
    {
        $result = new ResourceResult('customers', null, new RecordGraph(false), new SparseFieldsets([]), 200, null);

        self::assertSame(200, $result->status());
        self::assertSame('customers', $result->type);
        self::assertNull($result->resource);
    }

    /**
     * @param 200|201 $status
     */
    #[DataProvider('statusesOfAResourceDocument')]
    public function testReturnsTheEntityTagOfTheRepresentation(int $status): void
    {
        $tag = new EntityTag('a1b2');

        $result = new ResourceResult(
            'pets',
            self::record(),
            new RecordGraph(false),
            new SparseFieldsets([]),
            $status,
            $tag,
        );

        self::assertSame($tag, $result->entityTag());
    }

    /**
     * @param 200|201 $status
     */
    #[DataProvider('statusesOfAResourceDocument')]
    public function testReturnsNoEntityTagWhereTheLibraryWritesNone(int $status): void
    {
        $result = new ResourceResult(
            'pets',
            self::record(),
            new RecordGraph(false),
            new SparseFieldsets([]),
            $status,
            null,
        );

        self::assertNull($result->entityTag());
    }

    /**
     * @return iterable<string, array{200|201}>
     */
    public static function statusesOfAResourceDocument(): iterable
    {
        yield 'a fetch or a finished update' => [200];
        yield 'a finished create' => [201];
    }

    #[DataProvider('statusesOfAnotherResult')]
    public function testRefusesAStatusThatIsNeither200Nor201(int $status): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'a document whose primary data is a resource carries the status %d',
            $status,
        ));

        new ResourceResult('pets', self::record(), new RecordGraph(false), new SparseFieldsets([]), $status, null);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function statusesOfAnotherResult(): iterable
    {
        yield 'a write a store accepted' => [202];
        yield 'a write that returned nothing' => [204];
        yield 'a condition that did not hold' => [304];
        yield 'an error of the client' => [404];
        yield 'an error of the server' => [500];
        yield 'no status at all' => [0];
    }

    public function testRefusesA201ThatCarriesNoResource(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a 201 carries no resource');

        new ResourceResult('pets', null, new RecordGraph(false), new SparseFieldsets([]), 201, null);
    }

    private static function record(): ResourceRecord
    {
        return new ResourceRecord('pets', '1', new Pet('1', 'Rex'));
    }
}
