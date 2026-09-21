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

use Closure;
use InvalidArgumentException;
use Polunich\WpJsonApi\Operation\OperationKind;

/**
 * a type or a relationship declares a part per kind of its own routes, such as query parameters:
 * a kind of another owner's routes is declared there,
 * and one the declaration does not enable is refused as a declaration that never takes effect
 *
 * @internal
 */
final readonly class OwnRouteKind
{
    private function __construct() {}

    /**
     * @param list<OperationKind> $kinds       the kinds of the routes the declaration owns
     * @param string              $declaration names what declares the part, such as `type "pets"`
     * @param string              $what        the part, such as `a query parameter`
     * @param string              $elsewhere   where a kind of another owner's routes is declared
     *
     * @throws InvalidArgumentException when the kind is none of the declaration's routes
     */
    public static function checkOwned(
        OperationKind $kind,
        array $kinds,
        string $declaration,
        string $what,
        string $elsewhere,
    ): void {
        if (in_array($kind, $kinds, true)) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            '%s declares %s for %s, which is no route of its own: %s',
            $declaration,
            $what,
            $kind->value,
            $elsewhere,
        ));
    }

    /**
     * @param Closure(OperationKind): bool $enables
     *
     * @throws InvalidArgumentException when the declaration does not enable the kind
     */
    public static function checkEnabled(OperationKind $kind, Closure $enables, string $declaration, string $what): void
    {
        if ($enables($kind)) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            '%s declares %s for %s, which it does not enable',
            $declaration,
            $what,
            $kind->value,
        ));
    }
}
