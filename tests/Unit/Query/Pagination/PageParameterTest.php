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
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Query\NamingPolicy;
use Polunich\WpJsonApi\Query\Pagination\PageParameter;

/**
 * @internal
 */
#[CoversClass(PageParameter::class)]
final class PageParameterTest extends TestCase
{
    public function testTheCasesAreTheMembersOfThePageFamily(): void
    {
        self::assertSame(
            ['number', 'size', 'offset', 'limit', 'after', 'before', 'withCount'],
            array_map(static function (PageParameter $member): string {
                return $member->value;
            }, PageParameter::cases()),
        );
    }

    public function testEveryMemberButWithCountIsAPosition(): void
    {
        foreach (PageParameter::cases() as $member) {
            self::assertSame($member !== PageParameter::WithCount, $member->isPosition(), $member->value);
        }
    }

    public function testTheCountIsSpelledByTheNamingPolicy(): void
    {
        self::assertSame('with_count', PageParameter::WithCount->nameIn(NamingPolicy::SnakeCase));
        self::assertSame('withCount', PageParameter::WithCount->nameIn(NamingPolicy::CamelCase));
    }

    public function testAMemberIsFoundByItsSpellingUnderThePolicyAlone(): void
    {
        self::assertSame(PageParameter::WithCount, PageParameter::named('with_count', NamingPolicy::SnakeCase));
        self::assertSame(PageParameter::WithCount, PageParameter::named('withCount', NamingPolicy::CamelCase));
        self::assertNull(PageParameter::named('withCount', NamingPolicy::SnakeCase));
        self::assertNull(PageParameter::named('with_count', NamingPolicy::CamelCase));
        self::assertSame(PageParameter::Size, PageParameter::named('size', NamingPolicy::SnakeCase));
        self::assertNull(PageParameter::named('cursor', NamingPolicy::SnakeCase));
    }
}
