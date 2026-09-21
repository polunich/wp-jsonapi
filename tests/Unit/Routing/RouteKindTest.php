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
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Routing\RouteKind;
use ReflectionClass;
use stdClass;

/**
 * @internal
 */
#[CoversClass(RouteKind::class)]
final class RouteKindTest extends TestCase
{
    public function testTheEnumIsInternal(): void
    {
        self::assertMatchesRegularExpression(
            '/@internal\b/',
            (string) (new ReflectionClass(RouteKind::class))->getDocComment(),
        );
    }

    /**
     * a closed set: a `match` on the kind needs no default arm
     */
    public function testTheCasesAreTheRoutesOfJsonApiAndOfTheAtomicExtension(): void
    {
        $values = array_map(static function (RouteKind $kind): string {
            return $kind->value;
        }, RouteKind::cases());

        self::assertSame(
            ['collection', 'resource', 'related', 'relationship', 'atomic'],
            $values,
        );
    }

    public function testEveryCaseIsFoundByItsValue(): void
    {
        self::assertSame(RouteKind::Collection, RouteKind::from('collection'));
        self::assertSame(RouteKind::Resource, RouteKind::from('resource'));
        self::assertSame(RouteKind::Related, RouteKind::from('related'));
        self::assertSame(RouteKind::Relationship, RouteKind::from('relationship'));
        self::assertSame(RouteKind::Atomic, RouteKind::from('atomic'));
    }

    /**
     * two kinds, since they read different query parameters:
     * a to-many related route takes sort and filter, a relationship route never does
     */
    public function testTheRelatedAndTheRelationshipRouteAreDistinct(): void
    {
        self::assertNotSame(RouteKind::Related, RouteKind::Relationship);
    }

    /**
     * @param list<string> $segments
     */
    #[DataProvider('layouts')]
    public function testEachKindNamesTheMembersItAddressesAndLaysOutItsSegments(
        RouteKind $kind,
        bool $type,
        bool $id,
        bool $relationship,
        array $segments,
    ): void {
        self::assertSame($type, $kind->addressesType());
        self::assertSame($id, $kind->addressesId());
        self::assertSame($relationship, $kind->addressesRelationship());
        self::assertSame(
            $segments,
            $kind->segments($type ? ['pets'] : null, $id ? '7' : null, $relationship ? 'owner' : null),
        );
    }

    /**
     * @return iterable<string, array{RouteKind, bool, bool, bool, list<string>}>
     */
    public static function layouts(): iterable
    {
        yield 'the collection route' => [RouteKind::Collection, true, false, false, ['pets']];
        yield 'the resource route' => [RouteKind::Resource, true, true, false, ['pets', '7']];
        yield 'the related route' => [RouteKind::Related, true, true, true, ['pets', '7', 'owner']];
        yield 'the relationship route' => [
            RouteKind::Relationship,
            true,
            true,
            true,
            ['pets', '7', 'relationships', 'owner'],
        ];
    }

    public function testAnAtomicRouteAddressesNoMemberAndHasNoLayout(): void
    {
        self::assertFalse(RouteKind::Atomic->addressesType());
        self::assertFalse(RouteKind::Atomic->addressesId());
        self::assertFalse(RouteKind::Atomic->addressesRelationship());

        RouteKind::Atomic->checkMembers(null, null, null);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'an atomic route lies at the path its declaration writes, which no layout of a kind gives',
        );

        RouteKind::Atomic->segments(null, null, null);
    }

    #[DataProvider('impossibleLayouts')]
    public function testCheckMembersRefusesWhatALayoutRefuses(
        RouteKind $kind,
        ?string $type,
        ?string $id,
        ?string $relationship,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $kind->checkMembers($type, $id, $relationship);
    }

    public function testTheIdMayBeAnObjectThatStandsInItsPlace(): void
    {
        $parameter = new stdClass();

        self::assertSame(['pets', $parameter, 'owner'], RouteKind::Related->segments(['pets'], $parameter, 'owner'));
    }

    public function testAnObjectWhereNoIdStandsIsNamedByItsType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the collection route addresses no id, got "stdClass"');

        RouteKind::Collection->segments(['pets'], new stdClass(), null);
    }

    public function testTheSegmentsCarryTheMembersAsTheyAreGiven(): void
    {
        self::assertSame(
            ['a b', 'x/y', 'relationships', 'c'],
            RouteKind::Relationship->segments(['a b'], 'x/y', 'c'),
        );
    }

    public function testEveryRouteOfATypeLiesBelowThePathOfTheType(): void
    {
        self::assertSame(['database', 'tables'], RouteKind::Collection->segments(['database', 'tables'], null, null));
        self::assertSame(
            ['database', 'tables', '7', 'relationships', 'owner'],
            RouteKind::Relationship->segments(['database', 'tables'], '7', 'owner'),
        );
    }

    /**
     * @param ?list<string> $path
     */
    #[DataProvider('impossibleSegments')]
    public function testRefusesAMissingOrAStrayMember(
        RouteKind $kind,
        ?array $path,
        ?string $id,
        ?string $relationship,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $kind->segments($path, $id, $relationship);
    }

    /**
     * @return iterable<string, array{RouteKind, ?list<string>, ?string, ?string, string}>
     */
    public static function impossibleSegments(): iterable
    {
        yield 'a collection without a path' => [
            RouteKind::Collection,
            null,
            null,
            null,
            'the collection route needs the path of its type, which is missing',
        ];
        yield 'a collection with the empty path' => [
            RouteKind::Collection,
            [],
            null,
            null,
            'the collection route needs the path of its type, which is missing',
        ];
        yield 'a resource with an empty id' => [
            RouteKind::Resource,
            ['pets'],
            '',
            null,
            'the resource route needs id, which is missing',
        ];
        yield 'a collection with an id' => [
            RouteKind::Collection,
            ['pets'],
            '7',
            null,
            'the collection route addresses no id, got "7"',
        ];
        yield 'the atomic route with a path' => [
            RouteKind::Atomic,
            ['database', 'tables'],
            null,
            null,
            'the atomic route addresses no type, got the path "/database/tables"',
        ];
        yield 'a related route without a relationship' => [
            RouteKind::Related,
            ['pets'],
            '7',
            null,
            'the related route needs relationship, which is missing',
        ];
    }

    /**
     * @return iterable<string, array{RouteKind, ?string, ?string, ?string, string}>
     */
    public static function impossibleLayouts(): iterable
    {
        yield 'a collection without a type' => [
            RouteKind::Collection,
            null,
            null,
            null,
            'the collection route needs type, which is missing',
        ];
        yield 'a resource with an empty id' => [
            RouteKind::Resource,
            'pets',
            '',
            null,
            'the resource route needs id, which is missing',
        ];
        yield 'a collection with an id' => [
            RouteKind::Collection,
            'pets',
            '7',
            null,
            'the collection route addresses no id, got "7"',
        ];
        yield 'the atomic route with a type' => [
            RouteKind::Atomic,
            'pets',
            null,
            null,
            'the atomic route addresses no type, got "pets"',
        ];
        yield 'a related route without a relationship' => [
            RouteKind::Related,
            'pets',
            '7',
            null,
            'the related route needs relationship, which is missing',
        ];
    }

    public function testAMemberTheRouteDoesNotAddressIsWrittenEscaped(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the collection route addresses no relationship, got "a\\nb"');

        RouteKind::Collection->segments(['pets'], null, "a\nb");
    }
}
