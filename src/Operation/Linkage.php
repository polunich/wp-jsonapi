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

use LogicException;

/**
 * the resource linkage of one relationship, as a request carries it.
 *
 * the cardinality is part of the value, as the spec gives each case its own JSON form:
 * `toOne(null)` and `toMany([])` differ, and a to-many linkage of one identifier is no to-one one.
 *
 * a to-many linkage keeps the order and the repetitions of the request;
 * the spec gives them no meaning, and only the stored relationship knows what is already related
 */
final readonly class Linkage
{
    /**
     * @param list<Identifier> $identifiers at most one for a to-one linkage
     */
    private function __construct(
        private bool $toMany,
        private array $identifiers,
    ) {}

    public static function toOne(?Identifier $identifier): self
    {
        return new self(false, $identifier === null ? [] : [$identifier]);
    }

    /**
     * @param list<Identifier> $identifiers
     */
    public static function toMany(array $identifiers): self
    {
        return new self(true, $identifiers);
    }

    public function isToMany(): bool
    {
        return $this->toMany;
    }

    /**
     * @throws LogicException when the linkage is to-many:
     *                        null would read as an empty to-one linkage
     */
    public function identifier(): ?Identifier
    {
        if ($this->toMany) {
            throw new LogicException('a to-many linkage names a list of identifiers; read it with identifiers()');
        }

        return $this->identifiers[0] ?? null;
    }

    /**
     * for either cardinality
     *
     * @return list<Identifier>
     */
    public function identifiers(): array
    {
        return $this->identifiers;
    }
}
