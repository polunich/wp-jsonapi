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

/**
 * the read of a collection as the client asked for it:
 * the page stays unresolved, as the pagination policy of a resource is out of this module's reach
 */
final readonly class FetchCollection implements Operation
{
    /**
     * @param Sort          $sort            empty when the request has no sort parameter
     * @param Values<mixed> $queryParameters
     *
     * @throws InvalidArgumentException when the type is empty
     */
    public function __construct(
        public string $type,
        public ?FilterNode $filter,
        public Sort $sort,
        public PageRequest $page,
        public ?IncludePaths $include,
        public SparseFieldsets $fields,
        public LanguagePreferences $languages,
        public Values $queryParameters,
    ) {
        if ($type === '') {
            throw new InvalidArgumentException('a collection is read for a resource type, and a type is never empty');
        }
    }

    #[Override]
    public function kind(): OperationKind
    {
        return OperationKind::FetchCollection;
    }

    #[Override]
    public function type(): string
    {
        return $this->type;
    }

    #[Override]
    public function relationship(): ?string
    {
        return null;
    }
}
