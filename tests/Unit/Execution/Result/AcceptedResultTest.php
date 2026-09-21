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
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Execution\Result\AcceptedResult;
use Polunich\WpJsonApi\Execution\Result\RecordGraph;
use Polunich\WpJsonApi\Execution\Result\RecordLinkage;
use Polunich\WpJsonApi\Execution\Result\ResourceRecord;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use ReflectionClass;
use ReflectionParameter;

/**
 * @internal
 */
#[CoversClass(AcceptedResult::class)]
final class AcceptedResultTest extends TestCase
{
    public function testTheClassIsFinalAndReadonly(): void
    {
        $class = new ReflectionClass(AcceptedResult::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
    }

    public function testAnAcceptedWriteGets202AndTheMonitorOfTheWork(): void
    {
        $monitor = self::monitor();
        $graph = new RecordGraph(true, [], ['jobs' => ['7' => ['pet' => RecordLinkage::toOne(null)]]]);
        $fields = new SparseFieldsets(['jobs' => ['state']]);

        $result = new AcceptedResult($monitor, $graph, $fields);

        self::assertSame(202, $result->status());
        self::assertSame($monitor, $result->monitor);
        self::assertSame($graph, $result->graph);
        self::assertSame($fields, $result->fields);
    }

    public function testCarriesTheEmptyIncludedMemberOfARequestThatSuppliedInclude(): void
    {
        $result = new AcceptedResult(self::monitor(), new RecordGraph(true), new SparseFieldsets([]));

        self::assertTrue($result->graph->isCompound());
        self::assertSame([], $result->graph->included());
    }

    public function testReturnsNoEntityTagByConstruction(): void
    {
        $constructor = (new ReflectionClass(AcceptedResult::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertSame(
            ['monitor' => ResourceRecord::class, 'graph' => RecordGraph::class, 'fields' => SparseFieldsets::class],
            array_combine(
                array_map(static function (ReflectionParameter $parameter): string {
                    return $parameter->getName();
                }, $constructor->getParameters()),
                array_map(static function (ReflectionParameter $parameter): string {
                    return (string) $parameter->getType();
                }, $constructor->getParameters()),
            ),
        );
        self::assertNull((new AcceptedResult(
            self::monitor(),
            new RecordGraph(false),
            new SparseFieldsets([]),
        ))->entityTag());
    }

    private static function monitor(): ResourceRecord
    {
        return new ResourceRecord('jobs', '7', new Pet('7', 'importing the archive'));
    }
}
