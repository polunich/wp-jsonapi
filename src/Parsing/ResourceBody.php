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
use Polunich\WpJsonApi\Operation\Linkage;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Text\Printable;

/**
 * a checked and decoded resource object of a create or an update, before it becomes an operation:
 * the operation kind and the query parts come from the rest of the request.
 *
 * `id` is the client-generated id of a create or the target id of an update;
 * `lid` stands in its place for a resource named by its lid, so at most one is set.
 * the relationships keep `LocalLinkage`,
 * since an identifier may name a resource an earlier operation of the same document creates
 *
 * @internal
 */
final readonly class ResourceBody
{
    /**
     * @param Values<mixed>        $attributes
     * @param Values<LocalLinkage> $relationships
     *
     * @throws InvalidArgumentException when the type, the id or the lid is empty,
     *                                  or when both an id and a lid are given
     */
    public function __construct(
        public string $type,
        public ?string $id,
        public ?string $lid,
        public Values $attributes,
        public Values $relationships,
    ) {
        if ($type === '') {
            throw new InvalidArgumentException('a resource object names its type, and a type is never empty');
        }

        if ($id === '' || $lid === '') {
            throw new InvalidArgumentException(sprintf(
                'a resource object of type "%s" carries an empty %s, which identifies no resource',
                Printable::of($type),
                $id === '' ? Member::ID : Member::LID,
            ));
        }

        if ($id !== null && $lid !== null) {
            throw new InvalidArgumentException(sprintf(
                'a resource object of type "%s" carries an id and a lid, and a lid stands where an id'
                . ' is omitted',
                Printable::of($type),
            ));
        }
    }

    /**
     * `$idOfLid` as `LocalIdentifier::toIdentifier()` takes it
     *
     * @param ?Closure(string $type, string $lid): string $idOfLid
     *
     * @return Values<Linkage>
     *
     * @throws LogicException           when a `lid` meets no `$idOfLid`
     * @throws InvalidArgumentException when `$idOfLid` returns an empty id
     */
    public function relationshipsWithIds(?Closure $idOfLid = null): Values
    {
        $linkages = [];

        foreach ($this->relationships->names() as $name) {
            $linkages[$name] = $this->relationships->get($name)->toLinkage($idOfLid);
        }

        return new Values($linkages);
    }
}
