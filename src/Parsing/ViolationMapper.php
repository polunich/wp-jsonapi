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

use InvalidArgumentException;
use Polunich\WpJsonApi\Codec\DecodeException;
use Polunich\WpJsonApi\Codec\Violation;
use Polunich\WpJsonApi\Error\ErrorDescription;

/**
 * turns each violation of a `Codec\DecodeException` into an error description
 * with the same pointer, code and parameters.
 * the pointer is copied as is, so the caller starts the decode
 * at the absolute pointer of the value in the request document.
 *
 * the descriptions carry no status: they take the one of the exception the caller adds them with
 *
 * @internal
 */
final readonly class ViolationMapper
{
    /**
     * @return non-empty-list<ErrorDescription>
     *
     * @throws InvalidArgumentException when a pointer is malformed, which `Codec\Pointer` rules out
     */
    public static function describe(DecodeException $exception): array
    {
        return array_map(
            static function (Violation $violation): ErrorDescription {
                return new ErrorDescription(
                    $violation->code,
                    $violation->parameters,
                    $violation->pointer->toString(),
                );
            },
            $exception->violations(),
        );
    }
}
