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
use Polunich\WpJsonApi\Document\MemberName;
use Polunich\WpJsonApi\Query\ParameterFamily;
use Polunich\WpJsonApi\Text\Printable;

/**
 * the name of a query parameter the API defines itself:
 * one an endpoint, a type or a relationship declares, or one that names the language of a request.
 *
 * JSON:API draws a custom name (Implementation-Specific Query Parameters)
 * "from a family whose base name is a legal member name
 * and also contains at least one non a-z character".
 * an API may waive the non a-z character, so `check()` leaves it to `isReservedByJsonApi()`
 * and refuses the families of the specification, which that character alone would keep out.
 * it also refuses the names `WordPressReservedParameters` lists
 *
 * @internal
 */
final readonly class CustomQueryParameterName
{
    /**
     * @throws InvalidArgumentException when the name is no legal member name,
     *                                  names a family of the specification,
     *                                  or is one WordPress reads
     */
    public static function check(string $name): void
    {
        if (!MemberName::isValid($name)) {
            throw new InvalidArgumentException(sprintf(
                'the query parameter "%s" is no legal member name, which JSON:API asks of the base name of a'
                . ' custom query parameter',
                Printable::of($name),
            ));
        }

        if (ParameterFamily::tryFrom($name) !== null) {
            throw new InvalidArgumentException(sprintf(
                'the query parameter "%s" is a family JSON:API defines itself',
                $name,
            ));
        }

        if (WordPressReservedParameters::isReserved($name)) {
            throw new InvalidArgumentException(sprintf(
                'the query parameter "%s" is one WordPress reads itself before the library reads the request',
                $name,
            ));
        }
    }

    /**
     * a name of a-z alone: "By forbidding the use of query parameters that contain only
     * the characters [a-z], JSON:API is reserving the ability to standardize additional
     * query parameters later without conflicting with existing implementations"
     */
    public static function isReservedByJsonApi(string $name): bool
    {
        return preg_match('/^[a-z]+$/D', $name) === 1;
    }
}
