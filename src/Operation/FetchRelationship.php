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
use Polunich\WpJsonApi\Text\Printable;

/**
 * the relationship route reads `include`, `fields[TYPE]` and, for a to-many relationship, `page`;
 * it has no sort and no filter parameter. a to-one read carries `PageRequest::pageNumber()`.
 *
 * `$filter` is never the client's:
 * it holds the conditions the cursors of `page[after]` and `page[before]` add,
 * so a page starts or ends at its cursor, as the cursor pagination profile requires.
 * the conditions arrive built, decoded against the sort of the read.
 *
 * an include path is written from the parent's type and starts with the relationship:
 * the primary data are its identifiers, and every included resource must be reached from them.
 * `$fields` shapes the included resources only, as an identifier holds no field
 */
final readonly class FetchRelationship implements Operation
{
    public string $type;

    /**
     * @param ?FilterNode   $filter          null exactly when the page carries no cursor
     * @param Values<mixed> $queryParameters
     *
     * @throws InvalidArgumentException when the relationship name is empty,
     *                                  the filter or a page cursor comes without the other,
     *                                  or an include path does not start with the relationship
     */
    public function __construct(
        public Identifier $parent,
        public string $relationship,
        public ?FilterNode $filter,
        public PageRequest $page,
        public ?IncludePaths $include,
        public SparseFieldsets $fields,
        public LanguagePreferences $languages,
        public Values $queryParameters,
    ) {
        if ($relationship === '') {
            throw new InvalidArgumentException(sprintf(
                'the linkage of a parent of type "%s" is read through a relationship, whose name is never empty',
                Printable::of($parent->type),
            ));
        }

        $cursors = $page->after !== null || $page->before !== null;

        if ($filter !== null && !$cursors) {
            throw new InvalidArgumentException(sprintf(
                'the filter of relationship "%s" of a parent of type "%s" carries the conditions of the cursors'
                . ' of its page, the one filter that route reads, and this page carries no cursor',
                Printable::of($relationship),
                Printable::of($parent->type),
            ));
        }

        if ($filter === null && $cursors) {
            throw new InvalidArgumentException(sprintf(
                'the page of relationship "%s" of a parent of type "%s" carries a cursor, whose conditions are'
                . ' the filter this linkage is read with, and this operation carries none',
                Printable::of($relationship),
                Printable::of($parent->type),
            ));
        }

        $include?->checkRootedAt($relationship, $parent->type);

        $this->type = $parent->type;
    }

    #[Override]
    public function kind(): OperationKind
    {
        return OperationKind::FetchRelationship;
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
