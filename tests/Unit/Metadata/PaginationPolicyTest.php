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

namespace Polunich\WpJsonApi\Tests\Unit\Metadata;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Metadata\PaginationPolicy;
use Polunich\WpJsonApi\Query\Pagination\Cursor;
use Polunich\WpJsonApi\Query\Pagination\PageRequest;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * @internal
 */
#[CoversClass(PaginationPolicy::class)]
final class PaginationPolicyTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(PaginationPolicy::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    public function testTheTwoSizesLibraryDefaultAndUsedSizeKeepTheirSignatures(): void
    {
        $class = new ReflectionClass(PaginationPolicy::class);
        $signature = static function (?ReflectionMethod $method): array {
            return array_map(
                static function (ReflectionParameter $parameter): array {
                    return [$parameter->getName(), (string) $parameter->getType()];
                },
                $method?->getParameters() ?? [],
            );
        };

        self::assertSame([['defaultSize', 'int'], ['maxSize', 'int']], $signature($class->getConstructor()));
        self::assertTrue($class->getMethod('libraryDefault')->isStatic());
        self::assertSame([], $signature($class->getMethod('libraryDefault')));
        self::assertSame([['page', PageRequest::class]], $signature($class->getMethod('usedSize')));
        self::assertSame('int', (string) $class->getMethod('usedSize')->getReturnType());
    }

    #[DataProvider('policies')]
    public function testCarriesADefaultBetweenOneAndTheMaximum(int $defaultSize, int $maxSize): void
    {
        $policy = new PaginationPolicy($defaultSize, $maxSize);

        self::assertSame($defaultSize, $policy->defaultSize);
        self::assertSame($maxSize, $policy->maxSize);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function policies(): iterable
    {
        yield 'the smallest policy' => [1, 1];
        yield 'a default of 1' => [1, 50];
        yield 'a default equal to the maximum' => [25, 25];
        yield 'a default below the maximum' => [20, 50];
    }

    #[DataProvider('impossiblePolicies')]
    public function testRefusesADefaultOutsideOneAndTheMaximum(int $defaultSize, int $maxSize, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new PaginationPolicy($defaultSize, $maxSize);
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function impossiblePolicies(): iterable
    {
        yield 'a default of 0' => [0, 10, 'the default page size is 0, and it is at least 1'];
        yield 'a negative default' => [-5, 10, 'the default page size is -5, and it is at least 1'];
        yield 'a default above the maximum' => [11, 10, 'the default page size 11 is above the maximum page size 10'];
        yield 'a maximum below 1' => [1, 0, 'the default page size 1 is above the maximum page size 0'];
    }

    public function testTheLibraryDefaultIsTenAndOneHundred(): void
    {
        $policy = PaginationPolicy::libraryDefault();

        self::assertSame(10, $policy->defaultSize);
        self::assertSame(100, $policy->maxSize);
    }

    #[DataProvider('pages')]
    public function testReturnsTheSizeARequestIsReadWith(PageRequest $page, int $expected): void
    {
        self::assertSame($expected, (new PaginationPolicy(20, 50))->usedSize($page));
    }

    /**
     * @return iterable<string, array{PageRequest, int}>
     */
    public static function pages(): iterable
    {
        $after = new Cursor(['title' => 'a'], '1');
        $before = new Cursor(['title' => 'z'], '9');

        yield 'no page parameter' => [PageRequest::pageNumber(), 20];
        yield 'page[number] without page[size]' => [PageRequest::pageNumber(3), 20];
        yield 'page[size]' => [PageRequest::pageNumber(null, 7), 7];
        yield 'page[number] and page[size]' => [PageRequest::pageNumber(2, 30), 30];
        yield 'page[offset] without page[limit]' => [PageRequest::offset(40), 20];
        yield 'page[limit]' => [PageRequest::offset(null, 5), 5];
        yield 'page[offset] and page[limit]' => [PageRequest::offset(40, 15), 15];
        yield 'a cursor request without page[size]' => [PageRequest::cursor(), 20];
        yield 'page[after] without page[size]' => [PageRequest::cursor($after), 20];
        yield 'page[before] without page[size]' => [PageRequest::cursor(null, $before), 20];
        yield 'page[after] with page[size]' => [PageRequest::cursor($after, null, 3), 3];
        yield 'a range without page[size] reads the maximum' => [PageRequest::cursor($after, $before), 50];
        yield 'a range with page[size]' => [PageRequest::cursor($after, $before, 4), 4];
        yield 'a page[size] above the maximum is not clamped' => [PageRequest::pageNumber(null, 80), 80];
        yield 'a page[limit] above the maximum is not clamped' => [PageRequest::offset(null, 80), 80];
    }
}
