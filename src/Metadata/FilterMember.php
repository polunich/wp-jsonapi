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
use LogicException;
use Polunich\WpJsonApi\Codec\Codec;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Text\Printable;

/**
 * a member of the filter object of a type, other than a logical operator:
 * the attribute or the id its conditions compare,
 * or the relationship whose related type reads a filter object of its own
 *
 * @internal
 */
final readonly class FilterMember
{
    /**
     * @param ResourceMetadata      $resource     the type whose filter object holds the member
     * @param ?AttributeMetadata    $attribute    null where the member is the id or a relationship
     * @param ?RelationshipMetadata $relationship null where the member is an attribute or the id
     *
     * @throws InvalidArgumentException when the member is an attribute and a relationship at once,
     *                                  neither where its name is not `id`,
     *                                  or a field its name does not name
     */
    public function __construct(
        public string $name,
        public ResourceMetadata $resource,
        public ?AttributeMetadata $attribute,
        public ?RelationshipMetadata $relationship,
    ) {
        $field = $attribute ?? $relationship;

        if (
            ($field === null && $name !== FieldPath::id()->name())
            || ($attribute !== null && $relationship !== null)
        ) {
            throw new InvalidArgumentException(sprintf(
                'filter member "%s" is resolved to %s, and a filter member is an attribute, a relationship or the'
                . ' id',
                Printable::of($name),
                $field === null ? 'neither an attribute nor a relationship' : 'both an attribute and a relationship',
            ));
        }

        if ($field !== null && $field->name !== $name) {
            throw new InvalidArgumentException(sprintf(
                'filter member "%s" is resolved to the field "%s", which it does not name',
                Printable::of($name),
                $field->name,
            ));
        }
    }

    /**
     * the codec a condition on the member reads its value with
     *
     * @return Codec<mixed>
     *
     * @throws LogicException where the member is a relationship, which takes a filter object
     */
    public function codec(): Codec
    {
        if ($this->relationship !== null) {
            throw new LogicException(sprintf(
                'filter member "%s" is a relationship, which takes a filter object and no condition',
                $this->name,
            ));
        }

        return $this->attribute !== null ? $this->attribute->codec : $this->resource->id->codec;
    }

    /**
     * the operators a condition on the member may apply
     *
     * @return list<string>
     */
    public function operators(): array
    {
        if ($this->relationship !== null) {
            return [];
        }

        return $this->attribute !== null ? $this->attribute->operators : $this->resource->id->operators;
    }
}
