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

namespace Polunich\WpJsonApi\Tests\Unit\Declaration;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Declaration\ToOne;
use Polunich\WpJsonApi\Metadata\Cardinality;
use Polunich\WpJsonApi\Metadata\RelationshipUpdate;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use ReflectionClass;

/**
 * what a to-one relationship adds to `Relationship`, whose own parts `RelationshipTest` covers
 *
 * @internal
 */
#[CoversClass(ToOne::class)]
final class ToOneTest extends TestCase
{
    public function testTheClassIsFinalMutableAndPublicApi(): void
    {
        $class = new ReflectionClass(ToOne::class);

        self::assertTrue($class->isFinal());
        self::assertFalse($class->isReadOnly());
        self::assertStringNotContainsString('@internal', (string) $class->getDocComment());
        self::assertStringContainsString('@internal', (string) $class->getMethod('build')->getDocComment());
    }

    public function testEveryMethodReturnsTheBuilderItChanged(): void
    {
        $relationship = ToOne::make('owner', 'customers');

        self::assertSame($relationship, $relationship->relatedId(static function (Pet $pet): ?string {
            return $pet->ownerId;
        }));
        self::assertSame($relationship, $relationship->notNullable());
    }

    public function testCarriesWhatTheDeclarationStates(): void
    {
        $relationship = ToOne::make('owner', 'customers')
            ->relatedId(static function (Pet $pet): ?string {
                return $pet->ownerId;
            })
            ->replaceable()
            ->build();

        self::assertSame('owner', $relationship->name);
        self::assertSame(Cardinality::ToOne, $relationship->cardinality);
        self::assertSame('customers', $relationship->relatedType);
        self::assertTrue($relationship->readsRelatedId());
        self::assertSame('9', $relationship->relatedIdOf(new Pet('1', 'Rex', '9')));
        self::assertTrue($relationship->linkageAlwaysPresent);
        self::assertSame([RelationshipUpdate::Replace], $relationship->updates);
    }

    public function testAToOneRelationshipMayBeEmptyAndIsNeitherSortedNorPaged(): void
    {
        $relationship = ToOne::make('owner', 'customers')->build();

        self::assertFalse($relationship->readsRelatedId());
        self::assertTrue($relationship->nullable);
        self::assertFalse($relationship->linkageAlwaysPresent);
        self::assertNull($relationship->defaultSort);
        self::assertNull($relationship->paginationPolicy);
    }

    public function testALinkageWithoutAccessorIsRenderedWhereTheDeclarationSaysSo(): void
    {
        $relationship = ToOne::make('owner', 'customers')->linkageAlwaysPresent()->build();

        self::assertFalse($relationship->readsRelatedId());
        self::assertTrue($relationship->linkageAlwaysPresent);
    }

    public function testARelationshipThatIsNotNullableIsRequired(): void
    {
        $relationship = ToOne::make('owner', 'customers')
            ->notNullable()
            ->creatable()
            ->required()
            ->build();

        self::assertFalse($relationship->nullable);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('to-one relationship "owner" is not nullable and not required on create');

        ToOne::make('owner', 'customers')->notNullable()->build();
    }

    public function testRefusesARelatedIdAccessorWrittenTwice(): void
    {
        $relationship = ToOne::make('owner', 'customers')->relatedId(static function (Pet $pet): ?string {
            return $pet->ownerId;
        });

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'relationship "owner" declares a related id accessor twice, and each part of a declaration is written'
            . ' once',
        );

        $relationship->relatedId(static function (Pet $pet): ?string {
            return $pet->ownerId;
        });
    }
}
