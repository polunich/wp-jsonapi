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

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\Codec;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Metadata\Endpoint\BodyMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\BodyShapeKind;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(BodyMetadata::class)]
final class BodyMetadataTest extends TestCase
{
    public function testTheClassIsInternalFinalAndReadonly(): void
    {
        $class = new ReflectionClass(BodyMetadata::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    /**
     * @param ?Codec<mixed> $meta
     */
    #[DataProvider('malformedBodies')]
    public function testABodyWithAMemberItsKindDoesNotTakeIsRefused(
        BodyShapeKind $kind,
        ?string $type,
        ?string $parameter,
        ?Codec $meta,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new BodyMetadata($kind, $type, $parameter, $meta);
    }

    /**
     * @return iterable<string, array{BodyShapeKind, ?string, ?string, ?Codec<mixed>, string}>
     */
    public static function malformedBodies(): iterable
    {
        yield 'a record without its parameter' => [
            BodyShapeKind::Record,
            'pets',
            null,
            null,
            'a record body names a type and a bound parameter, and it names exactly those',
        ];
        yield 'a resource with a parameter' => [
            BodyShapeKind::Resource,
            'pets',
            'id',
            null,
            'a resource body names a type, and it names exactly those',
        ];
        yield 'a resource without a type' => [
            BodyShapeKind::Resource,
            null,
            null,
            null,
            'a resource body names a type, and it names exactly those',
        ];
        yield 'a linkage with a codec of meta' => [
            BodyShapeKind::ToOne,
            'customers',
            null,
            new StringCodec(),
            'a to-one body names a type, and it names exactly those',
        ];
        yield 'meta with a type' => [
            BodyShapeKind::Meta,
            'pets',
            null,
            new StringCodec(),
            'a meta body names a codec of its meta, and it names exactly those',
        ];
        yield 'meta without its codec' => [
            BodyShapeKind::Meta,
            null,
            null,
            null,
            'a meta body names a codec of its meta, and it names exactly those',
        ];
    }
}
