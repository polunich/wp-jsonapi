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

namespace Polunich\WpJsonApi\Execution;

use Polunich\WpJsonApi\Metadata\RelationshipMetadata;

/**
 * one reading of the include walk: a relationship of one type,
 * for every record of a level it writes a data member for
 *
 * @internal
 */
final readonly class IncludeBatch
{
    /**
     * @param string                      $type    the type of the parents
     * @param non-empty-list<BatchParent> $parents
     */
    public function __construct(
        public RelationshipMetadata $relationship,
        public string $type,
        public array $parents,
    ) {}

    /**
     * whether an include path names the relationship at any parent:
     * the related records are then read as resource objects, else as identifiers only
     */
    public function named(): bool
    {
        foreach ($this->parents as $parent) {
            if ($parent->named) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function parentIds(): array
    {
        return array_map(
            static function (BatchParent $parent): string {
                return $parent->resource->id;
            },
            $this->parents,
        );
    }
}
