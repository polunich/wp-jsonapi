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
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\NotFoundException;
use Polunich\WpJsonApi\Execution\ExecutionContext;
use Polunich\WpJsonApi\Execution\GraphRequest;
use Polunich\WpJsonApi\Execution\IncludeLoader;
use Polunich\WpJsonApi\Execution\Paginator;
use Polunich\WpJsonApi\Execution\Result\CollectionResult;
use Polunich\WpJsonApi\Execution\Result\ResourceRecord;
use Polunich\WpJsonApi\Execution\Result\ResourceResult;
use Polunich\WpJsonApi\Execution\StoreGateway;
use Polunich\WpJsonApi\Metadata\Cardinality;
use Polunich\WpJsonApi\Metadata\RelationshipMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Operation\FetchRelated;
use Polunich\WpJsonApi\Operation\Operation;
use Polunich\WpJsonApi\Query\Fieldset\FieldHint;
use Polunich\WpJsonApi\Query\ParentScope;
use Polunich\WpJsonApi\Store\LoadRequest;
use Polunich\WpJsonApi\Store\RelatedLoadRequest;
use UnexpectedValueException;

/**
 * fetches what one relationship of a parent points at.
 *
 * a to-many relationship responds with a page like the collection route, scoped to the parent;
 * a to-one relationship responds with one resource or `null`.
 * either way the primary data are resource objects of the related type,
 * and the include paths start from that type.
 *
 * a related id read off the parent that the loader returns no record for
 * is a record missing or hidden from the client, so it is a 404:
 * an empty relationship would contradict the parent.
 * without a related id accessor, an empty relationship and a hidden record look alike,
 * so both get 200 with `data: null`
 *
 * @internal
 */
final readonly class FetchRelatedProcessor implements OperationProcessor
{
    public function __construct(
        private ResourceRegistry $resources,
        private StoreGateway $store,
        private Paginator $paginator,
        private IncludeLoader $includes,
    ) {}

    /**
     * @throws NotFoundException        404 for a to-one related record read by id
     *                                  that the loader does not return
     * @throws LogicException           when the operation is of another kind, no parent was loaded,
     *                                  the reading has no pagination policy,
     *                                  or a capability is not bound
     * @throws UnexpectedValueException when a store breaks its contract,
     *                                  an accessor returns what it may not,
     *                                  or a boundary cursor cannot be written
     * @throws OutOfBoundsException     when a type or a relationship is not declared
     */
    #[Override]
    public function process(Operation $operation, ExecutionContext $context): CollectionResult|ResourceResult
    {
        if (!$operation instanceof FetchRelated) {
            throw new LogicException(sprintf(
                'a fetch_related processor processes a fetch_related operation, and this one is %s',
                $operation->kind()->value,
            ));
        }

        $relationship = $this->resources->relationshipOf($operation->parent->type, $operation->relationship);

        return $relationship->cardinality === Cardinality::ToMany
            ? $this->collection($operation, $relationship, $context)
            : $this->resource($operation, $relationship, $context);
    }

    /**
     * @throws LogicException           when the reading has no pagination policy,
     *                                  or the related type binds no collection reader
     * @throws UnexpectedValueException when the reader breaks its contract,
     *                                  or a boundary cursor cannot be written
     * @throws OutOfBoundsException     when a type or a relationship is not declared
     */
    private function collection(
        FetchRelated $operation,
        RelationshipMetadata $relationship,
        ExecutionContext $context,
    ): CollectionResult {
        $relatedType = $relationship->relatedType;
        $policy = $this->resources->paginationPolicy($operation) ?? throw new LogicException(sprintf(
            'relationship "%s" of type "%s" is read without a pagination policy, and every paginated'
            . ' reading declares one',
            $relationship->name,
            $operation->parent->type,
        ));
        $sort = $this->resources->readingSort($operation);

        $slice = $this->store->read($this->paginator->collectionQuery(
            $relatedType,
            $operation->filter,
            $sort,
            $operation->page,
            $policy,
            $this->includes->fieldHint($relatedType, $operation->fields, $sort),
            $operation->languages,
            new ParentScope($operation->parent->type, $operation->parent->id, $operation->relationship),
            $operation->queryParameters,
        ));
        $page = $this->paginator->page($operation->page, $operation->languages, $slice, $policy, $relatedType, $sort);

        return CollectionResult::paged(
            $relatedType,
            $page,
            $this->includes->load(GraphRequest::of($operation), $page->records, $context),
            $operation->fields,
        );
    }

    /**
     * @throws NotFoundException        404 for a record the loader does not return
     * @throws LogicException           when no parent was loaded,
     *                                  or a capability the reading needs is not bound
     * @throws UnexpectedValueException when a store breaks its contract,
     *                                  or an accessor returns what it may not
     * @throws OutOfBoundsException     when a type or a relationship is not declared
     */
    private function resource(
        FetchRelated $operation,
        RelationshipMetadata $relationship,
        ExecutionContext $context,
    ): ResourceResult {
        $relatedType = $relationship->relatedType;
        $hint = $this->includes->fieldHint($relatedType, $operation->fields, null);
        $record = $relationship->readsRelatedId()
            ? $this->byIdentifier($operation, $relationship, $hint, $context)
            : $this->store->loadToOne(RelatedLoadRequest::toOne(
                $operation->parent->type,
                [$operation->parent->id],
                $operation->relationship,
                $hint,
                $operation->languages,
                $operation->queryParameters,
            ))[0];

        // walked even when empty, so a request with `include` still gets an empty `included`
        $resource = $record === null ? null : ResourceRecord::of($this->resources->get($relatedType), $record);

        return new ResourceResult(
            $relatedType,
            $resource,
            $this->includes->load(GraphRequest::of($operation), $resource === null ? [] : [$resource], $context),
            $operation->fields,
            200,
            null,
        );
    }

    /**
     * @throws NotFoundException        404 when the loader returns `null` for the id
     * @throws LogicException           when no parent was loaded,
     *                                  or the related type binds no resource loader
     * @throws UnexpectedValueException when the loader breaks its contract,
     *                                  or the related id accessor returns what it may not
     * @throws OutOfBoundsException     when the related type is not declared
     */
    private function byIdentifier(
        FetchRelated $operation,
        RelationshipMetadata $relationship,
        FieldHint $hint,
        ExecutionContext $context,
    ): ?object {
        $id = $relationship->relatedIdOf($context->record());

        if ($id === null) {
            return null;
        }

        return $this->store->load(new LoadRequest(
            $relationship->relatedType,
            [$id],
            $hint,
            $operation->languages,
            $operation->queryParameters,
        ))[0] ?? throw new NotFoundException([new ErrorDescription(ErrorCode::NotFound)]);
    }
}
