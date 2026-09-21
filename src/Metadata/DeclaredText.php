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

/**
 * a description or a tag a declaration gives the contract is null or not empty:
 * null already says there is none, and an empty text would only blank what the contract shows
 *
 * @internal
 */
final readonly class DeclaredText
{
    private function __construct() {}

    /**
     * @param string $declaration names what declares the text, such as `type "pets"`,
     *                            escaped by the caller
     * @param string $part        such as `description`
     *
     * @throws InvalidArgumentException when the text is empty
     */
    public static function check(?string $text, string $declaration, string $part): void
    {
        if ($text !== '') {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            '%s declares an empty %s, and a declared text is never empty',
            $declaration,
            $part,
        ));
    }
}
