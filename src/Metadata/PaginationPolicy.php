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
use Polunich\WpJsonApi\Query\Pagination\PageRequest;
use Polunich\WpJsonApi\Query\Pagination\Strategy;

/**
 * @internal
 */
final readonly class PaginationPolicy
{
    private const LIBRARY_DEFAULT_SIZE = 10;

    private const LIBRARY_MAX_SIZE = 100;

    /**
     * @param int $defaultSize the page size of a request that names none
     * @param int $maxSize     the largest page size a request may name
     *
     * @throws InvalidArgumentException when the default size is below 1 or above the maximum,
     *                                  the bounds the cursor pagination profile sets
     */
    public function __construct(
        public int $defaultSize,
        public int $maxSize,
    ) {
        if ($defaultSize < 1) {
            throw new InvalidArgumentException(sprintf(
                'the default page size is %d, and it is at least 1 [profile-cursor]',
                $defaultSize,
            ));
        }

        if ($defaultSize > $maxSize) {
            throw new InvalidArgumentException(sprintf(
                'the default page size %d is above the maximum page size %d, and it is at most the maximum'
                . ' [profile-cursor]',
                $defaultSize,
                $maxSize,
            ));
        }
    }

    public static function libraryDefault(): self
    {
        return new self(self::LIBRARY_DEFAULT_SIZE, self::LIBRARY_MAX_SIZE);
    }

    /**
     * the size the client sent, else the default.
     * a cursor range, with both `page[after]` and `page[before]`, defaults to the maximum instead,
     * as the cursor pagination profile requires.
     * a size above the maximum is neither clamped nor refused here
     */
    public function usedSize(PageRequest $page): int
    {
        return match ($page->strategy) {
            Strategy::PageNumber => $page->size ?? $this->defaultSize,
            Strategy::Offset => $page->limit ?? $this->defaultSize,
            Strategy::Cursor => $page->size ?? (
                $page->after !== null && $page->before !== null ? $this->maxSize : $this->defaultSize
            ),
        };
    }
}
