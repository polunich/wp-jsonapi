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

namespace Polunich\WpJsonApi\Tests\Unit\Routing;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Routing\RouteKind;
use Polunich\WpJsonApi\Routing\RouteTarget;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(RouteTarget::class)]
final class RouteTargetTest extends TestCase
{
    public function testTheClassIsInternal(): void
    {
        self::assertMatchesRegularExpression(
            '/@internal\b/',
            (string) (new ReflectionClass(RouteTarget::class))->getDocComment(),
        );
    }

    public function testTheClassAndEveryMemberAreReadonly(): void
    {
        $class = new ReflectionClass(RouteTarget::class);

        self::assertTrue($class->isReadOnly());

        foreach ($class->getProperties() as $property) {
            self::assertTrue($property->isReadOnly(), $property->getName() . ' is not readonly');
        }
    }

    public function testTheAtomicRouteAddressesNoTypeIdOrRelationship(): void
    {
        $target = new RouteTarget(RouteKind::Atomic, null, null, null);

        self::assertNull($target->type);
        self::assertNull($target->id);
        self::assertNull($target->relationship);
    }

    /**
     * @param array{RouteKind, ?string, ?string, ?string} $members
     */
    #[DataProvider('wellFormedTargets')]
    public function testAWellFormedTargetIsAccepted(array $members): void
    {
        $target = new RouteTarget(...$members);

        self::assertSame($members[0], $target->kind);
        self::assertSame($members[1], $target->type);
        self::assertSame($members[2], $target->id);
        self::assertSame($members[3], $target->relationship);
    }

    /**
     * @return array<string, array{array{RouteKind, ?string, ?string, ?string}}>
     */
    public static function wellFormedTargets(): array
    {
        return [
            'a collection route' => [[RouteKind::Collection, 'pets', null, null]],
            'a resource route' => [[RouteKind::Resource, 'pets', '1', null]],
            'a related route' => [[RouteKind::Related, 'pets', '1', 'tags']],
            'a relationship route' => [[RouteKind::Relationship, 'pets', '1', 'tags']],
            'the atomic route' => [[RouteKind::Atomic, null, null, null]],
        ];
    }

    /**
     * @param array{RouteKind, ?string, ?string, ?string} $members
     */
    #[DataProvider('illFormedTargets')]
    public function testATargetThatDisagreesWithItsKindIsRefused(array $members, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new RouteTarget(...$members);
    }

    /**
     * @return array<string, array{array{RouteKind, ?string, ?string, ?string}, string}>
     */
    public static function illFormedTargets(): array
    {
        return [
            'a collection route without a type' => [
                [RouteKind::Collection, null, null, null],
                'the collection route needs type, which is missing',
            ],
            'a collection route with the empty type' => [
                [RouteKind::Collection, '', null, null],
                'the collection route needs type, which is missing',
            ],
            'a collection route with an id' => [
                [RouteKind::Collection, 'pets', '1', null],
                'the collection route addresses no id, got "1"',
            ],
            'a collection route with a relationship' => [
                [RouteKind::Collection, 'pets', null, 'tags'],
                'the collection route addresses no relationship, got "tags"',
            ],
            'a resource route without an id' => [
                [RouteKind::Resource, 'pets', null, null],
                'the resource route needs id, which is missing',
            ],
            'a resource route with the empty id' => [
                [RouteKind::Resource, 'pets', '', null],
                'the resource route needs id, which is missing',
            ],
            'a resource route with a relationship' => [
                [RouteKind::Resource, 'pets', '1', 'tags'],
                'the resource route addresses no relationship, got "tags"',
            ],
            'a related route without a relationship' => [
                [RouteKind::Related, 'pets', '1', null],
                'the related route needs relationship, which is missing',
            ],
            'a related route with the empty relationship' => [
                [RouteKind::Related, 'pets', '1', ''],
                'the related route needs relationship, which is missing',
            ],
            'a relationship route without an id' => [
                [RouteKind::Relationship, 'pets', null, 'tags'],
                'the relationship route needs id, which is missing',
            ],
            'the atomic route with a type' => [
                [RouteKind::Atomic, 'pets', null, null],
                'the atomic route addresses no type, got "pets"',
            ],
            'the atomic route with an id' => [
                [RouteKind::Atomic, null, '1', null],
                'the atomic route addresses no id, got "1"',
            ],
            'the atomic route with a relationship' => [
                [RouteKind::Atomic, null, null, 'tags'],
                'the atomic route addresses no relationship, got "tags"',
            ],
        ];
    }
}
