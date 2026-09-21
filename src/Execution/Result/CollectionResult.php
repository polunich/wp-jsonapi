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
 * resources as primary data: one page of a collection, or a whole list
 *
 * @internal
 */
final readonly class CollectionResult implements Result
{
    /**
     * @param string               $type    given apart, since the list may be empty
     * @param list<ResourceRecord> $records
     * @param ?Page                $page    `null` for a list without pages
     */
    private function __construct(
        public string $type,
        public array $records,
        public ?Page $page,
        public RecordGraph $graph,
        public SparseFieldsets $fields,
    ) {}

    public static function paged(string $type, Page $page, RecordGraph $graph, SparseFieldsets $fields): self
    {
        return new self($type, $page->records, $page, $graph, $fields);
    }

    /**
     * @param list<ResourceRecord> $records
     */
    public static function whole(string $type, array $records, RecordGraph $graph, SparseFieldsets $fields): self
    {
        return new self($type, $records, null, $graph, $fields);
    }

    #[Override]
    public function status(): int
    {
        return 200;
    }

    #[Override]
    public function entityTag(): ?EntityTag
    {
        return null;
    }
}
