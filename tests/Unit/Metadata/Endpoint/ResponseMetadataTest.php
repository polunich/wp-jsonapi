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
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Metadata\Cardinality;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseShapeKind;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(ResponseMetadata::class)]
final class ResponseMetadataTest extends TestCase
{
    public function testTheClassIsInternalFinalAndReadonly(): void
    {
        $class = new ReflectionClass(ResponseMetadata::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    #[DataProvider('lists')]
    public function testAResponseIsAListWhereItsKindOrItsCardinalityHoldsMany(
        ResponseMetadata $response,
        bool $list,
    ): void {
        self::assertSame($list, $response->isList());
    }

    /**
     * @return iterable<string, array{ResponseMetadata, bool}>
     */
    public static function lists(): iterable
    {
        yield 'a collection' => [
            new ResponseMetadata(ResponseShapeKind::Collection, 'pets', null, null, null, null),
            true,
        ];
        yield 'a page' => [
            new ResponseMetadata(ResponseShapeKind::Page, 'pets', null, null, null, null),
            true,
        ];
        yield 'the related resources of a to-many relationship' => [
            new ResponseMetadata(ResponseShapeKind::Related, 'orders', 'id', 'orders', Cardinality::ToMany, null),
            true,
        ];
        yield 'the linkage of a to-one relationship' => [
            new ResponseMetadata(ResponseShapeKind::Linkage, 'customers', 'id', 'owner', Cardinality::ToOne, null),
            false,
        ];
        yield 'one resource' => [
            new ResponseMetadata(ResponseShapeKind::Resource, 'pets', null, null, null, null),
            false,
        ];
    }

    public function testTheStatusIsTheOneOfItsKind(): void
    {
        self::assertSame(
            202,
            (new ResponseMetadata(ResponseShapeKind::Accepted, 'jobs', null, null, null, null))->status(),
        );
    }

    /**
     * @param ?Codec<mixed> $meta
     */
    #[DataProvider('malformedResponses')]
    public function testAResponseWithAMemberItsKindDoesNotTakeIsRefused(
        ResponseShapeKind $kind,
        ?string $type,
        ?string $parameter,
        ?string $relationship,
        ?Cardinality $cardinality,
        ?Codec $meta,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new ResponseMetadata($kind, $type, $parameter, $relationship, $cardinality, $meta);
    }

    /**
     * @return iterable<string, array{
     *     ResponseShapeKind,
     *     ?string,
     *     ?string,
     *     ?string,
     *     ?Cardinality,
     *     ?Codec<mixed>,
     *     string,
     * }>
     */
    public static function malformedResponses(): iterable
    {
        yield 'a resource without a type' => [
            ResponseShapeKind::Resource,
            null,
            null,
            null,
            null,
            null,
            'a resource response names a type, and it names exactly those',
        ];
        yield 'a record without its parameter' => [
            ResponseShapeKind::Record,
            'pets',
            null,
            null,
            null,
            null,
            'a record response names a type, a bound parameter, and it names exactly those',
        ];
        yield 'a record with a cardinality' => [
            ResponseShapeKind::Record,
            'pets',
            'id',
            null,
            Cardinality::ToOne,
            null,
            'a record response names a type, a bound parameter, and it names exactly those',
        ];
        yield 'a related response without its relationship' => [
            ResponseShapeKind::Related,
            'orders',
            'id',
            null,
            null,
            null,
            'a related response names a type, a bound parameter, a relationship with its cardinality',
        ];
        yield 'a related response without its cardinality' => [
            ResponseShapeKind::Related,
            'orders',
            'id',
            'orders',
            null,
            null,
            'a related response names a type, a bound parameter, a relationship with its cardinality',
        ];
        yield 'no content with a type' => [
            ResponseShapeKind::NoContent,
            'pets',
            null,
            null,
            null,
            null,
            'a no-content response names nothing, and it names exactly those',
        ];
        yield 'meta without its codec' => [
            ResponseShapeKind::Meta,
            null,
            null,
            null,
            null,
            null,
            'a meta response names a codec of its meta',
        ];
        yield 'a collection with a codec of meta' => [
            ResponseShapeKind::Collection,
            'pets',
            null,
            null,
            null,
            new IntegerCodec(),
            'a collection response names a type, and it names exactly those',
        ];
    }
}
