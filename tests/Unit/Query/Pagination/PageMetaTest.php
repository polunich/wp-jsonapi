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
use Polunich\WpJsonApi\Query\Pagination\PageMeta;

/**
 * @internal
 */
#[CoversClass(PageMeta::class)]
final class PageMetaTest extends TestCase
{
    public function testTheCasesAreTheMembersOfThePaginationMetadata(): void
    {
        self::assertSame('page', PageMeta::MEMBER);
        self::assertSame(
            ['total', 'estimatedTotal', 'bestGuess', 'rangeTruncated', 'maxSize'],
            array_map(static function (PageMeta $member): string {
                return $member->value;
            }, PageMeta::cases()),
        );
    }

    public function testAMemberIsSpelledByTheNamingPolicy(): void
    {
        self::assertSame('estimated_total', PageMeta::EstimatedTotal->nameIn(NamingPolicy::SnakeCase));
        self::assertSame('estimatedTotal', PageMeta::EstimatedTotal->nameIn(NamingPolicy::CamelCase));
        self::assertSame('total', PageMeta::Total->nameIn(NamingPolicy::SnakeCase));
    }
}
