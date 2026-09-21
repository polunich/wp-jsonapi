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

namespace Polunich\WpJsonApi\Query\Pagination;

use InvalidArgumentException;

/**
 * the page parameters of one request.
 * each named constructor is one strategy and carries that strategy's parameters only.
 * a parameter the client did not send stays null; a missing size is resolved per reading, not here
 */
final readonly class PageRequest
{
    /**
     * `PageParameter::WithCount`; without it, true for every strategy but the cursor:
     * counting the whole set costs what a cursor page is read to avoid
     */
    public bool $totalRequested;

    private function __construct(
        public Strategy $strategy,
        public ?int $number,
        public ?int $size,
        public ?int $offset,
        public ?int $limit,
        public ?Cursor $after,
        public ?Cursor $before,
        ?bool $totalRequested,
    ) {
        $this->totalRequested = $totalRequested ?? $strategy !== Strategy::Cursor;
    }

    /**
     * @param ?int $number counted from 1
     *
     * @throws InvalidArgumentException when the page is below 1 or the size is below 1
     */
    public static function pageNumber(?int $number = null, ?int $size = null, ?bool $totalRequested = null): self
    {
        self::atLeast('page[number]', $number, 1);
        self::atLeast('page[size]', $size, 1);

        return new self(Strategy::PageNumber, $number, $size, null, null, null, null, $totalRequested);
    }

    /**
     * @param ?int $offset the records skipped before the page
     *
     * @throws InvalidArgumentException when the offset is negative or the limit is below 1
     */
    public static function offset(?int $offset = null, ?int $limit = null, ?bool $totalRequested = null): self
    {
        self::atLeast('page[offset]', $offset, 0);
        self::atLeast('page[limit]', $limit, 1);

        return new self(Strategy::Offset, null, null, $offset, $limit, null, null, $totalRequested);
    }

    /**
     * both boundaries together ask for a range, as the cursor pagination profile allows
     *
     * @param ?Cursor $after  the page starts after its record
     * @param ?Cursor $before the page ends before its record
     *
     * @throws InvalidArgumentException when the size is below 1
     */
    public static function cursor(
        ?Cursor $after = null,
        ?Cursor $before = null,
        ?int $size = null,
        ?bool $totalRequested = null,
    ): self {
        self::atLeast('page[size]', $size, 1);

        return new self(Strategy::Cursor, null, $size, null, null, $after, $before, $totalRequested);
    }

    /**
     * @throws InvalidArgumentException when the value is below the bound
     */
    private static function atLeast(string $parameter, ?int $value, int $bound): void
    {
        if ($value !== null && $value < $bound) {
            throw new InvalidArgumentException(sprintf(
                '%s is %d, and the smallest page it can name is %d',
                $parameter,
                $value,
                $bound,
            ));
        }
    }
}
