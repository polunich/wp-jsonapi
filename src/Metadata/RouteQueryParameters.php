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

use Closure;
use InvalidArgumentException;
use Polunich\WpJsonApi\Operation\OperationKind;

/**
 * the query parameters a type or a relationship declares per kind of its own routes.
 * one on a kind the declaration does not enable could never be read,
 * so it is refused as a declaration that never takes effect;
 * one a linked route requires is refused as well, see `LINKED`
 *
 * @internal
 */
final readonly class RouteQueryParameters
{
    /**
     * the kinds whose routes a document links to with the language parameter alone:
     * a resource object to the fetch of its resource,
     * a relationship object to its related and its relationship route.
     * a parameter one of them requires would fail every such link with 400,
     * and JSON:API has a server respond to the fetch of every self and related link it provides
     */
    private const LINKED = [
        OperationKind::FetchResource,
        OperationKind::FetchRelated,
        OperationKind::FetchRelationship,
    ];

    private function __construct() {}

    /**
     * `$kinds` are the kinds of the routes the declaration owns, `$declaration` names it,
     * such as `type "pets"`, and `$elsewhere` tells where a kind of another route is declared
     *
     * @param array<value-of<OperationKind>, list<QueryParameterMetadata>> $queryParameters
     * @param list<OperationKind>                                          $kinds
     * @param Closure(OperationKind): bool                                 $enables
     *
     * @throws InvalidArgumentException when a kind is none of the declaration's routes
     *                                  or is not enabled, a name repeats for one kind,
     *                                  or a linked route requires a parameter
     */
    public static function check(
        array $queryParameters,
        array $kinds,
        Closure $enables,
        string $declaration,
        string $elsewhere,
    ): void {
        foreach ($queryParameters as $key => $parameters) {
            $kind = OperationKind::from($key);

            OwnRouteKind::checkOwned($kind, $kinds, $declaration, 'a query parameter', $elsewhere);

            $seen = [];

            foreach ($parameters as $parameter) {
                OwnRouteKind::checkEnabled(
                    $kind,
                    $enables,
                    $declaration,
                    sprintf('the query parameter "%s"', $parameter->name),
                );
                self::checkLinked($kind, $parameter, $declaration);

                if (array_key_exists($parameter->name, $seen)) {
                    throw new InvalidArgumentException(sprintf(
                        '%s declares the query parameter "%s" for %s twice',
                        $declaration,
                        $parameter->name,
                        $kind->value,
                    ));
                }

                $seen[$parameter->name] = true;
            }
        }
    }

    /**
     * `$kind` is the kind of the route that reads the parameter,
     * or of the route an endpoint takes the place of
     *
     * @param string $declaration names what declares the parameter, such as `type "pets"`
     *
     * @throws InvalidArgumentException when a linked route requires the parameter
     */
    public static function checkLinked(
        OperationKind $kind,
        QueryParameterMetadata $parameter,
        string $declaration,
    ): void {
        if (!$parameter->required || !in_array($kind, self::LINKED, true)) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            '%s requires the query parameter "%s" for %s, and a document links to that route with no query'
            . ' parameter of its own, so every such link would get 400',
            $declaration,
            $parameter->name,
            $kind->value,
        ));
    }
}
