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

namespace Polunich\WpJsonApi\Tests\Unit\Operation;

use Generator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Routing\RouteKind;

/**
 * @internal
 */
#[CoversClass(OperationKind::class)]
final class OperationKindTest extends TestCase
{
    public function testTheCasesAreTheOperationsOfJsonApiWithTheirWireValues(): void
    {
        self::assertSame(
            [
                'FetchCollection' => 'fetch_collection',
                'FetchResource' => 'fetch_resource',
                'FetchRelated' => 'fetch_related',
                'FetchRelationship' => 'fetch_relationship',
                'CreateResource' => 'create_resource',
                'UpdateResource' => 'update_resource',
                'DeleteResource' => 'delete_resource',
                'ReplaceRelationship' => 'replace_relationship',
                'AddToRelationship' => 'add_to_relationship',
                'RemoveFromRelationship' => 'remove_from_relationship',
            ],
            array_combine(
                array_map(static function (OperationKind $kind): string {
                    return $kind->name;
                }, OperationKind::cases()),
                array_map(static function (OperationKind $kind): string {
                    return $kind->value;
                }, OperationKind::cases()),
            ),
        );
    }

    public function testEveryCaseIsFoundByItsValue(): void
    {
        foreach (OperationKind::cases() as $kind) {
            self::assertSame($kind, OperationKind::from($kind->value));
        }
    }

    #[DataProvider('kinds')]
    public function testTellsWhatTheKindDoes(
        OperationKind $kind,
        bool $fetch,
        bool $relationship,
        bool $existing,
        bool $content,
        bool $conditional,
    ): void {
        self::assertSame($fetch, $kind->isFetch());
        self::assertSame($relationship, $kind->targetsRelationship());
        self::assertSame($existing, $kind->actsOnExistingResource());
        self::assertSame($content, $kind->carriesContent());
        self::assertSame($conditional, $kind->isConditionalWrite());
    }

    /**
     * a new case fails here until `kinds()` states its predicates
     */
    public function testThePredicatesAreStatedForEveryCase(): void
    {
        self::assertSame(
            OperationKind::cases(),
            array_values(array_map(static function (array $case): OperationKind {
                return $case[0];
            }, iterator_to_array(self::kinds()))),
        );
    }

    /**
     * each case with its `isFetch()`, `targetsRelationship()`, `actsOnExistingResource()`,
     * `carriesContent()` and `isConditionalWrite()`
     *
     * @return Generator<string, array{OperationKind, bool, bool, bool, bool, bool}>
     */
    public static function kinds(): Generator
    {
        yield 'fetch collection' => [OperationKind::FetchCollection, true, false, false, false, false];
        yield 'fetch resource' => [OperationKind::FetchResource, true, false, true, false, false];
        yield 'fetch related' => [OperationKind::FetchRelated, true, true, true, false, false];
        yield 'fetch relationship' => [OperationKind::FetchRelationship, true, true, true, false, false];
        yield 'create resource' => [OperationKind::CreateResource, false, false, false, true, false];
        yield 'update resource' => [OperationKind::UpdateResource, false, false, true, true, true];
        yield 'delete resource' => [OperationKind::DeleteResource, false, false, true, false, true];
        yield 'replace relationship' => [OperationKind::ReplaceRelationship, false, true, true, true, true];
        yield 'add to relationship' => [OperationKind::AddToRelationship, false, true, true, true, true];
        yield 'remove from relationship' => [OperationKind::RemoveFromRelationship, false, true, true, true, true];
    }

    /**
     * @param non-empty-list<string> $methods
     */
    #[DataProvider('routes')]
    public function testNamesTheRouteAndTheMethodsOfEachKind(
        OperationKind $kind,
        RouteKind $route,
        string $method,
        array $methods,
    ): void {
        self::assertSame($route, $kind->route());
        self::assertSame($method, $kind->method());
        self::assertSame($methods, $kind->methods());
    }

    /**
     * @return iterable<string, array{OperationKind, RouteKind, string, non-empty-list<string>}>
     */
    public static function routes(): iterable
    {
        yield 'fetch collection' => [OperationKind::FetchCollection, RouteKind::Collection, 'GET', ['GET', 'HEAD']];
        yield 'create resource' => [OperationKind::CreateResource, RouteKind::Collection, 'POST', ['POST']];
        yield 'fetch resource' => [OperationKind::FetchResource, RouteKind::Resource, 'GET', ['GET', 'HEAD']];
        yield 'update resource' => [OperationKind::UpdateResource, RouteKind::Resource, 'PATCH', ['PATCH']];
        yield 'delete resource' => [OperationKind::DeleteResource, RouteKind::Resource, 'DELETE', ['DELETE']];
        yield 'fetch related' => [OperationKind::FetchRelated, RouteKind::Related, 'GET', ['GET', 'HEAD']];
        yield 'fetch relationship' => [
            OperationKind::FetchRelationship,
            RouteKind::Relationship,
            'GET',
            ['GET', 'HEAD'],
        ];
        yield 'replace relationship' => [
            OperationKind::ReplaceRelationship,
            RouteKind::Relationship,
            'PATCH',
            ['PATCH'],
        ];
        yield 'add to relationship' => [OperationKind::AddToRelationship, RouteKind::Relationship, 'POST', ['POST']];
        yield 'remove from relationship' => [
            OperationKind::RemoveFromRelationship,
            RouteKind::Relationship,
            'DELETE',
            ['DELETE'],
        ];
    }

    public function testEveryMethodOfEveryKindReadsBackToThatKind(): void
    {
        foreach (OperationKind::cases() as $kind) {
            foreach ($kind->methods() as $method) {
                self::assertSame($kind, OperationKind::onRoute($kind->route(), $method), $kind->value . ' ' . $method);
            }
        }
    }

    /**
     * the atomic route has no kind of its own, as each operation in its document states one;
     * a method token is case-sensitive, so `get` is no method of any kind
     */
    public function testAMethodNoKindRespondsToReadsAsNone(): void
    {
        self::assertNull(OperationKind::onRoute(RouteKind::Related, 'POST'));
        self::assertNull(OperationKind::onRoute(RouteKind::Collection, 'PUT'));
        self::assertNull(OperationKind::onRoute(RouteKind::Atomic, 'POST'));
        self::assertNull(OperationKind::onRoute(RouteKind::Resource, 'get'));
    }
}
