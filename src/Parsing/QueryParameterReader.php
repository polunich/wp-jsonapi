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

namespace Polunich\WpJsonApi\Parsing;

use Polunich\WpJsonApi\Codec\DecodeException;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Error\BadRequestException;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\ErrorList;
use Polunich\WpJsonApi\Metadata\QueryParameterMetadata;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\QueryText;

/**
 * reads the query parameters an endpoint or a generated route declares:
 * a required one the request lacks is 400 `missing_query_parameter`,
 * and each value is read through `QueryText` and its codec, as a filter operand is,
 * every violation a 400 naming the parameter as the client wrote it, `priceRange[min]`.
 * a parameter the request does not carry is absent from the values
 *
 * @internal
 */
final readonly class QueryParameterReader
{
    private function __construct() {}

    /**
     * @param array<array-key, mixed>      $query    the query of the request
     * @param list<QueryParameterMetadata> $declared
     *
     * @return Values<mixed> by name, in declaration order
     */
    public static function read(array $query, array $declared, ErrorList $errors): Values
    {
        $values = [];

        foreach ($declared as $parameter) {
            if (!array_key_exists($parameter->name, $query)) {
                if ($parameter->required) {
                    $errors->add(new BadRequestException([new ErrorDescription(
                        ErrorCode::MissingQueryParameter,
                        ['name' => $parameter->name],
                        parameter: $parameter->name,
                    )]));
                }

                continue;
            }

            try {
                $values[$parameter->name] = $parameter->codec->decode(
                    QueryText::toWire($query[$parameter->name], $parameter->codec->schema()),
                    Pointer::root(),
                );
            } catch (DecodeException $refusal) {
                foreach ($refusal->violations() as $violation) {
                    $errors->add(new BadRequestException([new ErrorDescription(
                        $violation->code,
                        $violation->parameters,
                        parameter: $parameter->name . QueryText::brackets($violation->pointer),
                    )]));
                }
            }
        }

        return new Values($values);
    }
}
