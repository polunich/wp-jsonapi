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

namespace Polunich\WpJsonApi\Tests\Unit\Query\Pagination;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Query\Pagination\PageParameter;
use Polunich\WpJsonApi\Query\Pagination\Strategy;

/**
 * @internal
 */
#[CoversClass(Strategy::class)]
final class StrategyTest extends TestCase
{
    public function testTheCasesAreThePageNumberTheOffsetAndTheCursorStrategies(): void
    {
        self::assertSame(
            ['number', 'offset', 'cursor'],
            array_map(static function (Strategy $strategy): string {
                return $strategy->value;
            }, Strategy::cases()),
        );
    }

    public function testEachCaseIsNamedAfterTheParameterThatSelectsIt(): void
    {
        self::assertSame(Strategy::PageNumber, Strategy::from('number'));
        self::assertSame(Strategy::Offset, Strategy::from('offset'));
        self::assertSame(Strategy::Cursor, Strategy::from('cursor'));
    }

    /**
     * @param list<PageParameter> $selectors
     * @param list<PageParameter> $parameters
     */
    #[DataProvider('members')]
    public function testEachStrategyNamesTheMembersThatSelectItAndThatItCarries(
        Strategy $strategy,
        array $selectors,
        array $parameters,
        PageParameter $size,
    ): void {
        self::assertSame($selectors, $strategy->selectors());
        self::assertSame($parameters, $strategy->parameters());
        self::assertSame($size, $strategy->sizeParameter());
    }

    /**
     * @return iterable<string, array{
     *     Strategy,
     *     list<PageParameter>,
     *     list<PageParameter>,
     *     PageParameter,
     * }>
     */
    public static function members(): iterable
    {
        yield 'the page number' => [
            Strategy::PageNumber,
            [PageParameter::Number],
            [PageParameter::Number, PageParameter::Size, PageParameter::WithCount],
            PageParameter::Size,
        ];
        yield 'the offset' => [
            Strategy::Offset,
            [PageParameter::Offset, PageParameter::Limit],
            [PageParameter::Offset, PageParameter::Limit, PageParameter::WithCount],
            PageParameter::Limit,
        ];
        yield 'the cursor' => [
            Strategy::Cursor,
            [PageParameter::After, PageParameter::Before],
            [PageParameter::After, PageParameter::Before, PageParameter::Size, PageParameter::WithCount],
            PageParameter::Size,
        ];
    }

    /**
     * the invariant that keeps selection unambiguous: no member selects two strategies,
     * and every member belongs to at least one
     */
    public function testEveryMemberBelongsToAStrategyAndSelectsOneAtMost(): void
    {
        foreach (PageParameter::cases() as $member) {
            $selecting = array_filter(Strategy::cases(), static function (Strategy $strategy) use ($member): bool {
                return in_array($member, $strategy->selectors(), true);
            });
            $carrying = array_filter(Strategy::cases(), static function (Strategy $strategy) use ($member): bool {
                return in_array($member, $strategy->parameters(), true);
            });

            self::assertLessThanOrEqual(1, count($selecting), $member->value);
            self::assertNotSame([], $carrying, $member->value);
        }
    }
}
