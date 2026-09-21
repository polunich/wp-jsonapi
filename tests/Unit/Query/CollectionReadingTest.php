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

namespace Polunich\WpJsonApi\Tests\Unit\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Query\CollectionReading;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Query\Filter\Condition;
use Polunich\WpJsonApi\Query\Pagination\PageRequest;
use Polunich\WpJsonApi\Query\Sort\Sort;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(CollectionReading::class)]
final class CollectionReadingTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(CollectionReading::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testCarriesTheReadingAsParsed(): void
    {
        $filter = new Condition(new FieldPath(['name']), '@eq', 'Rex');
        $sort = Sort::fromString('-name');
        $readingSort = $sort->withId();
        $page = PageRequest::cursor(size: 5);

        $reading = new CollectionReading('pets', $filter, $sort, $readingSort, $page);

        self::assertSame('pets', $reading->type);
        self::assertSame($filter, $reading->filter);
        self::assertSame($sort, $reading->sort);
        self::assertSame($readingSort, $reading->readingSort);
        self::assertSame($page, $reading->page);
    }
}
