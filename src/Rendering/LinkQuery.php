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

use OutOfBoundsException;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Metadata\EndpointMetadata;
use Polunich\WpJsonApi\Metadata\LanguageSources;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Metadata\Route;
use Polunich\WpJsonApi\Metadata\RouteQuery;

/**
 * the query of a link: those parameters of the request that the route it leads to reads
 * (`Metadata\RouteQuery`) and the language parameters, as the client wrote them.
 * any other would break the link: the read a write links to responds with 400
 * to a parameter only the write reads, and WordPress applies a consumed one,
 * such as `_method`, to the GET of the link as well
 *
 * @internal
 */
final readonly class LinkQuery
{
    public function __construct(
        private ResourceRegistry $resources,
        private LanguageSources $languageSources,
    ) {}

    /**
     * @return array<array-key, mixed>
     *
     * @throws OutOfBoundsException when the type or the relationship of the route is not declared
     */
    public function to(Route $route, ServerRequest $request): array
    {
        return $this->kept(RouteQuery::ofRoute($route, $this->resources), $request);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function toEndpoint(EndpointMetadata $endpoint, ServerRequest $request): array
    {
        return $this->kept(RouteQuery::ofEndpoint($endpoint), $request);
    }

    /**
     * the query of a link that does not repeat the request, such as a resource object's self link:
     * the language parameter that decided, alone
     *
     * @return array<array-key, mixed>
     */
    public function language(ServerRequest $request): array
    {
        return $this->languageSources->linkQuery($request);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function kept(RouteQuery $route, ServerRequest $request): array
    {
        $languages = $this->languageSources->queryParameters();
        $kept = [];

        foreach ($request->query as $key => $value) {
            $name = (string) $key;

            if ($route->reads($name) || in_array($name, $languages, true)) {
                $kept[$key] = $value;
            }
        }

        return $kept;
    }
}
