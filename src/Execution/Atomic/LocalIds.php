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

namespace Polunich\WpJsonApi\Execution\Atomic;

use Closure;
use InvalidArgumentException;
use LogicException;
use Polunich\WpJsonApi\Text\Printable;

/**
 * the ids that the creates of one atomic request gave to their lids.
 *
 * an id exists only once its create has run, so the executor records it here,
 * and `AtomicOperation::build()` reads it through `idLookup()` for each later operation:
 * no operation ever carries a lid.
 * a lid declared twice or used before its declaration is refused before the transaction begins,
 * so meeting one here is a library bug.
 *
 * a lid identifies a resource by type, so the map is keyed by type and then by lid,
 * and two types may share one.
 * a key of digits turns into an int, which is harmless, since no key ever leaves the map.
 *
 * mutable on purpose: it grows while the operations run, one instance per request
 *
 * @internal
 */
final class LocalIds
{
    /**
     * @var array<array-key, array<array-key, string>>
     */
    private array $ids = [];

    /**
     * @throws InvalidArgumentException when the type, the lid or the id is empty
     * @throws LogicException           when the pair already carries an id
     */
    public function assign(string $type, string $lid, string $id): void
    {
        if ($type === '') {
            throw new InvalidArgumentException(
                'a lid identifies a resource by type, and a type is never empty [spec]',
            );
        }

        if ($lid === '') {
            throw new InvalidArgumentException(sprintf(
                'a resource of type "%s" is recorded under an empty lid, which identifies no resource of the'
                . ' document [spec]',
                Printable::of($type),
            ));
        }

        if ($id === '') {
            throw new InvalidArgumentException(sprintf(
                'the resource of type "%s" and lid "%s" was created with an empty id, which identifies no'
                . ' resource [spec]',
                Printable::of($type),
                Printable::of($lid),
            ));
        }

        if ($this->has($type, $lid)) {
            throw new LogicException(sprintf(
                'the lid "%s" of type "%s" already names the resource with id "%s", and a lid is declared once'
                . ' in a document, which Parsing refuses with 400 duplicate_lid',
                Printable::of($lid),
                Printable::of($type),
                Printable::of($this->idOf($type, $lid)),
            ));
        }

        $this->ids[$type][$lid] = $id;
    }

    public function has(string $type, string $lid): bool
    {
        return isset($this->ids[$type][$lid]);
    }

    /**
     * @throws LogicException when no prior operation assigned the pair
     */
    public function idOf(string $type, string $lid): string
    {
        return $this->ids[$type][$lid] ?? throw new LogicException(sprintf(
            'no operation of this request gave the resource of type "%s" the lid "%s", and a lid is used only'
            . ' after the operation that declares it, which Parsing refuses with 400 unknown_lid',
            Printable::of($type),
            Printable::of($lid),
        ));
    }

    /**
     * the closure reads the map as it stands when called,
     * so each operation is built with every id recorded before it
     *
     * @return Closure(string $type, string $lid): string
     */
    public function idLookup(): Closure
    {
        return $this->idOf(...);
    }
}
