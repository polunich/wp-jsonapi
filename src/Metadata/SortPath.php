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
use Polunich\WpJsonApi\Codec\Codec;
use Polunich\WpJsonApi\Query\FieldPath;

/**
 * a sort path resolved against the declarations.
 * a cursor holds no null, so a `$nullable` path cannot key one
 *
 * @internal
 */
final readonly class SortPath
{
    /**
     * whether a record may carry no value for the path:
     * its attribute may be null, or a relationship on it may be empty
     */
    public bool $nullable;

    /**
     * @param list<RelationshipMetadata> $relationships in path order
     * @param ResourceMetadata           $resource      the type that holds the last segment
     * @param ?AttributeMetadata         $attribute     null where the path ends at an id
     *
     * @throws InvalidArgumentException when the relationships are not those the path runs through,
     *                                  or the attribute is given where the path ends at an id,
     *                                  or missing where it does not
     */
    public function __construct(
        public FieldPath $path,
        public array $relationships,
        public ResourceMetadata $resource,
        public ?AttributeMetadata $attribute,
    ) {
        $names = array_map(
            static function (RelationshipMetadata $relationship): string {
                return $relationship->name;
            },
            $relationships,
        );

        if ($names !== $path->relationships()) {
            throw new InvalidArgumentException(sprintf(
                'sort path "%s" is resolved through the relationships "%s", which are not the ones it runs through',
                $path->toString(),
                implode('", "', $names),
            ));
        }

        if (($attribute === null) !== $path->endsAtId()) {
            throw new InvalidArgumentException(sprintf(
                'sort path "%s" is resolved %s, and a sort path ends at a sortable attribute or at the id',
                $path->toString(),
                $attribute === null ? 'to no attribute' : sprintf('to attribute "%s"', $attribute->name),
            ));
        }

        $nullable = $attribute !== null && $attribute->nullable;

        foreach ($relationships as $relationship) {
            $nullable = $nullable || $relationship->nullable;
        }

        $this->nullable = $nullable;
    }

    /**
     * a cursor carries the value of the path in the wire form of this codec
     *
     * @return Codec<mixed>
     */
    public function codec(): Codec
    {
        return $this->attribute !== null ? $this->attribute->codec : $this->resource->id->codec;
    }
}
