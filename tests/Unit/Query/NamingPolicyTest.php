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
use Polunich\WpJsonApi\Query\NamingPolicy;

/**
 * @internal
 */
#[CoversClass(NamingPolicy::class)]
final class NamingPolicyTest extends TestCase
{
    #[DataProvider('names')]
    public function testSpellsANameOfTheLibrary(string $camelCase, string $snakeCase): void
    {
        self::assertSame($snakeCase, NamingPolicy::SnakeCase->name($camelCase));
        self::assertSame($camelCase, NamingPolicy::CamelCase->name($camelCase));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function names(): iterable
    {
        yield 'the count of a page' => ['withCount', 'with_count'];
        yield 'the estimate of the profile' => ['estimatedTotal', 'estimated_total'];
        yield 'the guess of the profile' => ['bestGuess', 'best_guess'];
        yield 'the truncated range of the profile' => ['rangeTruncated', 'range_truncated'];
        yield 'the maximum size of the profile' => ['maxSize', 'max_size'];
        yield 'an operator of two words' => ['@notIn', '@not_in'];
        yield 'an operator of two words that ignores case' => ['@notContainsi', '@not_containsi'];
        yield 'an operator that starts' => ['@startsWith', '@starts_with'];
        yield 'an operator that starts, ignoring case' => ['@startsWithi', '@starts_withi'];
        yield 'an operator that ends' => ['@endsWith', '@ends_with'];
        yield 'the negated null' => ['@notNull', '@not_null'];
        yield 'an operator of one word' => ['@containsi', '@containsi'];
        yield 'a member of one word' => ['size', 'size'];
    }
}
