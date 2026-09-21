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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Document\Link;

/**
 * @internal
 */
#[CoversClass(Link::class)]
final class LinkTest extends TestCase
{
    public function testHrefIsTheOnlyRequiredMember(): void
    {
        $link = new Link('http://example.com/pets/1');

        self::assertSame('http://example.com/pets/1', $link->href);
        self::assertNull($link->rel);
        self::assertNull($link->describedby);
        self::assertNull($link->title);
        self::assertNull($link->type);
        self::assertNull($link->hreflang);
        self::assertNull($link->meta);
    }

    #[DataProvider('links')]
    public function testALinkIsHrefOnlyWhileEveryOtherMemberCarriesNothing(Link $link, bool $hrefOnly): void
    {
        self::assertSame($hrefOnly, $link->isHrefOnly());
    }

    /**
     * @return iterable<string, array{Link, bool}>
     */
    public static function links(): iterable
    {
        yield 'href alone' => [new Link('h'), true];
        yield 'empty meta' => [new Link('h', meta: []), true];
        yield 'empty hreflang' => [new Link('h', hreflang: []), true];
        yield 'empty hreflang and empty meta' => [new Link('h', hreflang: [], meta: []), true];
        yield 'rel' => [new Link('h', rel: 'describedby'), false];
        yield 'describedby' => [new Link('h', describedby: new Link('d')), false];
        yield 'title' => [new Link('h', title: 'orders'), false];
        yield 'type' => [new Link('h', type: 'application/vnd.api+json'), false];
        yield 'hreflang as a string' => [new Link('h', hreflang: 'en'), false];
        yield 'hreflang as a list' => [new Link('h', hreflang: ['en', 'de']), false];
        yield 'meta' => [new Link('h', meta: ['count' => 10]), false];
    }

    public function testADescribedbyMemberIsItselfALink(): void
    {
        $link = new Link(
            'http://example.com/pets/1/orders',
            describedby: new Link('http://example.com/schemas/pet-orders'),
        );

        self::assertInstanceOf(Link::class, $link->describedby);
        self::assertSame('http://example.com/schemas/pet-orders', $link->describedby->href);
    }
}
