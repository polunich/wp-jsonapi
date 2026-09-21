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
use Polunich\WpJsonApi\Text\Printable;

/**
 * the name a project gives an operation of the contract, an endpoint or an atomic route:
 * the operationId, which OpenAPI recommends to "follow common programming naming conventions",
 * so a letter or `_`, then letters, digits and `_`.
 * every operationId the library writes itself holds a dot, so a declared name collides with none
 *
 * @internal
 */
final readonly class OperationName
{
    private const PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/D';

    /**
     * @param string $owner what carries the name, for the message, such as `an endpoint`
     *
     * @throws InvalidArgumentException when the name breaks the rule
     */
    public static function check(string $name, string $owner): void
    {
        if (preg_match(self::PATTERN, $name) === 1) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            '%s is named "%s", and its name is its operationId, which OpenAPI recommends to "follow common'
            . ' programming naming conventions": a letter or "_", then letters, digits and "_"',
            $owner,
            Printable::of($name),
        ));
    }
}
