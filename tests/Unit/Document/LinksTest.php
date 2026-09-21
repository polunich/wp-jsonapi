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

namespace Polunich\WpJsonApi\Tests\Unit\Document;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Document\Link;
use Polunich\WpJsonApi\Document\Links;

/**
 * @internal
 */
#[CoversClass(Links::class)]
final class LinksTest extends TestCase
{
    public function testAStringIsNormalizedIntoALinkWithThatHref(): void
    {
        $links = new Links(['self' => 'http://example.com/pets/1']);

        $link = $links->all()['self'];

        self::assertInstanceOf(Link::class, $link);
        self::assertSame('http://example.com/pets/1', $link->href);
        self::assertTrue($link->isHrefOnly());
    }

    public function testANullValueIsKept(): void
    {
        $links = new Links(['prev' => null, 'next' => 'http://example.com/pets?page[after]=9']);

        self::assertNull($links->all()['prev']);
        self::assertInstanceOf(Link::class, $links->all()['next']);
    }

    public function testTheInsertionOrderIsKept(): void
    {
        $links = new Links(['self' => 's', 'related' => 'r', 'first' => 'f']);

        self::assertSame(['self', 'related', 'first'], array_keys($links->all()));
    }

    public function testHasIsTrueForALinkUnderThatName(): void
    {
        $links = new Links(['self' => 's', 'related' => new Link('r')]);

        self::assertTrue($links->has('self'));
        self::assertTrue($links->has('related'));
    }

    public function testHasIsFalseForANameWhoseLinkIsNull(): void
    {
        self::assertFalse((new Links(['next' => null]))->has('next'));
    }

    public function testHasIsFalseForANameThatIsAbsent(): void
    {
        self::assertFalse((new Links(['self' => 's']))->has('related'));
    }

    public function testALinkNameOfDigitsKeepsItsIntKeyAndIsStillFound(): void
    {
        $links = new Links(self::members(['0']));

        self::assertSame([0], array_keys($links->all()));
        self::assertTrue($links->has('0'));
    }

    public function testAMapWithoutNamesIsEmpty(): void
    {
        self::assertTrue((new Links([]))->isEmpty());
    }

    public function testAMapWithANullLinkIsNotEmpty(): void
    {
        self::assertFalse((new Links(['next' => null]))->isEmpty());
    }

    /**
     * built at runtime, so PHPStan cannot narrow the keys to strings
     *
     * @param list<string> $names
     *
     * @return array<array-key, string>
     */
    private static function members(array $names): array
    {
        $members = [];

        foreach ($names as $name) {
            $members[$name] = $name;
        }

        return $members;
    }
}
