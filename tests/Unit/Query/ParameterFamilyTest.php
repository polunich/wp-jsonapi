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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Query\ParameterFamily;

/**
 * @internal
 */
#[CoversClass(ParameterFamily::class)]
final class ParameterFamilyTest extends TestCase
{
    public function testTheCasesAreTheFiveFamiliesOfJsonApi(): void
    {
        self::assertSame(
            ['filter', 'sort', 'page', 'include', 'fields'],
            array_map(static function (ParameterFamily $family): string {
                return $family->value;
            }, ParameterFamily::cases()),
        );
    }

    /**
     * @param array<array-key, mixed> $query
     */
    #[DataProvider('queries')]
    public function testReadsTheValueOfTheFamilyOutOfTheQuery(array $query, mixed $value, bool $carried): void
    {
        self::assertSame($value, ParameterFamily::Page->valueIn($query));
        self::assertSame($carried, ParameterFamily::Page->isIn($query));
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, mixed, bool}>
     */
    public static function queries(): iterable
    {
        yield 'no page' => [['sort' => 'name'], null, false];
        yield 'page members' => [['page' => ['size' => '2']], ['size' => '2'], true];
        yield 'the base name alone' => [['page' => '3'], '3', true];
        yield 'the empty value' => [['page' => ''], '', true];
    }

    public function testTheEmptyIncludeIsSupplied(): void
    {
        self::assertTrue(ParameterFamily::Include->isIn(['include' => '']));
        self::assertFalse(ParameterFamily::Include->isIn(['fields' => ['pets' => 'name']]));
    }

    public function testWritesTheNameOfAParameterOfTheFamily(): void
    {
        self::assertSame('fields[pets]', ParameterFamily::Fields->parameter('pets'));
        self::assertSame('page[size]', ParameterFamily::Page->parameter('size'));
    }
}
