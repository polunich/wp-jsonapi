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

namespace Polunich\WpJsonApi\Endpoint;

/**
 * the port a project implements to respond to requests on one endpoint it declares.
 *
 * the library calls it once the request passed every step before it: the permission, the method,
 * negotiation, the path, the query, the bound records, the preconditions and the body.
 * the response is checked against what the endpoint declares, and the library writes the document,
 * so a handler never writes JSON:API itself.
 *
 * a failure is thrown: the library responds with a `Error\JsonApiException` as it is,
 * any other throwable passes the exception mappers of the API
 */
interface Handler
{
    public function handle(Request $request): Response;
}
