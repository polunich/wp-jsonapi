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
use Polunich\WpJsonApi\Query\Pagination\Cursor;
use Polunich\WpJsonApi\Query\Pagination\Strategy;

/**
 * one page of records, with what its pagination links and `meta.page` are written from.
 *
 * each member of a strategy is `null` in the others: `$number` belongs to page number pagination,
 * `$offset` to offset pagination, the cursors to cursor pagination
 *
 * @internal
 */
final readonly class Page
{
    /**
     * @param list<ResourceRecord> $records        without the probe record
     * @param int                  $size           the used page size: the size the request names,
     *                                             or the default where it names none
     * @param ?int                 $total          `null` when the store counted none
     * @param ?int                 $estimatedTotal `null` when the store estimated none
     * @param ?Cursor              $firstCursor    the cursor of the first record,
     *                                             or `page[after]` of the request on an empty page
     * @param ?Cursor              $lastCursor     the cursor of the last record,
     *                                             or `page[before]` of the request on an empty page
     *
     * @throws InvalidArgumentException when the size is below 1 or exceeded by the records,
     *                                  a count or the offset is negative, the number is below 1,
     *                                  or a member is given outside its strategy
     */
    public function __construct(
        public array $records,
        public Strategy $strategy,
        public int $size,
        public ?int $number,
        public ?int $offset,
        public bool $hasNext,
        public bool $hasPrev,
        public bool $rangeTruncated,
        public ?int $total,
        public ?int $estimatedTotal,
        public ?Cursor $firstCursor,
        public ?Cursor $lastCursor,
    ) {
        if ($size < 1) {
            throw new InvalidArgumentException(sprintf(
                'a page is written with the used page size %d, and a page size is at least 1 [profile-cursor]',
                $size,
            ));
        }

        if (count($records) > $size) {
            throw new InvalidArgumentException(sprintf(
                'a page of the used page size %d carries %d records, and the probe record is trimmed'
                . ' before the page is written',
                $size,
                count($records),
            ));
        }

        if ($total !== null && $total < 0) {
            throw new InvalidArgumentException(sprintf(
                'a page reports %d records in all, which is fewer than none',
                $total,
            ));
        }

        if ($estimatedTotal !== null && $estimatedTotal < 0) {
            throw new InvalidArgumentException(sprintf(
                'a page estimates %d records in all, which is fewer than none',
                $estimatedTotal,
            ));
        }

        if ($number !== null && $number < 1) {
            throw new InvalidArgumentException(sprintf(
                'a page is numbered %d, and the pages of a collection are counted from 1',
                $number,
            ));
        }

        if ($offset !== null && $offset < 0) {
            throw new InvalidArgumentException(sprintf(
                'a page stands at the offset %d, and the records before a page are counted from 0',
                $offset,
            ));
        }

        if ($number !== null && $strategy !== Strategy::PageNumber) {
            throw new InvalidArgumentException(sprintf(
                'a page of the %s strategy carries page[number] %d, and a page is numbered in the page'
                . ' number strategy alone',
                $strategy->value,
                $number,
            ));
        }

        if ($offset !== null && $strategy !== Strategy::Offset) {
            throw new InvalidArgumentException(sprintf(
                'a page of the %s strategy carries page[offset] %d, and a page stands at an offset in'
                . ' the offset strategy alone',
                $strategy->value,
                $offset,
            ));
        }

        foreach (['first' => $firstCursor, 'last' => $lastCursor] as $boundary => $cursor) {
            if ($cursor === null) {
                continue;
            }

            if ($strategy !== Strategy::Cursor) {
                throw new InvalidArgumentException(sprintf(
                    'a page of the %s strategy carries the cursor of its %s record, and a cursor is'
                    . ' written into the links of the cursor strategy alone [profile-cursor]',
                    $strategy->value,
                    $boundary,
                ));
            }
        }
    }

    /**
     * @throws InvalidArgumentException when the page is not a cursor page
     */
    public function withLastCursor(Cursor $cursor): self
    {
        return new self(
            $this->records,
            $this->strategy,
            $this->size,
            $this->number,
            $this->offset,
            $this->hasNext,
            $this->hasPrev,
            $this->rangeTruncated,
            $this->total,
            $this->estimatedTotal,
            $this->firstCursor,
            $cursor,
        );
    }
}
