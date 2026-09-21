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

namespace Polunich\WpJsonApi\Tests\Unit\Metadata\Endpoint;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseShapeKind;
use ReflectionEnum;

/**
 * @internal
 */
#[CoversClass(ResponseShapeKind::class)]
final class ResponseShapeKindTest extends TestCase
{
    public function testTheEnumIsInternal(): void
    {
        self::assertMatchesRegularExpression(
            '/@internal\b/',
            (string) (new ReflectionEnum(ResponseShapeKind::class))->getDocComment(),
        );
    }

    #[DataProvider('statuses')]
    public function testTheKindOfAResponseFixesItsStatusAndWhetherItCarriesResources(
        ResponseShapeKind $kind,
        int $status,
        bool $resources,
    ): void {
        self::assertSame($status, $kind->status());
        self::assertSame($resources, $kind->carriesResources());
    }

    /**
     * @return iterable<string, array{ResponseShapeKind, int, bool}>
     */
    public static function statuses(): iterable
    {
        yield 'resource' => [ResponseShapeKind::Resource, 200, true];
        yield 'record' => [ResponseShapeKind::Record, 200, true];
        yield 'created' => [ResponseShapeKind::Created, 201, true];
        yield 'collection' => [ResponseShapeKind::Collection, 200, true];
        yield 'page' => [ResponseShapeKind::Page, 200, true];
        yield 'related' => [ResponseShapeKind::Related, 200, true];
        yield 'linkage' => [ResponseShapeKind::Linkage, 200, true];
        yield 'meta' => [ResponseShapeKind::Meta, 200, false];
        yield 'accepted' => [ResponseShapeKind::Accepted, 202, false];
        yield 'no content' => [ResponseShapeKind::NoContent, 204, false];
    }

    #[DataProvider('phrases')]
    public function testAMessageNamesTheKindInWordsWithItsArticle(ResponseShapeKind $kind, string $phrase): void
    {
        self::assertSame($phrase, $kind->phrase());
    }

    /**
     * @return iterable<string, array{ResponseShapeKind, string}>
     */
    public static function phrases(): iterable
    {
        yield 'resource' => [ResponseShapeKind::Resource, 'a resource response'];
        yield 'record' => [ResponseShapeKind::Record, 'a record response'];
        yield 'created' => [ResponseShapeKind::Created, 'a created response'];
        yield 'collection' => [ResponseShapeKind::Collection, 'a collection response'];
        yield 'page' => [ResponseShapeKind::Page, 'a page response'];
        yield 'related' => [ResponseShapeKind::Related, 'a related response'];
        yield 'linkage' => [ResponseShapeKind::Linkage, 'a linkage response'];
        yield 'meta' => [ResponseShapeKind::Meta, 'a meta response'];
        yield 'accepted, after "an"' => [ResponseShapeKind::Accepted, 'an accepted response'];
        yield 'no content, in words' => [ResponseShapeKind::NoContent, 'a no-content response'];
    }
}
