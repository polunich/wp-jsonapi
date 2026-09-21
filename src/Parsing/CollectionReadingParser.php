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

namespace Polunich\WpJsonApi\Parsing;

use OutOfBoundsException;
use Polunich\WpJsonApi\Error\ErrorList;
use Polunich\WpJsonApi\Metadata\PaginationPolicy;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Query\CollectionReading;
use Polunich\WpJsonApi\Query\Filter\AllOf;
use Polunich\WpJsonApi\Query\Filter\FilterNode;
use Polunich\WpJsonApi\Query\Pagination\PageRequest;
use Polunich\WpJsonApi\Query\Pagination\Strategy;
use Polunich\WpJsonApi\Query\ParameterFamily;
use Polunich\WpJsonApi\Query\Sort\Sort;

/**
 * reads `filter`, `sort` and `page` of a request whose primary data is paged.
 * run it after every other parameter of the request:
 * `conditions()` reads a cursor only while the error list holds no mistake
 *
 * @internal
 */
final readonly class CollectionReadingParser
{
    public function __construct(
        private FilterParser $filters,
        private SortParser $sorts,
        private PageParser $pages,
        private CursorConditions $cursorConditions,
    ) {}

    /**
     * @param array<array-key, mixed> $query       the query of the request, as the client wrote it
     * @param string                  $type        the type of the records read
     * @param ?Sort                   $defaultSort the declared order without `sort`, null for none
     * @param Strategy                $default     where the page parameters select no strategy
     *
     * @throws OutOfBoundsException when the type, or a type a path runs through, is not declared
     */
    public function parse(
        array $query,
        string $type,
        PaginationPolicy $policy,
        ?Sort $defaultSort,
        Strategy $default,
        ErrorList $errors,
    ): CollectionReading {
        $page = $this->pages->parse(ParameterFamily::Page->valueIn($query), $policy, $errors, $default);
        $sort = $this->sorts->parse(ParameterFamily::Sort->valueIn($query), $type, $page->strategy, $errors);
        $filter = $this->filters->parse(ParameterFamily::Filter->valueIn($query), $type, $errors);
        $readingSort = ResourceRegistry::readingSortOf($sort, $defaultSort);
        $conditions = $this->conditions($page, $type, $readingSort, $errors);

        return new CollectionReading(
            $type,
            $conditions === null ? $filter : self::both($filter, $conditions),
            $sort,
            $readingSort,
            $page,
        );
    }

    /**
     * the conditions the cursors of the page add to the filter;
     * null without a cursor, when every cursor is refused, or once the error list holds a mistake.
     * a mistake read earlier may have narrowed the sort, since a refused sort field is left out,
     * and a cursor checked against that sort would be refused for no mistake of the client;
     * the response is 400 either way
     *
     * @param string $type        the type of the records the cursors name
     * @param Sort   $readingSort the order the reading runs in, `id` included
     */
    public function conditions(PageRequest $page, string $type, Sort $readingSort, ErrorList $errors): ?FilterNode
    {
        if (($page->after === null && $page->before === null) || $errors->exception() !== null) {
            return null;
        }

        return $this->cursorConditions->of($page, $type, $readingSort, $errors);
    }

    private static function both(?FilterNode $filter, FilterNode $conditions): FilterNode
    {
        return $filter === null ? $conditions : new AllOf([$filter, $conditions]);
    }
}
