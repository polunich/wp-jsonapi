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

use LogicException;

/**
 * the linkage of one relationship, as the data member of a relationship object or as primary data.
 *
 * a to-many linkage is one page, or a whole list, which has no pages.
 * the cardinality is part of the value:
 * `toOne(null)` and `toMany()` of an empty page are different linkages (`null` against `[]`),
 * and a to-many linkage of one record is no to-one linkage.
 * each accessor refuses the other cardinality, so no reader can read `null` for both
 *
 * @internal
 */
final readonly class RecordLinkage
{
    /**
     * @param list<ResourceRecord> $records the members of a to-many linkage
     */
    private function __construct(
        private bool $toMany,
        private ?ResourceRecord $record,
        private ?Page $page,
        private array $records,
    ) {}

    public static function toOne(?ResourceRecord $record): self
    {
        return new self(false, $record, null, []);
    }

    public static function toMany(Page $page): self
    {
        return new self(true, null, $page, $page->records);
    }

    /**
     * @param list<ResourceRecord> $records
     */
    public static function toManyWhole(array $records): self
    {
        return new self(true, null, null, $records);
    }

    public function isToMany(): bool
    {
        return $this->toMany;
    }

    /**
     * @throws LogicException when the linkage is to-many
     */
    public function record(): ?ResourceRecord
    {
        if ($this->toMany) {
            throw new LogicException('a to-many linkage names a list of records; read it with records()');
        }

        return $this->record;
    }

    /**
     * @throws LogicException when the linkage is to-one, or a whole list without pages
     */
    public function page(): Page
    {
        return $this->page ?? throw new LogicException(
            $this->toMany
                ? 'this to-many linkage is a whole list without pages; read it with records()'
                : 'a to-one linkage names one record or none; read it with record()',
        );
    }

    /**
     * the members of a to-many linkage, of its page or of its whole list
     *
     * @return list<ResourceRecord>
     *
     * @throws LogicException when the linkage is to-one
     */
    public function records(): array
    {
        if (!$this->toMany) {
            throw new LogicException('a to-one linkage names one record or none; read it with record()');
        }

        return $this->records;
    }

    public function isPaged(): bool
    {
        return $this->page !== null;
    }
}
