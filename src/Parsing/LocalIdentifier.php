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
use Polunich\WpJsonApi\Document\Member;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Text\Printable;

/**
 * a resource identifier as a request document writes it:
 * a type with an `id`, or with the `lid` of a resource another operation of the document creates.
 *
 * `Operation\Identifier` has no `lid`:
 * the id behind one exists only once the creating operation has run,
 * so `toIdentifier()` builds the identifier at that point
 *
 * @internal
 */
final readonly class LocalIdentifier
{
    /**
     * @param string $idOrLid the id, or the `lid` when `$local`
     *
     * @throws InvalidArgumentException when the type, the id or the lid is empty
     */
    private function __construct(
        public string $type,
        private string $idOrLid,
        private bool $local,
    ) {
        if ($type === '') {
            throw new InvalidArgumentException('an identifier names the type of its resource, and a type is never'
                . ' empty');
        }

        if ($idOrLid === '') {
            throw new InvalidArgumentException(sprintf(
                'an identifier of type "%s" carries an empty %s, which identifies no resource',
                Printable::of($type),
                $local ? Member::LID : Member::ID,
            ));
        }
    }

    /**
     * @throws InvalidArgumentException when the type or the id is empty
     */
    public static function ofId(string $type, string $id): self
    {
        return new self($type, $id, false);
    }

    /**
     * @throws InvalidArgumentException when the type or the lid is empty
     */
    public static function ofLid(string $type, string $lid): self
    {
        return new self($type, $lid, true);
    }

    public function id(): ?string
    {
        return $this->local ? null : $this->idOrLid;
    }

    public function lid(): ?string
    {
        return $this->local ? $this->idOrLid : null;
    }

    /**
     * `$idOfLid` maps a `lid` to its id; a request that declares no `lid` passes none
     *
     * @param ?Closure(string $type, string $lid): string $idOfLid
     *
     * @throws LogicException           when a `lid` meets no `$idOfLid`
     * @throws InvalidArgumentException when `$idOfLid` returns an empty id
     */
    public function toIdentifier(?Closure $idOfLid = null): Identifier
    {
        if (!$this->local) {
            return new Identifier($this->type, $this->idOrLid);
        }

        if ($idOfLid === null) {
            throw new LogicException(sprintf(
                'identifier "%s" of type "%s" names a lid, and no id of a lid is'
                . ' given: a request that declares no lid is refused before an identifier is built',
                Printable::of($this->idOrLid),
                Printable::of($this->type),
            ));
        }

        return new Identifier($this->type, $idOfLid($this->type, $this->idOrLid));
    }
}
