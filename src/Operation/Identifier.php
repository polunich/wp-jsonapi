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

namespace Polunich\WpJsonApi\Operation;

use InvalidArgumentException;
use Polunich\WpJsonApi\Text\Printable;

/**
 * a resource by type and id, never by lid: a lid is replaced by its id while an operation is built.
 *
 * neither is ever empty: a member name, and so a type, has at least one character,
 * and an empty id would leave an empty segment in the path of the resource route
 */
final readonly class Identifier
{
    /**
     * @throws InvalidArgumentException when the type or the id is empty
     */
    public function __construct(
        public string $type,
        public string $id,
    ) {
        if ($type === '') {
            throw new InvalidArgumentException('an identifier names the type of its resource, and a type is never'
                . ' empty');
        }

        if ($id === '') {
            throw new InvalidArgumentException(sprintf(
                'an identifier of type "%s" carries an empty id, which identifies no resource',
                Printable::of($type),
            ));
        }
    }
}
