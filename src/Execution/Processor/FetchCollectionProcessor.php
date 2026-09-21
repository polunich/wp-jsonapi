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

namespace Polunich\WpJsonApi\Execution\Processor;

use LogicException;
use OutOfBoundsException;
use Override;
use Polunich\WpJsonApi\Execution\ExecutionContext;
use Polunich\WpJsonApi\Execution\GraphRequest;
use Polunich\WpJsonApi\Execution\IncludeLoader;
use Polunich\WpJsonApi\Execution\Paginator;
use Polunich\WpJsonApi\Execution\Result\CollectionResult;
use Polunich\WpJsonApi\Execution\StoreGateway;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Operation\FetchCollection;
use Polunich\WpJsonApi\Operation\Operation;
use UnexpectedValueException;

/**
 * fetches one page of a collection
 *
 * @internal
 */
final readonly class FetchCollectionProcessor implements OperationProcessor
{
    public function __construct(
        private ResourceRegistry $resources,
        private StoreGateway $store,
        private Paginator $paginator,
        private IncludeLoader $includes,
    ) {}

    /**
     * @throws LogicException           when the operation is of another kind,
     *                                  the collection has no pagination policy,
     *                                  or the type binds no collection reader
     * @throws UnexpectedValueException when a store breaks its contract,
     *                                  or a boundary cursor cannot be written
     * @throws OutOfBoundsException     when a type or a relationship is not declared
     */
    #[Override]
    public function process(Operation $operation, ExecutionContext $context): CollectionResult
    {
        if (!$operation instanceof FetchCollection) {
            throw new LogicException(sprintf(
                'a fetch_collection processor processes a fetch_collection operation, and this one is %s',
                $operation->kind()->value,
            ));
        }

        $policy = $this->resources->paginationPolicy($operation) ?? throw new LogicException(sprintf(
            'the collection of type "%s" is read without a pagination policy, and every paginated'
            . ' reading declares one',
            $operation->type,
        ));
        $sort = $this->resources->readingSort($operation);

        $slice = $this->store->read($this->paginator->collectionQuery(
            $operation->type,
            $operation->filter,
            $sort,
            $operation->page,
            $policy,
            $this->includes->fieldHint($operation->type, $operation->fields, $sort),
            $operation->languages,
            null,
            $operation->queryParameters,
        ));
        $page = $this->paginator->page(
            $operation->page,
            $operation->languages,
            $slice,
            $policy,
            $operation->type,
            $sort,
        );

        return CollectionResult::paged(
            $operation->type,
            $page,
            $this->includes->load(GraphRequest::of($operation), $page->records, $context),
            $operation->fields,
        );
    }
}
