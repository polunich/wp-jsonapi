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

/**
 * how a client pages through a collection, selected by the page parameters it sends.
 * the cursor strategy follows the JSON:API cursor pagination profile
 */
enum Strategy: string
{
    case PageNumber = 'number';

    case Offset = 'offset';

    case Cursor = 'cursor';

    /**
     * @return non-empty-list<PageParameter>
     */
    public function selectors(): array
    {
        return match ($this) {
            self::PageNumber => [PageParameter::Number],
            self::Offset => [PageParameter::Offset, PageParameter::Limit],
            self::Cursor => [PageParameter::After, PageParameter::Before],
        };
    }

    /**
     * the selectors, then the members shared with other strategies
     *
     * @return non-empty-list<PageParameter>
     */
    public function parameters(): array
    {
        return [...$this->selectors(), ...match ($this) {
            self::Offset => [PageParameter::WithCount],
            self::PageNumber, self::Cursor => [$this->sizeParameter(), PageParameter::WithCount],
        }];
    }

    public function sizeParameter(): PageParameter
    {
        return $this === self::Offset ? PageParameter::Limit : PageParameter::Size;
    }
}
