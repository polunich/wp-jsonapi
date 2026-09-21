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
use Polunich\WpJsonApi\Execution\Result\AtomicResult;
use Polunich\WpJsonApi\Execution\Result\NoContentResult;
use Polunich\WpJsonApi\Execution\Result\RecordGraph;
use Polunich\WpJsonApi\Execution\Result\ResourceRecord;
use Polunich\WpJsonApi\Execution\Result\ResourceResult;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use ReflectionClass;
use ReflectionParameter;

/**
 * @internal
 */
#[CoversClass(AtomicResult::class)]
final class AtomicResultTest extends TestCase
{
    public function testTheClassIsFinalAndReadonly(): void
    {
        $class = new ReflectionClass(AtomicResult::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
    }

    public function testKeepsTheResultsAtThePositionsOfTheirOperations(): void
    {
        $created = self::created();
        $deleted = new NoContentResult();

        $result = new AtomicResult([$created, $deleted, $created]);

        self::assertSame([$created, $deleted, $created], $result->results);
    }

    /**
     * @param non-empty-list<NoContentResult> $results
     */
    #[DataProvider('requestsThatReturnNoData')]
    public function testRespondsWith204WhenEveryOperationReturnedNothing(array $results): void
    {
        self::assertSame(204, (new AtomicResult($results))->status());
    }

    /**
     * @return iterable<string, array{non-empty-list<NoContentResult>}>
     */
    public static function requestsThatReturnNoData(): iterable
    {
        yield 'one operation' => [[new NoContentResult()]];
        yield 'three operations' => [[new NoContentResult(), new NoContentResult(), new NoContentResult()]];
    }

    /**
     * @param non-empty-list<NoContentResult|ResourceResult> $results
     */
    #[DataProvider('requestsThatReturnData')]
    public function testRespondsWith200WhenOneOperationReturnedAResource(array $results): void
    {
        self::assertSame(200, (new AtomicResult($results))->status());
    }

    /**
     * @return iterable<string, array{non-empty-list<NoContentResult|ResourceResult>}>
     */
    public static function requestsThatReturnData(): iterable
    {
        yield 'the only operation' => [[self::created()]];
        yield 'the first of three' => [[self::created(), new NoContentResult(), new NoContentResult()]];
        yield 'the middle of three' => [[new NoContentResult(), self::created(), new NoContentResult()]];
        yield 'the last of three' => [[new NoContentResult(), new NoContentResult(), self::created()]];
    }

    public function testRefusesAResponseOfNoResult(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('an atomic response holds a result of every operation of the request, and this'
            . ' one holds none');

        new AtomicResult([]);
    }

    /**
     * @param non-empty-list<NoContentResult|ResourceResult> $results
     */
    #[DataProvider('resultsWithoutAResource')]
    public function testRefusesAResourceResultThatCarriesNoResource(array $results, int $position): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'the result of the operation at position %d carries no resource',
            $position,
        ));

        new AtomicResult($results);
    }

    /**
     * @return iterable<string, array{non-empty-list<NoContentResult|ResourceResult>, int}>
     */
    public static function resultsWithoutAResource(): iterable
    {
        yield 'the only operation' => [[self::withoutAResource()], 0];
        yield 'the last of three' => [[new NoContentResult(), self::created(), self::withoutAResource()], 2];
    }

    public function testReturnsNoEntityTagByConstruction(): void
    {
        $constructor = (new ReflectionClass(AtomicResult::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertSame(
            ['results' => 'array'],
            array_combine(
                array_map(static function (ReflectionParameter $parameter): string {
                    return $parameter->getName();
                }, $constructor->getParameters()),
                array_map(static function (ReflectionParameter $parameter): string {
                    return (string) $parameter->getType();
                }, $constructor->getParameters()),
            ),
        );
        self::assertNull((new AtomicResult([new NoContentResult()]))->entityTag());
    }

    private static function created(): ResourceResult
    {
        return new ResourceResult(
            'pets',
            new ResourceRecord('pets', '1', new Pet('1', 'Rex')),
            new RecordGraph(false),
            new SparseFieldsets([]),
            201,
            null,
        );
    }

    /**
     * only a read of an empty to-one relationship returns this,
     * and an atomic request carries no read
     */
    private static function withoutAResource(): ResourceResult
    {
        return new ResourceResult('customers', null, new RecordGraph(false), new SparseFieldsets([]), 200, null);
    }
}
