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

namespace Polunich\WpJsonApi\Declaration;

use Closure;
use InvalidArgumentException;
use Override;
use Polunich\WpJsonApi\Metadata\Cardinality;
use Polunich\WpJsonApi\Metadata\RelationshipMetadata;

/**
 * a to-one relationship is empty as null and updated only by replace;
 * no route sorts or pages it, so unlike `ToMany` it declares no default sort and no pagination
 */
final class ToOne extends Relationship
{
    private ?Closure $relatedId = null;

    private bool $nullable = true;

    public static function make(string $name, string $relatedType): self
    {
        return new self($name, $relatedType);
    }

    /**
     * reads the id of the related resource from a record,
     * so every document renders the linkage straight from the record, without a load.
     * without an accessor the linkage is loaded through the related loader,
     * and rendered without `include` only after `linkageAlwaysPresent()`
     *
     * @template TRecord of object
     *
     * @param Closure(TRecord): ?string $relatedId returns null when the relationship is empty
     *
     * @throws InvalidArgumentException when a related id accessor is already declared
     */
    public function relatedId(Closure $relatedId): self
    {
        if ($this->relatedId !== null) {
            throw WrittenTwice::refusal($this->declaration(), 'a related id accessor');
        }

        $this->relatedId = $relatedId;

        return $this->linkageAlwaysPresent();
    }

    /**
     * refuses a null linkage. requires `required()` as well,
     * since a create could otherwise leave the relationship empty
     */
    public function notNullable(): self
    {
        $this->nullable = false;

        return $this;
    }

    /**
     * @internal
     */
    #[Override]
    public function build(): RelationshipMetadata
    {
        return $this->metadata(Cardinality::ToOne, $this->relatedId, $this->nullable, [], null, null);
    }
}
