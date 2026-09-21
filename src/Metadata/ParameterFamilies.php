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
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseShapeKind;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Query\ParameterFamily;

/**
 * the query parameter families each route processes; any other family gets 400,
 * as the spec requires for a query parameter the server cannot process.
 *
 * the related route of a to-many relationship responds with a resource collection,
 * so it processes all five; its relationship route pages the linkage.
 * a write processes `include` and `fields[TYPE]`, which shape the document it responds with,
 * except a delete, which processes none.
 * an atomic route takes `fields[TYPE]` alone: an atomic document carries no `included`.
 * an endpoint takes `include` where a response carries resources,
 * and `fields[TYPE]` there and beside a monitor;
 * `filter`, `sort` and `page` only where it responds with a page:
 * the library reads them into the query its handler applies and pages the result,
 * while a handler of any other response would have to apply them itself.
 * the query parameters an endpoint declares are its own and pass beside these families
 *
 * @internal
 */
final readonly class ParameterFamilies
{
    /**
     * in the order of the `ParameterFamily` cases
     *
     * @param ?OperationKind $kind        null for an atomic route, which has no kind
     * @param ?Cardinality   $cardinality null where the route addresses no relationship
     *
     * @return list<ParameterFamily>
     *
     * @throws InvalidArgumentException when the cardinality is missing
     *                                  for a route that addresses a relationship,
     *                                  or given for one that addresses none
     */
    public static function processedBy(?OperationKind $kind, ?Cardinality $cardinality): array
    {
        if ($kind !== null && $kind->targetsRelationship()) {
            if ($cardinality === null) {
                throw new InvalidArgumentException(sprintf(
                    'the %s route addresses a relationship, whose cardinality decides the parameters it processes',
                    $kind->value,
                ));
            }
        } elseif ($cardinality !== null) {
            throw new InvalidArgumentException(sprintf(
                'the %s route addresses no relationship and reads no cardinality',
                $kind === null ? 'atomic' : $kind->value,
            ));
        }

        $document = [ParameterFamily::Include, ParameterFamily::Fields];

        return match ($kind) {
            null => [ParameterFamily::Fields],
            OperationKind::FetchCollection => ParameterFamily::cases(),
            OperationKind::FetchRelated => $cardinality === Cardinality::ToMany ? ParameterFamily::cases() : $document,
            OperationKind::FetchRelationship => $cardinality === Cardinality::ToMany
                ? [ParameterFamily::Page, ...$document]
                : $document,
            OperationKind::DeleteResource => [],
            OperationKind::FetchResource,
            OperationKind::CreateResource,
            OperationKind::UpdateResource,
            OperationKind::ReplaceRelationship,
            OperationKind::AddToRelationship,
            OperationKind::RemoveFromRelationship => $document,
        };
    }

    /**
     * in the order of the `ParameterFamily` cases
     *
     * @return list<ParameterFamily>
     */
    public static function processedByEndpoint(EndpointMetadata $endpoint): array
    {
        $include = false;
        $fields = false;

        foreach ($endpoint->responses as $response) {
            $include = $include || $response->kind->carriesResources();
            $fields = $fields || $response->kind->carriesResources() || $response->kind === ResponseShapeKind::Accepted;
        }

        $page = $endpoint->pageType() !== null;

        return array_values(array_filter([
            $page ? ParameterFamily::Filter : null,
            $page ? ParameterFamily::Sort : null,
            $page ? ParameterFamily::Page : null,
            $include ? ParameterFamily::Include : null,
            $fields ? ParameterFamily::Fields : null,
        ]));
    }
}
