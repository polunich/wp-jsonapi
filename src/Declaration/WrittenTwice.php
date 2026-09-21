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

/**
 * the refusal of a part of a declaration written a second time.
 * each part is written once, so no declaration overwrites another,
 * and a builder reused as a template fails as soon as both uses declare the same part
 *
 * @internal
 */
final readonly class WrittenTwice
{
    /**
     * @param string $declaration what declares the part, such as `type "pets"`,
     *                            escaped by the caller
     * @param string $part        such as `a description`
     */
    public static function refusal(string $declaration, string $part): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            '%s declares %s twice, and each part of a declaration is written once',
            $declaration,
            $part,
        ));
    }
}
