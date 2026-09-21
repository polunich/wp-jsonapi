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
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Query\ParameterFamily;

/**
 * the query parameters one route reads, the language parameters aside:
 * the families `ParameterFamilies` assigns to it and the parameters it declares
 *
 * @internal
 */
final readonly class RouteQuery
{
    /**
     * @param list<ParameterFamily>        $families in the order of the `ParameterFamily` cases
     * @param list<QueryParameterMetadata> $declared in declaration order
     */
    public function __construct(
        public array $families,
        public array $declared,
    ) {}

    /**
     * @throws OutOfBoundsException when the type or the relationship of the route is not declared
     */
    public static function ofRoute(Route $route, ResourceRegistry $resources): self
    {
        if ($route instanceof EndpointRoute) {
            return self::ofEndpoint($route->endpoint);
        }

        if ($route instanceof OperationRoute) {
            return self::ofKind($route->operationKind, $route->type, $route->relationship, $resources);
        }

        return self::ofAtomicRoute();
    }

    /**
     * @param ?string $relationship given exactly for a kind that addresses a relationship
     *
     * @throws InvalidArgumentException when the relationship is missing for a relationship kind,
     *                                  or given for another
     * @throws OutOfBoundsException     when the type or the relationship is not declared
     */
    public static function ofKind(
        OperationKind $kind,
        string $type,
        ?string $relationship,
        ResourceRegistry $resources,
    ): self {
        $declared = $resources->queryParametersOf($kind, $type, $relationship);

        return new self(
            ParameterFamilies::processedBy(
                $kind,
                $relationship === null ? null : $resources->relationshipOf($type, $relationship)->cardinality,
            ),
            $declared,
        );
    }

    public static function ofEndpoint(EndpointMetadata $endpoint): self
    {
        return new self(ParameterFamilies::processedByEndpoint($endpoint), $endpoint->queryParameters);
    }

    /**
     * an atomic route declares no parameter: its operations carry none
     */
    public static function ofAtomicRoute(): self
    {
        return new self(ParameterFamilies::processedBy(null, null), []);
    }

    /**
     * @return list<string> in declaration order
     */
    public function declaredNames(): array
    {
        return array_map(static function (QueryParameterMetadata $parameter): string {
            return $parameter->name;
        }, $this->declared);
    }

    /**
     * whether the base name is a family the route processes or a parameter it declares
     */
    public function reads(string $name): bool
    {
        $family = ParameterFamily::tryFrom($name);

        return ($family !== null && in_array($family, $this->families, true))
            || in_array($name, $this->declaredNames(), true);
    }
}
