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
use Polunich\WpJsonApi\Authentication\SecurityScheme;

/**
 * the security schemes an API, or one operation in place of the API's, declares:
 * each becomes one requirement of `security` and its name one key of `components.securitySchemes`,
 * so a list names a scheme once
 *
 * @internal
 */
final readonly class SecuritySchemes
{
    private function __construct() {}

    /**
     * @param list<SecurityScheme> $schemes
     * @param string               $declaration names what declares them, such as `type "pets"`
     *
     * @throws InvalidArgumentException when a name repeats
     */
    public static function checkDistinct(array $schemes, string $declaration): void
    {
        $seen = [];

        foreach ($schemes as $scheme) {
            if (array_key_exists($scheme->name, $seen)) {
                throw new InvalidArgumentException(sprintf(
                    '%s declares the security scheme "%s" twice',
                    $declaration,
                    $scheme->name,
                ));
            }

            $seen[$scheme->name] = true;
        }
    }
}
