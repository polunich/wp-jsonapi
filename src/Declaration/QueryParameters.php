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

namespace Polunich\WpJsonApi\Declaration;

use InvalidArgumentException;
use Polunich\WpJsonApi\Metadata\QueryParameterMetadata;
use Polunich\WpJsonApi\Operation\OperationKind;

/**
 * the query parameters a builder collects: those of an endpoint,
 * or those a type or a relationship declares for one kind of its routes.
 * a name is declared once in each, as every part of a declaration is written once
 *
 * @internal
 */
final readonly class QueryParameters
{
    private function __construct() {}

    /**
     * @param list<QueryParameterMetadata> $declared    the parameters declared so far
     * @param string                       $declaration what declares them, escaped by the caller
     * @param ?OperationKind               $kind        the kind of the routes that read them,
     *                                                  null for an endpoint
     *
     * @return list<QueryParameterMetadata>
     *
     * @throws InvalidArgumentException when the name is declared already
     */
    public static function added(
        array $declared,
        QueryParameterMetadata $parameter,
        string $declaration,
        ?OperationKind $kind = null,
    ): array {
        foreach ($declared as $earlier) {
            if ($earlier->name === $parameter->name) {
                throw WrittenTwice::refusal($declaration, sprintf(
                    'the query parameter "%s"%s',
                    $parameter->name,
                    $kind === null ? '' : ' for ' . $kind->value,
                ));
            }
        }

        return [...$declared, $parameter];
    }
}
