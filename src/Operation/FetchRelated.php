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

namespace Polunich\WpJsonApi\Operation;

use InvalidArgumentException;
use Override;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\Filter\FilterNode;
use Polunich\WpJsonApi\Query\Inclusion\IncludePaths;
use Polunich\WpJsonApi\Query\Pagination\PageRequest;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Text\Printable;

/**
 * a to-many relationship is read with the filter, the sort and the page of a collection;
 * a to-one relationship points at one resource, so its read carries none of them:
 * no filter, an empty `Sort` and `PageRequest::pageNumber()`
 */
final readonly class FetchRelated implements Operation
{
    public string $type;

    /**
     * @param Sort          $sort            empty when the request has no sort parameter
     * @param Values<mixed> $queryParameters
     *
     * @throws InvalidArgumentException when the relationship name is empty
     */
    public function __construct(
        public Identifier $parent,
        public string $relationship,
        public ?FilterNode $filter,
        public Sort $sort,
        public PageRequest $page,
        public ?IncludePaths $include,
        public SparseFieldsets $fields,
        public LanguagePreferences $languages,
        public Values $queryParameters,
    ) {
        if ($relationship === '') {
            throw new InvalidArgumentException(sprintf(
                'the related resources of a parent of type "%s" are read through a relationship, whose name is'
                . ' never empty',
                Printable::of($parent->type),
            ));
        }

        $this->type = $parent->type;
    }

    #[Override]
    public function kind(): OperationKind
    {
        return OperationKind::FetchRelated;
    }

    #[Override]
    public function type(): string
    {
        return $this->type;
    }

    #[Override]
    public function relationship(): string
    {
        return $this->relationship;
    }
}
