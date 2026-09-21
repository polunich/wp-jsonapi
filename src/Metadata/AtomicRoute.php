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

use Polunich\WpJsonApi\Http\Method;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Routing\RouteKind;
use Polunich\WpJsonApi\Routing\RouteTarget;

/**
 * a route of the Atomic Operations extension, at the path its declaration writes.
 * it addresses no type, no relationship and no id:
 * each of its operations names its own in the document
 *
 * @internal
 */
final readonly class AtomicRoute extends GeneratedRoute
{
    public function __construct(public AtomicRouteMetadata $metadata)
    {
        // "All HTTP requests sent with this extension MUST be issued with POST"
        parent::__construct(RouteKind::Atomic, $metadata->template, [Method::POST]);
    }

    public function subject(): string
    {
        return sprintf('atomic route "%s"', $this->metadata->name);
    }

    public function target(ServerRequest $request): RouteTarget
    {
        return new RouteTarget(RouteKind::Atomic, null, null, null);
    }
}
