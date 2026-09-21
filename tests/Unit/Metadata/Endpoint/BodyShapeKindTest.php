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
use Polunich\WpJsonApi\Metadata\Endpoint\BodyShapeKind;
use ReflectionEnum;

/**
 * @internal
 */
#[CoversClass(BodyShapeKind::class)]
final class BodyShapeKindTest extends TestCase
{
    public function testTheEnumIsInternal(): void
    {
        self::assertMatchesRegularExpression(
            '/@internal\b/',
            (string) (new ReflectionEnum(BodyShapeKind::class))->getDocComment(),
        );
    }

    #[DataProvider('phrases')]
    public function testAMessageNamesTheKindInWordsWithItsArticle(BodyShapeKind $kind, string $phrase): void
    {
        self::assertSame($phrase, $kind->phrase());
    }

    /**
     * @return iterable<string, array{BodyShapeKind, string}>
     */
    public static function phrases(): iterable
    {
        yield 'resource' => [BodyShapeKind::Resource, 'a resource body'];
        yield 'record' => [BodyShapeKind::Record, 'a record body'];
        yield 'to one, in words' => [BodyShapeKind::ToOne, 'a to-one body'];
        yield 'to many, in words' => [BodyShapeKind::ToMany, 'a to-many body'];
        yield 'meta' => [BodyShapeKind::Meta, 'a meta body'];
    }
}
