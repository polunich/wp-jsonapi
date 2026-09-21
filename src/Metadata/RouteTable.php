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

namespace Polunich\WpJsonApi\Metadata;

use InvalidArgumentException;
use OutOfBoundsException;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseShapeKind;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Routing\PathParameter;
use Polunich\WpJsonApi\Routing\RouteKind;
use Polunich\WpJsonApi\Routing\TemplateOverlaps;

/**
 * every route of a declared API, in one list. it matches no URL:
 * an adapter renders the patterns of its own router from `all()`.
 *
 * `of()` lists the types in declaration order, per type the collection and the resource route,
 * then per relationship its related and its relationship route;
 * then the endpoints in declaration order, and the atomic routes last, in declaration order.
 * an endpoint that takes the place of an excluded read route stands where that route stood.
 *
 * two routes collide when they respond to one method and one path can match both,
 * and when their templates lie on one hierarchy and name their parameters differently.
 * with no collision no path of one method matches two routes,
 * so the order in which an adapter registers them does not matter
 *
 * @internal
 */
final readonly class RouteTable
{
    /**
     * @var list<bool> whether the declaration enables each route of `$routes`, by position
     */
    private array $enabled;

    /**
     * the enabled methods of each path, in table order. a method stands once per path,
     * since two routes of one path that respond to one method collide
     *
     * @var array<string, list<string>>
     */
    private array $allowed;

    /**
     * `fetchRoute()` of each generated read, keyed by `fetchKey()`
     *
     * @var array<string, ?Route>
     */
    private array $fetchRoutes;

    /**
     * @param list<Route>      $routes
     * @param ResourceRegistry $resources declares every type and relationship a route names
     *
     * @throws InvalidArgumentException when two routes collide
     * @throws OutOfBoundsException     when a route names an undeclared type or relationship
     */
    public function __construct(private array $routes, ResourceRegistry $resources)
    {
        self::checkCollisions($routes);

        $enabled = [];
        $allowed = [];
        $fetchRoutes = [];

        foreach ($routes as $route) {
            $enables = self::declarationEnables($route, $resources);
            $enabled[] = $enables;
            $path = $route->template->path;
            $allowed[$path] ??= [];

            if ($enables) {
                $allowed[$path] = [...$allowed[$path], ...$route->methods];
            }

            $read = $route instanceof EndpointRoute ? $route->replaces : $route;

            if ($read instanceof OperationRoute && $read->operationKind->isFetch()) {
                $key = self::fetchKey($read->routeKind, $read->type, $read->relationship);
                $fetchRoutes[$key] = $enables ? $route : null;
            }
        }

        $this->enabled = $enabled;
        $this->allowed = $allowed;
        $this->fetchRoutes = $fetchRoutes;
    }

    /**
     * @param list<EndpointMetadata>    $endpoints    in declaration order
     * @param list<AtomicRouteMetadata> $atomicRoutes in declaration order
     *
     * @throws InvalidArgumentException when two routes collide,
     *                                  an endpoint responds to GET
     *                                  on the path of a kept read route,
     *                                  or it takes the place of a read route but not as it must
     */
    public static function of(ResourceRegistry $resources, array $endpoints, array $atomicRoutes): self
    {
        $generated = [];

        foreach ($resources->all() as $resource) {
            $idParameter = $resource->idParameter();

            foreach (self::routesOf($resource) as [$kind, $relationship]) {
                $generated[] = new OperationRoute(
                    $kind,
                    $resource->type,
                    $relationship?->name,
                    $kind->route()->addressesId() ? $idParameter : null,
                    $resource->path,
                );
            }
        }

        $reads = [];

        foreach ($generated as $position => $route) {
            if ($route->operationKind->isFetch()) {
                $reads[$route->template->hierarchy()] ??= $position;
            }
        }

        $routes = $generated;

        foreach ($endpoints as $endpoint) {
            $position = self::replacedBy($endpoint, $generated, $reads, $resources);

            // a read an earlier endpoint took the place of keeps it,
            // and this endpoint then collides with that one
            if ($position === null || $routes[$position] instanceof EndpointRoute) {
                $routes[] = new EndpointRoute($endpoint);

                continue;
            }

            $routes[$position] = new EndpointRoute($endpoint, $generated[$position]);
        }

        foreach ($atomicRoutes as $atomicRoute) {
            $routes[] = new AtomicRoute($atomicRoute);
        }

        return new self($routes, $resources);
    }

    /**
     * what an adapter registers, including the routes the declaration does not enable:
     * a request to them must reach the library to get 403,
     * since a method with no registered route never reaches it
     *
     * @return list<Route>
     */
    public function all(): array
    {
        return $this->routes;
    }

    /**
     * whether the declaration enables the route:
     * its kind is enabled and, for a route of a relationship, that relationship offers it.
     * false for a route the table does not list
     */
    public function enables(Route $route): bool
    {
        $position = array_search($route, $this->routes, true);

        return $position !== false && $this->enabled[$position];
    }

    /**
     * whether `fetchRoute()` finds a route. a link may point only at such a route:
     * JSON:API requires a server to respond to a GET of every self and related link it provides
     * (Fetching Resources, Fetching Relationships)
     *
     * @param ?string $relationship the relationship a related or relationship route addresses
     */
    public function fetches(RouteKind $kind, string $type, ?string $relationship): bool
    {
        return $this->fetchRoute($kind, $type, $relationship) !== null;
    }

    /**
     * the route that responds to a GET of that route, null for none:
     * its fetch route where the declaration enables it, or the endpoint in its place
     *
     * @param ?string $relationship the relationship a related or relationship route addresses
     */
    public function fetchRoute(RouteKind $kind, string $type, ?string $relationship): ?Route
    {
        return $this->fetchRoutes[self::fetchKey($kind, $type, $relationship)] ?? null;
    }

    /**
     * the `Allow` field of a 405:
     * the methods the declaration enables on the path of that route, in table order.
     * any route stands for its whole path,
     * so a caller needs none that responds to the refused method.
     *
     * the declaration narrows the list, a permission never does:
     * the field states what the resource supports (RFC 9110, section 10.2.1),
     * and asking permission implementations while an error is rendered would be a side effect.
     *
     * empty for null, for a path the table does not list, and for a path with no enabled method,
     * which is exactly what an empty `Allow` value says: "the resource allows no methods"
     *
     * @return list<string>
     */
    public function allowedMethods(?Route $route): array
    {
        if ($route === null) {
            return [];
        }

        return $this->allowed[$route->template->path] ?? [];
    }

    /**
     * an atomic route trips the first rule beside the create route of a type
     * whose collection lies at its path,
     * or beside an endpoint that responds to POST on a path it can match.
     * the checks read the built templates, not a second spelling of the layout
     *
     * @param list<Route> $routes
     *
     * @throws InvalidArgumentException when two routes collide
     */
    private static function checkCollisions(array $routes): void
    {
        $overlap = TemplateOverlaps::first(array_map(static function (Route $route): array {
            return [$route->methods, $route->template];
        }, $routes), false);
        $namedApart = self::firstNamedApart($routes);

        // the pair first in table order is refused, as an overlap where it is both
        if (
            $overlap !== null
            && (
                $namedApart === null
                || $overlap[0] < $namedApart[0]
                || ($overlap[0] === $namedApart[0] && $overlap[1] <= $namedApart[1])
            )
        ) {
            $route = $routes[$overlap[0]];
            $later = $routes[$overlap[1]];

            throw new InvalidArgumentException(sprintf(
                'the %s on "%s" and the %s on "%s" both respond to %s, and a path can match both, so no'
                . ' registered route and no published contract could tell them apart',
                $route->subject(),
                $route->template->path,
                $later->subject(),
                $later->template->path,
                $route->sharedMethod($later),
            ));
        }

        if ($namedApart !== null) {
            $route = $routes[$namedApart[0]];
            $later = $routes[$namedApart[1]];

            throw new InvalidArgumentException(sprintf(
                'the %s on "%s" and the %s on "%s" lie on one path hierarchy and name its parameters'
                . ' differently, and OpenAPI says "Templated paths with the same hierarchy but different'
                . ' templated names MUST NOT exist as they are identical": name them "%s", as the first'
                . ' route does',
                $route->subject(),
                $route->template->path,
                $later->subject(),
                $later->template->path,
                implode('", "', self::namesOf($route)),
            ));
        }
    }

    /**
     * the first pair in table order of two routes on one hierarchy
     * that name their parameters apart.
     * its earlier route is the first route of the hierarchy:
     * of two routes that name them apart, one names them apart from the first route as well
     *
     * @param list<Route> $routes
     *
     * @return ?array{int, int} the positions of the earlier and the later route
     */
    private static function firstNamedApart(array $routes): ?array
    {
        $firsts = [];
        $first = null;

        foreach ($routes as $position => $route) {
            $earlier = $firsts[$route->template->hierarchy()] ??= $position;

            if ($earlier === $position || self::namesOf($routes[$earlier]) === self::namesOf($route)) {
                continue;
            }

            if ($first === null || $earlier < $first[0]) {
                $first = [$earlier, $position];
            }
        }

        return $first;
    }

    /**
     * @return list<string>
     */
    private static function namesOf(Route $route): array
    {
        return array_map(static function (PathParameter $parameter): string {
            return $parameter->name;
        }, $route->template->parameters());
    }

    /**
     * the position of the excluded read route the endpoint takes the place of:
     * the first generated read on the hierarchy of the endpoint's template
     *
     * @param list<OperationRoute> $generated
     * @param array<string, int>   $reads     hierarchy => the position of its first generated read
     *
     * @throws InvalidArgumentException when that read is not excluded,
     *                                  or the endpoint does not take its place as it must
     */
    private static function replacedBy(
        EndpointMetadata $endpoint,
        array $generated,
        array $reads,
        ResourceRegistry $resources,
    ): ?int {
        $position = $reads[$endpoint->template->hierarchy()] ?? null;

        if (!$endpoint->isSafe() || $position === null) {
            return null;
        }

        $route = $generated[$position];

        if (!self::excludes($route, $resources)) {
            throw new InvalidArgumentException(sprintf(
                'the endpoint "%s" responds to GET on "%s", the path of the %s, which the declaration keeps: an'
                . ' endpoint takes the place of a read route once the declaration excludes it with except() or'
                . ' only()',
                $endpoint->name,
                $endpoint->template->path,
                $route->subject(),
            ));
        }

        self::checkReplacement($endpoint, $route);

        return $position;
    }

    /**
     * @throws OutOfBoundsException when the route names an undeclared type or relationship
     */
    private static function excludes(OperationRoute $route, ResourceRegistry $resources): bool
    {
        return $route->relationship === null
            ? $resources->get($route->type)->excluded->excludes($route->operationKind)
            : $resources->relationshipOf($route->type, $route->relationship)->excluded->excludes($route->operationKind);
    }

    /**
     * the endpoint responds to every link of the route:
     * its template names the id parameter as the route does, bound to the route's type,
     * so it takes that type's id codec,
     * and loads its record as the route loads its target or parent;
     * it requires no query parameter a link to the route could not carry;
     * and its response of 200 is what the route responded with:
     * for the collection route, a page of its type or all of its resources at once
     *
     * @throws InvalidArgumentException when the endpoint does not take the place as it must
     */
    private static function checkReplacement(EndpointMetadata $endpoint, OperationRoute $route): void
    {
        $subject = $route->subject();
        $parameter = null;

        if ($route->idParameter !== null) {
            $parameter = $route->idParameter->name;

            if (($endpoint->bindings[$parameter] ?? null) !== $route->type) {
                throw new InvalidArgumentException(sprintf(
                    'the endpoint "%s" takes the place of the %s, and so names the parameter "%s" as that route does'
                    . ' and binds it to type "%s": every link to the route carries an id of that type, and a request'
                    . ' for a missing record gets 404 as it does on the route',
                    $endpoint->name,
                    $subject,
                    $parameter,
                    $route->type,
                ));
            }
        }

        foreach ($endpoint->queryParameters as $queryParameter) {
            RouteQueryParameters::checkLinked($route->operationKind, $queryParameter, sprintf(
                'the endpoint "%s", which takes the place of the %s,',
                $endpoint->name,
                $subject,
            ));
        }

        $response = $endpoint->response(200);
        $expected = match ($route->operationKind) {
            OperationKind::FetchCollection => in_array(
                $response?->kind,
                [ResponseShapeKind::Collection, ResponseShapeKind::Page],
                true,
            ) && $response->type === $route->type,
            OperationKind::FetchResource => $response?->kind === ResponseShapeKind::Record
                && $response->parameter === $parameter,
            OperationKind::FetchRelated => $response?->kind === ResponseShapeKind::Related
                && $response->parameter === $parameter
                && $response->relationship === $route->relationship,
            default => $response?->kind === ResponseShapeKind::Linkage
                && $response->parameter === $parameter
                && $response->relationship === $route->relationship,
        };

        if (!$expected) {
            throw new InvalidArgumentException(sprintf(
                'the endpoint "%s" takes the place of the %s, and so responds with 200 and %s, as every link to the'
                . ' route promises',
                $endpoint->name,
                $subject,
                match ($route->operationKind) {
                    OperationKind::FetchCollection => sprintf(
                        'ResponseShape::page("%s") or ResponseShape::collection("%s")',
                        $route->type,
                        $route->type,
                    ),
                    OperationKind::FetchResource => sprintf('ResponseShape::record("%s")', (string) $parameter),
                    OperationKind::FetchRelated => sprintf(
                        'ResponseShape::related("%s", "%s")',
                        (string) $parameter,
                        (string) $route->relationship,
                    ),
                    default => sprintf(
                        'ResponseShape::linkage("%s", "%s")',
                        (string) $parameter,
                        (string) $route->relationship,
                    ),
                },
            ));
        }
    }

    /**
     * a to-one relationship gets no add and no remove route:
     * it cannot declare those updates, so such a route could only ever respond with 403.
     * the replace route is listed for every relationship,
     * so a request to one that declares no replace gets 403 instead of never reaching the library
     *
     * @return list<array{OperationKind, ?RelationshipMetadata}>
     */
    private static function routesOf(ResourceMetadata $resource): array
    {
        $routes = [
            [OperationKind::FetchCollection, null],
            [OperationKind::CreateResource, null],
            [OperationKind::FetchResource, null],
            [OperationKind::UpdateResource, null],
            [OperationKind::DeleteResource, null],
        ];

        foreach ($resource->relationships as $relationship) {
            $routes[] = [OperationKind::FetchRelated, $relationship];
            $routes[] = [OperationKind::FetchRelationship, $relationship];
            $routes[] = [OperationKind::ReplaceRelationship, $relationship];

            if ($relationship->cardinality === Cardinality::ToMany) {
                $routes[] = [OperationKind::AddToRelationship, $relationship];
                $routes[] = [OperationKind::RemoveFromRelationship, $relationship];
            }
        }

        return $routes;
    }

    /**
     * a member name holds no control character, so no two routes join into one key
     */
    private static function fetchKey(RouteKind $kind, string $type, ?string $relationship): string
    {
        return $kind->value . "\0" . $type . "\0" . ($relationship ?? '');
    }

    /**
     * a route the declaration does not enable is refused to every client,
     * so it is no "currently supported method" (RFC 9110, section 15.5.6).
     * an atomic route has no kind to disable, so its one method is always allowed
     *
     * @throws OutOfBoundsException when the route names an undeclared type or relationship
     */
    private static function declarationEnables(Route $route, ResourceRegistry $resources): bool
    {
        // an endpoint guards itself with its permission
        if (!$route instanceof OperationRoute) {
            return true;
        }

        if (!$resources->get($route->type)->enables($route->operationKind)) {
            return false;
        }

        // `OperationRoute` names a relationship exactly where its kind addresses one
        return $route->relationship === null
            || $resources->relationshipOf($route->type, $route->relationship)->enables($route->operationKind);
    }
}
