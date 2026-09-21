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

use Override;
use Polunich\WpJsonApi\Http\EntityTag;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;

/**
 * the linkage of one relationship of one resource as primary data.
 *
 * the parent is a type and an id rather than a record, since no resource object is written for it.
 * `$relatedType` names the type an empty to-one linkage carries no identifier for
 *
 * @internal
 */
final readonly class RelationshipResult implements Result
{
    /**
     * @param ?EntityTag $entityTag the parent's tag
     */
    public function __construct(
        public string $parentType,
        public string $parentId,
        public string $relationship,
        public string $relatedType,
        public RecordLinkage $linkage,
        public RecordGraph $graph,
        public SparseFieldsets $fields,
        private ?EntityTag $entityTag,
    ) {}

    #[Override]
    public function status(): int
    {
        return 200;
    }

    #[Override]
    public function entityTag(): ?EntityTag
    {
        return $this->entityTag;
    }
}
