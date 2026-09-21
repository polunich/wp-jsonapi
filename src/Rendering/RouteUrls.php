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

namespace Polunich\WpJsonApi\Rendering;

use InvalidArgumentException;
use LogicException;
use Polunich\WpJsonApi\Metadata\EndpointMetadata;
use Polunich\WpJsonApi\Metadata\RouteTable;
use Polunich\WpJsonApi\Routing\RouteTarget;
use Polunich\WpJsonApi\Routing\UrlGenerator;

/**
 * the URL of a route: its template filled with the values of its parameters.
 * a link is built from the route it leads to, as a router generates the URL of a named route,
 * so it follows the path the route is registered at: the path a type declares,
 * or the endpoint that takes the place of a read
 *
 * @internal
 */
final readonly class RouteUrls
{
    public function __construct(
        private RouteTable $routes,
        private UrlGenerator $urls,
    ) {}

    /**
     * the URL of the route that responds to a GET of the target, filled with its id
     *
     * @param array<array-key, mixed> $query
     *
     * @throws LogicException when no route responds to a GET of the target,
     *                        which a caller checks with `RouteTable::fetches()` first
     */
    public function fetchUrl(RouteTarget $target, array $query): string
    {
        $type = $target->type ?? throw self::unfetched($target);
        $route = $this->routes->fetchRoute($target->kind, $type, $target->relationship)
            ?? throw self::unfetched($target);
        $parameters = $route->template->parameters();
        $values = [];

        // a route that addresses an id carries it as its one parameter;
        // an endpoint in place of a read lies on the same hierarchy, so it does too
        if (count($parameters) !== ($target->id === null ? 0 : 1)) {
            throw new LogicException(sprintf(
                'the route on "%s" holds %d parameters, and a route of the %s kind carries the id alone',
                $route->template->path,
                count($parameters),
                $target->kind->value,
            ));
        }

        foreach ($parameters as $parameter) {
            $values[$parameter->name] = (string) $target->id;
        }

        return $this->urls->url($route->template->fill($values), $query);
    }

    /**
     * the URL of the endpoint: its template filled with the path of the request
     *
     * @param array<string, string>   $path
     * @param array<array-key, mixed> $query
     *
     * @throws InvalidArgumentException when the path holds no value of a parameter of the template
     */
    public function endpointUrl(EndpointMetadata $endpoint, array $path, array $query): string
    {
        return $this->urls->url($endpoint->template->fill($path), $query);
    }

    private static function unfetched(RouteTarget $target): LogicException
    {
        return new LogicException(sprintf(
            'no route responds to a GET of the %s route of type "%s", so no link leads there',
            $target->kind->value,
            (string) $target->type,
        ));
    }
}
