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
use Polunich\WpJsonApi\Execution\Result\AcceptedResult;
use Polunich\WpJsonApi\Execution\Result\NoContentResult;
use Polunich\WpJsonApi\Execution\Result\RelationshipResult;
use Polunich\WpJsonApi\Execution\StoreGateway;
use Polunich\WpJsonApi\Metadata\Cardinality;
use Polunich\WpJsonApi\Metadata\RelationshipMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Operation\AddToRelationship;
use Polunich\WpJsonApi\Operation\FetchRelationship;
use Polunich\WpJsonApi\Operation\Operation;
use Polunich\WpJsonApi\Operation\RemoveFromRelationship;
use Polunich\WpJsonApi\Operation\ReplaceRelationship;
use Polunich\WpJsonApi\Query\Fieldset\FieldHint;
use Polunich\WpJsonApi\Query\Pagination\PageRequest;
use Polunich\WpJsonApi\Store\ConstraintViolationException;
use Polunich\WpJsonApi\Store\LoadRequest;
use Polunich\WpJsonApi\Store\RelatedResourceNotFoundException;
use Polunich\WpJsonApi\Store\ResourceNotFoundException;
use Polunich\WpJsonApi\Store\VersionMismatchException;
use Polunich\WpJsonApi\Text\Printable;
use UnexpectedValueException;

/**
 * replaces, adds to or removes from a relationship of a parent:
 * the three writes differ only in the port that carries them out.
 *
 * adding a member already present and removing one already missing both succeed;
 * only the store knows the stored members, so it applies those rules.
 *
 * inside an atomic request a finished write returns no data, and nothing is read back.
 * a single request reads the relationship back and gets 200:
 * the library cannot tell whether the store changed more than asked,
 * and the spec requires that 200 when it did.
 *
 * the read-back reloads the parent, so its linkage and version are the ones the write left behind;
 * the record loaded before the write is not rendered.
 * `FetchRelationshipProcessor` returns the linkage with the write's include paths and fieldsets,
 * no filter, and the page strategy the relationship route would negotiate,
 * so the 200 of the write equals the first page of that route.
 * it asks no permission: it is the response of a write the client was already allowed.
 *
 * for a parent that can no longer be read, gone or hidden, the library responds with 204.
 * the spec allows it, except when the store changed more than asked:
 * the 200 that case requires would need the relationship that can no longer be read
 *
 * @internal
 */
final readonly class RelationshipWriteProcessor implements OperationProcessor
{
    public function __construct(
        private ResourceRegistry $resources,
        private StoreGateway $store,
        private AcceptedWrites $accepted,
        private FetchRelationshipProcessor $relationshipFetch,
    ) {}

    /**
     * @throws LogicException                   when the operation is no relationship write,
     *                                          or the write or its read-back misses a capability
     * @throws UnexpectedValueException         when the store returns an undeclared monitor type,
     *                                          a loader breaks its batch contract,
     *                                          or an accessor returns what it may not
     * @throws OutOfBoundsException             when a type or a relationship is not declared
     * @throws ResourceNotFoundException        as the store reported it
     * @throws RelatedResourceNotFoundException as the store reported it
     * @throws ConstraintViolationException     as the store reported it
     * @throws VersionMismatchException         as the store reported it
     */
    #[Override]
    public function process(
        Operation $operation,
        ExecutionContext $context,
    ): AcceptedResult|NoContentResult|RelationshipResult {
        if (!$operation instanceof ReplaceRelationship
            && !$operation instanceof AddToRelationship
            && !$operation instanceof RemoveFromRelationship
        ) {
            throw new LogicException(sprintf(
                'a relationship write processor processes a replace_relationship, an add_to_relationship or a'
                . ' remove_from_relationship operation, and this one is %s',
                $operation->kind()->value,
            ));
        }

        $accepted = match (true) {
            $operation instanceof ReplaceRelationship => $this->store->replace($operation),
            $operation instanceof AddToRelationship => $this->store->add($operation),
            default => $this->store->remove($operation),
        };

        if ($accepted !== null) {
            return $this->accepted->result($operation, $accepted, $operation->fields, $context);
        }

        if ($context->isAtomic()) {
            return new NoContentResult();
        }

        return $this->readBack($operation, $context);
    }

    /**
     * the reload asks for every field: the record feeds the related id and version accessors,
     * never a resource object, so no fieldset applies to it
     *
     * @throws LogicException           when the type binds no resource loader,
     *                                  or a capability the reading needs is not bound
     * @throws UnexpectedValueException when a loader breaks its batch contract,
     *                                  or an accessor returns what it may not
     * @throws OutOfBoundsException     when a type or a relationship is not declared
     */
    private function readBack(
        ReplaceRelationship|AddToRelationship|RemoveFromRelationship $operation,
        ExecutionContext $context,
    ): NoContentResult|RelationshipResult {
        $parent = $this->store->load(new LoadRequest(
            $operation->parent->type,
            [$operation->parent->id],
            new FieldHint(null),
            $operation->languages,
            $operation->queryParameters,
        ))[0];

        if ($parent === null) {
            return new NoContentResult();
        }

        return $this->relationshipFetch->process(
            new FetchRelationship(
                $operation->parent,
                $operation->relationship,
                null,
                self::pageOf($this->relationshipOf($operation), $context),
                $operation->include,
                $operation->fields,
                $operation->languages,
                $operation->queryParameters,
            ),
            $context->withRecord($parent),
        );
    }

    private static function pageOf(RelationshipMetadata $relationship, ExecutionContext $context): PageRequest
    {
        return $relationship->cardinality === Cardinality::ToMany && $context->cursorPagination
            ? PageRequest::cursor()
            : PageRequest::pageNumber();
    }

    /**
     * @throws OutOfBoundsException when the type or the relationship is not declared
     */
    private function relationshipOf(
        ReplaceRelationship|AddToRelationship|RemoveFromRelationship $operation,
    ): RelationshipMetadata {
        $resource = $this->resources->get($operation->parent->type);

        return $resource->relationships[$operation->relationship] ?? throw new OutOfBoundsException(sprintf(
            'type "%s" declares no relationship "%s"',
            $resource->type,
            Printable::of($operation->relationship),
        ));
    }
}
