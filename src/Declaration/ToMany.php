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

use InvalidArgumentException;
use Override;
use Polunich\WpJsonApi\Metadata\Cardinality;
use Polunich\WpJsonApi\Metadata\PaginationPolicy;
use Polunich\WpJsonApi\Metadata\RelationshipMetadata;
use Polunich\WpJsonApi\Metadata\RelationshipUpdate;
use Polunich\WpJsonApi\Query\Sort\Sort;

/**
 * a to-many relationship is empty as `[]`, never null, and has no related id accessor.
 * it accepts replace, add and remove.
 * its related route, its relationship route and its rendered linkage are sorted and paged,
 * so it may declare a default sort and a pagination policy of its own.
 * the related type must bind a collection reader, and the default sort paths are checked against it
 */
final class ToMany extends Relationship
{
    private bool $addable = false;

    private bool $removable = false;

    private ?Sort $defaultSort = null;

    private ?PaginationPolicy $paginationPolicy = null;

    public static function make(string $name, string $relatedType): self
    {
        return new self($name, $relatedType);
    }

    /**
     * accepts add: `POST` on the relationship route. requires `ResourceType::relationshipAdder()`
     */
    public function addable(): self
    {
        $this->addable = true;

        return $this;
    }

    /**
     * accepts remove: `DELETE` on the relationship route.
     * requires `ResourceType::relationshipRemover()`
     */
    public function removable(): self
    {
        $this->removable = true;

        return $this;
    }

    /**
     * the order of the relationship's readings when a request sends no `sort`,
     * in the sort syntax of the specification, such as `-placedAt`.
     * the paths are those of the related type. without one the readings are sorted by id,
     * and `id` is appended to any sort that does not order by it
     *
     * @throws InvalidArgumentException when the relationship already declares a default sort,
     *                                  or the text is no sort
     */
    public function defaultSort(string $sort): self
    {
        if ($this->defaultSort !== null) {
            throw WrittenTwice::refusal($this->declaration(), 'a default sort');
        }

        $this->defaultSort = Sort::fromString($sort);

        return $this;
    }

    /**
     * the page sizes of the relationship's readings;
     * the default size also caps the identifiers a document renders as linkage.
     * without this call the related type's sizes apply
     *
     * @param int $defaultSize used when a request names no size; at least 1
     * @param int $maxSize     a request with a larger size gets 400 with the maximum in `meta.page`
     *
     * @throws InvalidArgumentException when the relationship already declares a policy,
     *                                  or the default size is below 1 or above the maximum
     */
    public function pagination(int $defaultSize, int $maxSize): self
    {
        if ($this->paginationPolicy !== null) {
            throw WrittenTwice::refusal($this->declaration(), 'a pagination policy');
        }

        $this->paginationPolicy = new PaginationPolicy($defaultSize, $maxSize);

        return $this;
    }

    /**
     * the updates come out as replace, add, remove, whatever order they were declared in,
     * so equal declarations build equal relationships
     *
     * @internal
     */
    #[Override]
    public function build(): RelationshipMetadata
    {
        $updates = [];

        if ($this->addable) {
            $updates[] = RelationshipUpdate::Add;
        }

        if ($this->removable) {
            $updates[] = RelationshipUpdate::Remove;
        }

        return $this->metadata(Cardinality::ToMany, null, false, $updates, $this->defaultSort, $this->paginationPolicy);
    }
}
