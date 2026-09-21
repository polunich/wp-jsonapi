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

use Closure;
use InvalidArgumentException;
use LogicException;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\Linkage;

/**
 * resource linkage of a request document,
 * before its `lid`s are replaced by their ids in `Operation\Linkage`.
 * it keeps the cardinality, since `null` and `[]` are different empty linkages,
 * and a to-many linkage keeps its identifiers in request order
 *
 * @internal
 */
final readonly class LocalLinkage
{
    /**
     * @param list<LocalIdentifier> $identifiers at most one for a to-one linkage
     */
    private function __construct(
        private bool $toMany,
        private array $identifiers,
    ) {}

    public static function toOne(?LocalIdentifier $identifier): self
    {
        return new self(false, $identifier === null ? [] : [$identifier]);
    }

    /**
     * @param list<LocalIdentifier> $identifiers
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
     * @return list<LocalIdentifier>
     */
    public function identifiers(): array
    {
        return $this->identifiers;
    }

    /**
     * `$idOfLid` as `LocalIdentifier::toIdentifier()` takes it
     *
     * @param ?Closure(string $type, string $lid): string $idOfLid
     *
     * @throws LogicException           when a `lid` meets no `$idOfLid`
     * @throws InvalidArgumentException when `$idOfLid` returns an empty id
     */
    public function toLinkage(?Closure $idOfLid = null): Linkage
    {
        $identifiers = array_map(
            static function (LocalIdentifier $identifier) use ($idOfLid): Identifier {
                return $identifier->toIdentifier($idOfLid);
            },
            $this->identifiers,
        );

        return $this->toMany ? Linkage::toMany($identifiers) : Linkage::toOne($identifiers[0] ?? null);
    }
}
