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

namespace Polunich\WpJsonApi\Execution\Result;

use InvalidArgumentException;
use Polunich\WpJsonApi\Text\Printable;

/**
 * what a document carries beside its primary data:
 * the included resources and the data member of each relationship object.
 *
 * the linkage is a side table, not a member of `ResourceRecord`:
 * a compound document may hold a cycle (a pet whose owner's pets include the pet),
 * which readonly records holding their own linkage could not be built for.
 *
 * a type, an id and a relationship name may consist of digits only,
 * which PHP turns into int keys, so the maps are keyed by `array-key`
 *
 * @internal
 */
final readonly class RecordGraph
{
    /**
     * @param bool                 $compound `true` whenever the request supplied `include`,
     *                                       even when nothing was reached:
     *                                       the spec then requires an empty `included`
     * @param list<ResourceRecord> $included one entry per type and id pair, in load order
     * @param array<array-key, array<array-key, array<array-key, RecordLinkage>>> $linkage
     *                                       type => id => relationship name => data member;
     *                                       a relationship left out writes no data member
     *
     * @throws InvalidArgumentException when `$included` holds a type and id pair twice,
     *                                  or an entry of it carries no record
     */
    public function __construct(
        private bool $compound,
        private array $included = [],
        private array $linkage = [],
    ) {
        $seen = [];

        foreach ($included as $resource) {
            if ($resource->record === null) {
                throw new InvalidArgumentException(sprintf(
                    'the included resource of type "%s" and id "%s" carries no record, and an included'
                    . ' resource is written as a resource object, which is read off the record',
                    Printable::of($resource->type),
                    Printable::of($resource->id),
                ));
            }

            if (isset($seen[$resource->type][$resource->id])) {
                throw new InvalidArgumentException(sprintf(
                    'the included resources hold type "%s" and id "%s" twice, and "A compound document'
                    . ' MUST NOT include more than one resource object for each type and id pair."'
                    . ' [spec, Compound Documents]',
                    Printable::of($resource->type),
                    Printable::of($resource->id),
                ));
            }

            $seen[$resource->type][$resource->id] = true;
        }
    }

    public function isCompound(): bool
    {
        return $this->compound;
    }

    /**
     * @return list<ResourceRecord>
     */
    public function included(): array
    {
        return $this->included;
    }

    /** `null` when the relationship object writes no data member, which is not the empty linkage */
    public function linkage(string $type, string $id, string $relationship): ?RecordLinkage
    {
        return $this->linkage[$type][$id][$relationship] ?? null;
    }
}
