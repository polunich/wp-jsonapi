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
use Polunich\WpJsonApi\Execution\Preconditions;
use Polunich\WpJsonApi\Execution\Result\RecordLinkage;
use Polunich\WpJsonApi\Execution\Result\RelationshipResult;
use Polunich\WpJsonApi\Execution\Result\ResourceRecord;
use Polunich\WpJsonApi\Execution\StoreGateway;
use Polunich\WpJsonApi\Metadata\Cardinality;
use Polunich\WpJsonApi\Metadata\RelationshipMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Operation\FetchRelationship;
use Polunich\WpJsonApi\Operation\Operation;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\Fieldset\FieldHint;
use Polunich\WpJsonApi\Query\ParentScope;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Store\LoadRequest;
use Polunich\WpJsonApi\Store\RelatedLoadRequest;
use UnexpectedValueException;

/**
 * fetches the linkage of one relationship of a parent.
 *
 * the primary data are identifiers, so the route takes no client sort:
 * it reads in the default sort of the relationship with `id` appended,
 * and its filter holds only the conditions of its cursors.
 *
 * a related id read off the parent loads nothing unless `include` names a path.
 * a record the loader then hides still renders as an identifier, never as a 404.
 *
 * the tag is the parent's: the version accessor belongs to the parent,
 * and the representation is one of its relationships
 *
 * @internal
 */
final readonly class FetchRelationshipProcessor implements OperationProcessor
{
    public function __construct(
        private ResourceRegistry $resources,
        private StoreGateway $store,
        private Paginator $paginator,
        private IncludeLoader $includes,
        private Preconditions $preconditions,
    ) {}

    /**
     * @throws LogicException           when the operation is of another kind, no parent was loaded,
     *                                  or a capability the reading needs is not bound
     * @throws UnexpectedValueException when a store breaks its contract,
     *                                  an accessor returns what it may not,
     *                                  or a boundary cursor cannot be written
     * @throws OutOfBoundsException     when a type or a relationship is not declared
     */
    #[Override]
    public function process(Operation $operation, ExecutionContext $context): RelationshipResult
    {
        if (!$operation instanceof FetchRelationship) {
            throw new LogicException(sprintf(
                'a fetch_relationship processor processes a fetch_relationship operation, and this one is %s',
                $operation->kind()->value,
            ));
        }

        $parentType = $operation->parent->type;
        $name = $operation->relationship;
        $relationship = $this->resources->relationshipOf($parentType, $name);

        [$linkage, $related] = match (true) {
            $relationship->cardinality === Cardinality::ToMany => $this->page($operation, $relationship),
            $relationship->readsRelatedId() => $this->byRelatedId($operation, $relationship, $context),
            default => $this->related($operation, $relationship),
        };

        return new RelationshipResult(
            $parentType,
            $operation->parent->id,
            $name,
            $relationship->relatedType,
            $linkage,
            $this->includes->loadFromRelationship(GraphRequest::of($operation), $parentType, $name, $related, $context),
            $operation->fields,
            $this->preconditions->entityTag($parentType, $context->record(), $context),
        );
    }

    /**
     * read through the same port and scope as the related route, so both routes page alike
     *
     * @return array{RecordLinkage, list<ResourceRecord>}
     *
     * @throws LogicException           when the related type binds no collection reader
     * @throws UnexpectedValueException when the reader breaks its contract,
     *                                  or a boundary cursor cannot be written
     * @throws OutOfBoundsException     when a type or a relationship is not declared
     */
    private function page(FetchRelationship $operation, RelationshipMetadata $relationship): array
    {
        $parentType = $operation->parent->type;
        $name = $relationship->name;
        $relatedType = $relationship->relatedType;
        $sort = $this->resources->readingSort($operation);
        $policy = $this->resources->relationshipPaginationPolicy($parentType, $name);

        $slice = $this->store->read($this->paginator->collectionQuery(
            $relatedType,
            $operation->filter,
            $sort,
            $operation->page,
            $policy,
            $this->hint($operation, $relatedType, $sort),
            $operation->languages,
            new ParentScope($parentType, $operation->parent->id, $name),
            $operation->queryParameters,
        ));
        $page = $this->paginator->page($operation->page, $operation->languages, $slice, $policy, $relatedType, $sort);

        return [RecordLinkage::toMany($page), $page->records];
    }

    /**
     * @return array{RecordLinkage, list<ResourceRecord>}
     *
     * @throws LogicException           when no parent was loaded,
     *                                  or the related type binds no resource loader
     * @throws UnexpectedValueException when the loader breaks its contract,
     *                                  or the related id accessor returns what it may not
     * @throws OutOfBoundsException     when the related type is not declared
     */
    private function byRelatedId(
        FetchRelationship $operation,
        RelationshipMetadata $relationship,
        ExecutionContext $context,
    ): array {
        $relatedType = $relationship->relatedType;
        $id = $relationship->relatedIdOf($context->record());

        if ($id === null) {
            return [RecordLinkage::toOne(null), []];
        }

        $linkage = RecordLinkage::toOne(new ResourceRecord($relatedType, $id, null));

        // `include=` names no path; every other include of this route starts with its relationship
        // and so reaches the related record
        if ($operation->include === null || $operation->include->isEmpty()) {
            return [$linkage, []];
        }

        // the record is loaded for `included`, which the parameters of the route do not address
        $record = $this->store->load(new LoadRequest(
            $relatedType,
            [$id],
            $this->hint($operation, $relatedType, null),
            $operation->languages,
            new Values([]),
        ))[0];

        return [$linkage, $record === null ? [] : [ResourceRecord::of($this->resources->get($relatedType), $record)]];
    }

    /**
     * @return array{RecordLinkage, list<ResourceRecord>}
     *
     * @throws LogicException           when the type of the parent binds no related loader
     * @throws UnexpectedValueException when the loader breaks its contract,
     *                                  or the id accessor returns no non-empty string
     * @throws OutOfBoundsException     when a type is not declared
     */
    private function related(FetchRelationship $operation, RelationshipMetadata $relationship): array
    {
        $relatedType = $relationship->relatedType;
        $record = $this->store->loadToOne(RelatedLoadRequest::toOne(
            $operation->parent->type,
            [$operation->parent->id],
            $relationship->name,
            $this->hint($operation, $relatedType, null),
            $operation->languages,
            $operation->queryParameters,
        ))[0];

        if ($record === null) {
            return [RecordLinkage::toOne(null), []];
        }

        $resource = ResourceRecord::of($this->resources->get($relatedType), $record);

        return [RecordLinkage::toOne($resource), [$resource]];
    }

    /**
     * without a path in `include` the records render as identifiers,
     * so the hint asks for no client field
     *
     * @throws OutOfBoundsException when the type is not declared
     */
    private function hint(FetchRelationship $operation, string $type, ?Sort $sort): FieldHint
    {
        return $operation->include === null || $operation->include->isEmpty()
            ? $this->includes->identifierHint($type, $sort)
            : $this->includes->fieldHint($type, $operation->fields, $sort);
    }
}
