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

namespace Polunich\WpJsonApi\Tests\Unit\Metadata;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Access\OperationAttempt;
use Polunich\WpJsonApi\Codec\DigitsCodec;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Metadata\OperationRoute;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Routing\PathParameter;
use Polunich\WpJsonApi\Routing\RouteKind;
use Polunich\WpJsonApi\Routing\RouteTarget;
use Polunich\WpJsonApi\Tests\Fixtures\ParameterNames;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * the route of every operation kind is pinned one by one,
 * since it must invert `Parsing\OperationParser::kindOf()`
 * and match the paths `OpenApi\PathBuilder` publishes:
 * otherwise a request would run a kind the gate never checked,
 * or a route would be registered that the contract does not name
 *
 * @internal
 */
#[CoversClass(OperationRoute::class)]
final class OperationRouteTest extends TestCase
{
    /** a member name that needs no percent-encoding */
    private const TYPE = 'pets';

    private const RELATIONSHIP = 'orders';

    /** the id parameter a type gets unless it names its own */
    private const ID = 'id';

    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(OperationRoute::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    /**
     * an adapter reads the derived properties, so their names are part of the surface
     */
    public function testThePropertiesAndMethodsAnAdapterReadsKeepTheirNamesAndTypes(): void
    {
        $constructor = (new ReflectionMethod(OperationRoute::class, '__construct'))->getParameters();
        $target = (new ReflectionMethod(OperationRoute::class, 'target'))->getParameters();

        self::assertSame(
            ['operationKind', 'type', 'relationship', 'idParameter', 'path'],
            ParameterNames::of($constructor),
        );
        self::assertSame(OperationKind::class, (string) $constructor[0]->getType());
        self::assertSame('string', (string) $constructor[1]->getType());
        self::assertSame('?string', (string) $constructor[2]->getType());
        self::assertSame('?' . PathParameter::class, (string) $constructor[3]->getType());
        self::assertSame('array', (string) $constructor[4]->getType());

        self::assertSame(['request'], ParameterNames::of($target));
        self::assertSame(ServerRequest::class, (string) $target[0]->getType());
        self::assertSame(
            RouteTarget::class,
            (string) (new ReflectionMethod(OperationRoute::class, 'target'))->getReturnType(),
        );

        self::assertSame(
            ['operationKind', 'type', 'relationship', 'idParameter', 'routeKind', 'template', 'methods'],
            array_map(
                static function (ReflectionProperty $property): string {
                    return $property->getName();
                },
                (new ReflectionClass(OperationRoute::class))->getProperties(),
            ),
        );
    }

    /**
     * @param array{OperationKind, RouteKind, string, non-empty-list<string>} $route
     */
    #[DataProvider('kinds')]
    public function testEveryKindStandsOnItsRouteKind(array $route): void
    {
        self::assertSame($route[1], self::route($route[0])->routeKind);
    }

    /**
     * @param array{OperationKind, RouteKind, string, non-empty-list<string>} $route
     */
    #[DataProvider('kinds')]
    public function testEveryKindRespondsToItsMethods(array $route): void
    {
        self::assertSame($route[3], self::route($route[0])->methods);
    }

    /**
     * the paths are literals here;
     * `RouteTableTest` checks a whole table against a generated contract
     *
     * @param array{OperationKind, RouteKind, string, non-empty-list<string>} $route
     */
    #[DataProvider('kinds')]
    public function testEveryKindStandsOnThePathThePublishedContractWrites(array $route): void
    {
        self::assertSame($route[2], self::route($route[0])->template->path);
    }

    /**
     * @return array<string, array{array{OperationKind, RouteKind, string, non-empty-list<string>}}>
     */
    public static function kinds(): array
    {
        return [
            'a collection reading' => [
                [OperationKind::FetchCollection, RouteKind::Collection, '/pets', ['GET', 'HEAD']],
            ],
            'a create' => [
                [OperationKind::CreateResource, RouteKind::Collection, '/pets', ['POST']],
            ],
            'a resource reading' => [
                [OperationKind::FetchResource, RouteKind::Resource, '/pets/{id}', ['GET', 'HEAD']],
            ],
            'an update' => [
                [OperationKind::UpdateResource, RouteKind::Resource, '/pets/{id}', ['PATCH']],
            ],
            'a delete' => [
                [OperationKind::DeleteResource, RouteKind::Resource, '/pets/{id}', ['DELETE']],
            ],
            'a related reading' => [
                [OperationKind::FetchRelated, RouteKind::Related, '/pets/{id}/orders', ['GET', 'HEAD']],
            ],
            'a relationship reading' => [[
                OperationKind::FetchRelationship,
                RouteKind::Relationship,
                '/pets/{id}/relationships/orders',
                ['GET', 'HEAD'],
            ]],
            'a relationship replace' => [[
                OperationKind::ReplaceRelationship,
                RouteKind::Relationship,
                '/pets/{id}/relationships/orders',
                ['PATCH'],
            ]],
            'an add to a relationship' => [[
                OperationKind::AddToRelationship,
                RouteKind::Relationship,
                '/pets/{id}/relationships/orders',
                ['POST'],
            ]],
            'a remove from a relationship' => [[
                OperationKind::RemoveFromRelationship,
                RouteKind::Relationship,
                '/pets/{id}/relationships/orders',
                ['DELETE'],
            ]],
        ];
    }

    public function testAReadingRespondsToHeadBesideGet(): void
    {
        $readings = [
            OperationKind::FetchCollection,
            OperationKind::FetchResource,
            OperationKind::FetchRelated,
            OperationKind::FetchRelationship,
        ];

        foreach ($readings as $kind) {
            self::assertSame(['GET', 'HEAD'], self::route($kind)->methods, $kind->value);
        }

        $writes = [
            OperationKind::CreateResource,
            OperationKind::UpdateResource,
            OperationKind::DeleteResource,
            OperationKind::ReplaceRelationship,
            OperationKind::AddToRelationship,
            OperationKind::RemoveFromRelationship,
        ];

        foreach ($writes as $kind) {
            self::assertFalse(self::route($kind)->respondsTo('HEAD'), $kind->value);
        }
    }

    /**
     * the template holds the names as declared;
     * its path, as the contract and WordPress write it, encodes them
     */
    public function testATypeAndARelationshipThatAreNoUrlSegmentsStandInTheTemplateAsDeclared(): void
    {
        $related = new OperationRoute(
            OperationKind::FetchRelated,
            'pet toys',
            'bought by',
            self::idParameter(),
            ['pet toys'],
        );
        $relationship = new OperationRoute(
            OperationKind::ReplaceRelationship,
            'pet toys',
            'bought by',
            self::idParameter(),
            ['pet toys'],
        );

        self::assertSame(['pet toys', $related->idParameter, 'bought by'], $related->template->segments);
        self::assertSame('/pet%20toys/{id}/bought%20by', $related->template->path);
        self::assertSame('/pet%20toys/{id}/relationships/bought%20by', $relationship->template->path);
        self::assertSame('/pets', self::route(OperationKind::FetchCollection)->template->path);
    }

    public function testTheIdParameterStandsInTheTemplateUnderTheNameTheTypeGivesIt(): void
    {
        $parameter = new PathParameter('pet_id', new DigitsCodec());
        $resource = new OperationRoute(OperationKind::FetchResource, self::TYPE, null, $parameter, [self::TYPE]);
        $relationship = new OperationRoute(
            OperationKind::FetchRelationship,
            self::TYPE,
            self::RELATIONSHIP,
            $parameter,
            [self::TYPE],
        );

        self::assertSame([self::TYPE, $parameter], $resource->template->segments);
        self::assertSame('/pets/{pet_id}', $resource->template->path);
        self::assertSame('/pets/{pet_id}/relationships/orders', $relationship->template->path);
        self::assertSame([$parameter], $relationship->template->parameters());
        self::assertSame('7', $resource->target(self::request(['pet_id' => '7', self::ID => '8']))->id);
    }

    #[DataProvider('routesThatAddressAnId')]
    public function testARouteThatAddressesAnIdIsRefusedWithoutItsParameter(
        OperationKind $kind,
        string $routeKind,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'the %s route addresses an id, and it names no path parameter to carry it',
            $routeKind,
        ));

        new OperationRoute(
            $kind,
            self::TYPE,
            $kind->targetsRelationship() ? self::RELATIONSHIP : null,
            null,
            [self::TYPE],
        );
    }

    /**
     * @return iterable<string, array{OperationKind, string}>
     */
    public static function routesThatAddressAnId(): iterable
    {
        yield 'the resource route' => [OperationKind::FetchResource, 'resource'];
        yield 'the related route' => [OperationKind::FetchRelated, 'related'];
        yield 'the relationship route' => [OperationKind::RemoveFromRelationship, 'relationship'];
    }

    public function testTheCollectionRouteIsRefusedWithAnIdParameter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the collection route addresses no id, got the path parameter "id"');

        new OperationRoute(OperationKind::CreateResource, self::TYPE, null, self::idParameter(), [self::TYPE]);
    }

    public function testAKindThatAddressesARelationshipIsRefusedWithoutOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'a fetch_related route addresses a relationship of its type, and it names none',
        );

        new OperationRoute(OperationKind::FetchRelated, self::TYPE, null, self::idParameter(), [self::TYPE]);
    }

    public function testAKindThatAddressesNoRelationshipIsRefusedWithOne(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a fetch_resource route addresses no relationship, got "orders"');

        new OperationRoute(
            OperationKind::FetchResource,
            self::TYPE,
            self::RELATIONSHIP,
            self::idParameter(),
            [self::TYPE],
        );
    }

    public function testAnEmptyTypeIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a route addresses a resource type, and a type is never empty');

        new OperationRoute(OperationKind::FetchCollection, '', null, null, ['']);
    }

    public function testAnEmptyRelationshipIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a route addresses a relationship, whose name is never empty');

        new OperationRoute(OperationKind::FetchRelated, self::TYPE, '', self::idParameter(), [self::TYPE]);
    }

    public function testAMessageNamesTheRouteByItsKindAndItsType(): void
    {
        self::assertSame(
            'add_to_relationship route of type "pets"',
            self::route(OperationKind::AddToRelationship)->subject(),
        );
    }

    public function testTheTargetCarriesTheRouteKindTheTypeAndTheRelationshipOfTheRoute(): void
    {
        $route = self::route(OperationKind::AddToRelationship);
        $target = $route->target(self::request([self::ID => '7']));

        self::assertSame($route->routeKind, $target->kind);
        self::assertSame($route->type, $target->type);
        self::assertSame($route->relationship, $target->relationship);
    }

    public function testTheTargetReadsTheIdOfThePathParametersUnderTheNameTheLibraryWrites(): void
    {
        $target = self::route(OperationKind::FetchResource)->target(
            self::request(['post_id' => '1', self::ID => '7', 'rest_route' => '/pets/7']),
        );

        self::assertSame('7', $target->id);
    }

    /**
     * an upper-case UUID, which `Codec\UuidCodec` would lower-case, shows that no codec runs here
     */
    public function testTheIdOfTheTargetIsTheTextTheClientWroteAndPassesNoCodec(): void
    {
        $written = '5A9D40C2-1F4B-4E5A-9C3D-8B7E6F0A1D2C';

        $target = self::route(OperationKind::FetchResource)->target(
            self::request([self::ID => $written]),
        );

        self::assertSame($written, $target->id);
    }

    public function testTheTargetOfACollectionRouteCarriesNoIdAlthoughTheRequestNamesOne(): void
    {
        $target = self::route(OperationKind::FetchCollection)->target(
            self::request([self::ID => '7']),
        );

        self::assertSame(RouteKind::Collection, $target->kind);
        self::assertNull($target->id);
    }

    public function testARequestWithoutTheRouteIdIsALogicException(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'the resource route addresses an id, and the request carries no route parameter "id"',
        );

        self::route(OperationKind::FetchResource)->target(self::request([]));
    }

    public function testEveryRouteBuildsAnAttemptTheGateAccepts(): void
    {
        $request = self::request([self::ID => '7']);

        foreach (OperationKind::cases() as $kind) {
            $route = self::route($kind);
            $attempt = OperationAttempt::forKind($kind, self::TYPE, $route->relationship, $request);

            self::assertSame($kind, $attempt->kind);
            self::assertSame($route->type, $attempt->type);
            self::assertSame($route->relationship, $attempt->relationship);
            self::assertNull($attempt->record, $kind->value . ' is checked at level 1');
        }
    }

    private static function route(OperationKind $kind): OperationRoute
    {
        return new OperationRoute(
            $kind,
            self::TYPE,
            $kind->targetsRelationship() ? self::RELATIONSHIP : null,
            $kind->route()->addressesId() ? self::idParameter() : null,
            [self::TYPE],
        );
    }

    private static function idParameter(): PathParameter
    {
        return new PathParameter(self::ID, new DigitsCodec());
    }

    /**
     * @param array<string, string> $path
     */
    private static function request(array $path): ServerRequest
    {
        return new ServerRequest('GET', new Headers(), [], $path, '');
    }
}
