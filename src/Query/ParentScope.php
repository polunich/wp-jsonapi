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

namespace Polunich\WpJsonApi\Query;

use InvalidArgumentException;
use Polunich\WpJsonApi\Document\MemberName;
use Polunich\WpJsonApi\Text\Printable;

/**
 * narrows a collection reading to the resources one relationship of one resource points at.
 * the parent is loaded, and its visibility settled, before the scoped read
 */
final readonly class ParentScope
{
    /**
     * @throws InvalidArgumentException when the type or the relationship is no member name,
     *                                  or the id is empty
     */
    public function __construct(
        public string $type,
        public string $id,
        public string $relationship,
    ) {
        if (!MemberName::isValid($type)) {
            throw new InvalidArgumentException(sprintf(
                'type "%s" is no member name, so no resource type carries it',
                Printable::of($type),
            ));
        }

        if ($id === '') {
            throw new InvalidArgumentException('a related collection hangs from one resource, whose id is never'
                . ' empty');
        }

        if (!MemberName::isValid($relationship)) {
            throw new InvalidArgumentException(sprintf(
                'relationship "%s" is no member name, so no relationship carries it',
                Printable::of($relationship),
            ));
        }
    }
}
