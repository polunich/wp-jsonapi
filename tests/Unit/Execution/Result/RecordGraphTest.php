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
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(RecordGraph::class)]
final class RecordGraphTest extends TestCase
{
    public function testTheClassIsFinalAndReadonly(): void
    {
        $class = new ReflectionClass(RecordGraph::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
    }

    public function testADocumentOfARequestWithoutIncludeIsNoCompoundDocument(): void
    {
        $graph = new RecordGraph(false, [], ['pets' => ['1' => ['owner' => RecordLinkage::toOne(null)]]]);

        self::assertFalse($graph->isCompound());
        self::assertSame([], $graph->included());
        self::assertNotNull($graph->linkage('pets', '1', 'owner'));
    }

    public function testADocumentOfARequestWithIncludeIsCompoundAlthoughItReachedNoResource(): void
    {
        $graph = new RecordGraph(true);

        self::assertTrue($graph->isCompound());
        self::assertSame([], $graph->included());
    }

    public function testKeepsTheIncludedResourcesInTheOrderTheyWereLoaded(): void
    {
        $owner = self::record('customers', '9');
        $order = self::record('orders', '5');

        $graph = new RecordGraph(true, [$owner, $order]);

        self::assertSame([$owner, $order], $graph->included());
    }

    public function testReturnsTheLinkageOfOneRelationshipOfOneResource(): void
    {
        $owner = RecordLinkage::toOne(self::record('customers', '9'));
        $other = RecordLinkage::toOne(null);

        $graph = new RecordGraph(false, [], [
            'pets' => ['1' => ['owner' => $owner], '2' => ['owner' => $other]],
        ]);

        self::assertSame($owner, $graph->linkage('pets', '1', 'owner'));
        self::assertSame($other, $graph->linkage('pets', '2', 'owner'));
    }

    #[DataProvider('relationshipsTheTableDoesNotName')]
    public function testReturnsNoLinkageForARelationshipTheTableDoesNotName(
        string $type,
        string $id,
        string $relationship,
    ): void {
        $graph = new RecordGraph(false, [], [
            'pets' => ['1' => ['owner' => RecordLinkage::toOne(null)]],
        ]);

        self::assertNull($graph->linkage($type, $id, $relationship));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function relationshipsTheTableDoesNotName(): iterable
    {
        yield 'another type' => ['orders', '1', 'owner'];
        yield 'another id' => ['pets', '2', 'owner'];
        yield 'another relationship' => ['pets', '1', 'orders'];
    }

    public function testReturnsTheLinkageHeldUnderAnIdAndANameOfDigits(): void
    {
        $linkage = RecordLinkage::toOne(self::record('customers', '9'));

        $graph = new RecordGraph(false, [], ['pets' => ['12' => ['2' => $linkage]]]);

        self::assertSame($linkage, $graph->linkage('pets', '12', '2'));
    }

    public function testRefusesTheSameTypeAndIdPairTwice(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the included resources hold type "customers" and id "9" twice');

        new RecordGraph(
            true,
            [self::record('customers', '9'), self::record('orders', '5'), self::record('customers', '9')],
        );
    }

    public function testTakesTheSameIdUnderTwoTypes(): void
    {
        $owner = self::record('customers', '1');
        $order = self::record('orders', '1');

        $graph = new RecordGraph(true, [$owner, $order]);

        self::assertSame([$owner, $order], $graph->included());
    }

    public function testRefusesAnIncludedResourceThatCarriesNoRecord(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the included resource of type "customers" and id "9" carries no record');

        new RecordGraph(true, [new ResourceRecord('customers', '9', null)]);
    }

    private static function record(string $type, string $id): ResourceRecord
    {
        return new ResourceRecord($type, $id, new Pet($id, 'Rex'));
    }
}
