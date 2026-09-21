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
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Operation\OperationKind;

/**
 * the security schemes a type or a relationship declares for the kinds of its own routes,
 * each list in place of the schemes of the API on the operation of that route
 *
 * @internal
 */
final readonly class RouteSecuritySchemes
{
    private function __construct() {}

    /**
     * `$kinds` are the kinds of the routes the declaration owns, `$declaration` names it,
     * such as `type "pets"`, and `$elsewhere` tells where a kind of another route is declared
     *
     * @param array<value-of<OperationKind>, list<SecurityScheme>> $securitySchemes
     * @param list<OperationKind>                                  $kinds
     * @param Closure(OperationKind): bool                         $enables
     *
     * @throws InvalidArgumentException when a kind is none of the declaration's routes
     *                                  or is not enabled, or a list names a scheme twice
     */
    public static function check(
        array $securitySchemes,
        array $kinds,
        Closure $enables,
        string $declaration,
        string $elsewhere,
    ): void {
        foreach ($securitySchemes as $key => $schemes) {
            $kind = OperationKind::from($key);

            OwnRouteKind::checkOwned($kind, $kinds, $declaration, 'security schemes', $elsewhere);
            OwnRouteKind::checkEnabled($kind, $enables, $declaration, 'security schemes');
            SecuritySchemes::checkDistinct($schemes, sprintf('%s for %s', $declaration, $kind->value));
        }
    }
}
