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

namespace Polunich\WpJsonApi\Execution;

use InvalidArgumentException;
use LogicException;
use OutOfBoundsException;
use Polunich\WpJsonApi\Access\Gate;
use Polunich\WpJsonApi\Access\OperationAttempt;
use Polunich\WpJsonApi\Error\ConflictException;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Error\NotFoundException;
use Polunich\WpJsonApi\Error\PreconditionFailedException;
use Polunich\WpJsonApi\Execution\Processor\OperationProcessor;
use Polunich\WpJsonApi\Execution\Result\Result;
use Polunich\WpJsonApi\Http\FieldName;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Operation\AddToRelationship;
use Polunich\WpJsonApi\Operation\ConditionalWrite;
use Polunich\WpJsonApi\Operation\CreateResource;
use Polunich\WpJsonApi\Operation\DeleteResource;
use Polunich\WpJsonApi\Operation\FetchRelated;
use Polunich\WpJsonApi\Operation\FetchRelationship;
use Polunich\WpJsonApi\Operation\FetchResource;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\Linkage;
use Polunich\WpJsonApi\Operation\Operation;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Operation\PendingWrite;
use Polunich\WpJsonApi\Operation\RemoveFromRelationship;
use Polunich\WpJsonApi\Operation\ReplaceRelationship;
use Polunich\WpJsonApi\Operation\UpdateResource;
use Polunich\WpJsonApi\Query\Fieldset\FieldHint;
use Polunich\WpJsonApi\Store\ConstraintViolationException;
use Polunich\WpJsonApi\Store\LoadRequest;
use Polunich\WpJsonApi\Store\RelatedResourceNotFoundException;
use Polunich\WpJsonApi\Store\ResourceExistsException;
use Polunich\WpJsonApi\Store\ResourceNotFoundException;
use Polunich\WpJsonApi\Store\VersionMismatchException;
use Polunich\WpJsonApi\Text\Printable;
use RuntimeException;
use UnexpectedValueException;

/**
 * runs one operation: loads the target or parent with its record-level permission,
 * then runs the processor of the operation kind and translates the failures a store reports.
 *
 * permissions and enabled kinds are the gate's:
 * callers ask it before `execute()`, and `loadTarget()` asks it again with the loaded record.
 *
 * the five store failures become errors here, once for every kind.
 * a `JsonApiException` a store raises passes through as the project's own response
 *
 * @internal
 */
final readonly class Executor
{
    /**
     * @param array<value-of<OperationKind>, OperationProcessor> $processors
     *
     * @throws InvalidArgumentException when a kind has no processor
     */
    public function __construct(
        private ResourceRegistry $resources,
        private StoreGateway $store,
        private Gate $gate,
        private array $processors,
    ) {
        foreach (OperationKind::cases() as $kind) {
            if (!array_key_exists($kind->value, $processors)) {
                throw new InvalidArgumentException(sprintf(
                    'no processor is bound for the kind %s, and an executor executes every kind a request'
                    . ' becomes',
                    $kind->value,
                ));
            }
        }
    }

    /**
     * a `PendingWrite` stands in for a write whose operation is built only after this load
     *
     * @throws LogicException       when it acts on no existing resource
     * @throws OutOfBoundsException when the type is not declared
     */
    public function targetRequest(Operation|PendingWrite $operation): LoadRequest
    {
        if ($operation instanceof PendingWrite) {
            return $this->requestFor($operation, $operation->target ?? throw new LogicException(sprintf(
                'a pending %s names no target and no parent, and a record is loaded for a write that acts on an'
                . ' existing resource',
                $operation->kind->value,
            )));
        }

        return match (true) {
            $operation instanceof FetchResource => $this->requestFor($operation, $operation->target),
            $operation instanceof UpdateResource => $this->requestFor($operation, $operation->target),
            $operation instanceof DeleteResource => $this->requestFor($operation, $operation->target),
            $operation instanceof FetchRelated => $this->requestFor($operation, $operation->parent),
            $operation instanceof FetchRelationship => $this->requestFor($operation, $operation->parent),
            $operation instanceof ReplaceRelationship => $this->requestFor($operation, $operation->parent),
            $operation instanceof AddToRelationship => $this->requestFor($operation, $operation->parent),
            $operation instanceof RemoveFromRelationship => $this->requestFor($operation, $operation->parent),
            default => throw new LogicException(sprintf(
                'a %s operation names no target and no parent, and a record is loaded for the eight kinds'
                . ' that act on an existing resource',
                $operation->kind()->value,
            )),
        };
    }

    /**
     * @throws NotFoundException        404 when the record is missing or hidden from the client
     * @throws JsonApiException         the 401 or 403 of the record-level permission
     * @throws InvalidArgumentException when `OperationAttempt::forRecord()` refuses the kind
     *                                  or the relationship
     * @throws UnexpectedValueException when the loader breaks its batch contract
     * @throws LogicException           when the request names other than one id,
     *                                  or the type binds no resource loader
     * @throws OutOfBoundsException     when the type is not declared
     */
    public function loadTarget(
        LoadRequest $request,
        OperationKind $kind,
        ?string $relationship,
        ExecutionContext $context,
    ): object {
        if (count($request->ids) !== 1) {
            throw new LogicException(sprintf(
                'a %s operation acts on one resource, and the request that loads it names %d ids',
                $kind->value,
                count($request->ids),
            ));
        }

        $record = $this->store->load($request)[0];

        // missing and hidden are one response:
        // RFC 9110 15.5.4 lets a server hide a forbidden target behind a 404
        if ($record === null) {
            throw new NotFoundException([new ErrorDescription(ErrorCode::NotFound)]);
        }

        $this->gate->check(OperationAttempt::forRecord(
            $kind,
            $request->type,
            $relationship,
            $context->request,
            $record,
        ), $context->securitySchemes);

        return $record;
    }

    /**
     * @throws JsonApiException         what a store failure becomes, and what a processor raises
     * @throws UnexpectedValueException when a store breaks a contract,
     *                                  such as reporting a failure the operation cannot carry
     * @throws LogicException           when the kind has no processor,
     *                                  a capability the gate let through is not bound,
     *                                  or the context lacks the record the kind needs
     * @throws OutOfBoundsException     when a type or a relationship is not declared
     */
    public function execute(Operation $operation, ExecutionContext $context): Result
    {
        $processor = $this->processors[$operation->kind()->value]
            ?? throw new LogicException(sprintf(
                'no processor is bound for the kind %s, and an executor executes every kind a request'
                . ' becomes',
                $operation->kind()->value,
            ));

        try {
            return $processor->process($operation, $context);
        } catch (
            ResourceNotFoundException
            |RelatedResourceNotFoundException
            |ResourceExistsException
            |ConstraintViolationException
            |VersionMismatchException $failure
        ) {
            throw $this->translate($failure, $operation);
        }
    }

    /**
     * @throws UnexpectedValueException when the operation cannot carry the failure,
     *                                  such as a taken id on an update: a store bug
     * @throws LogicException           when the failure is none of the five `execute()` catches
     */
    private function translate(RuntimeException $failure, Operation $operation): JsonApiException
    {
        return match (true) {
            // a target gone by the time the write runs gets the response of one missing before it
            $failure instanceof ResourceNotFoundException => new NotFoundException(
                [new ErrorDescription(ErrorCode::NotFound)],
                $failure,
            ),
            $failure instanceof RelatedResourceNotFoundException => self::missingRelatedResources($failure, $operation),
            $failure instanceof ResourceExistsException => self::existingResource($failure, $operation),
            $failure instanceof ConstraintViolationException => self::violatedConstraint($failure, $operation),
            $failure instanceof VersionMismatchException => self::failedPrecondition($operation),
            default => throw new LogicException(sprintf(
                'a %s is no failure of a store, and the five a store reports are the ones the executor'
                . ' translates',
                $failure::class,
            )),
        };
    }

    /**
     * only a fetched resource is rendered, so only its load narrows the fields:
     * to the fieldset of the client, widened by what the library reads.
     * the other kinds feed the record to the permission and the version and related id accessors,
     * so their load asks for every field
     *
     * @param (
     *     FetchResource
     *     |FetchRelated
     *     |FetchRelationship
     *     |UpdateResource
     *     |DeleteResource
     *     |ReplaceRelationship
     *     |AddToRelationship
     *     |RemoveFromRelationship
     *     |PendingWrite
     * ) $operation
     *
     * @throws OutOfBoundsException when the type is not declared
     */
    private function requestFor(Operation|PendingWrite $operation, Identifier $identifier): LoadRequest
    {
        $hint = $operation instanceof FetchResource
            ? new FieldHint(
                $operation->fields->fields($identifier->type),
                IncludeLoader::requiredFields($this->resources->get($identifier->type), null),
            )
            : new FieldHint(null);

        return new LoadRequest(
            $identifier->type,
            [$identifier->id],
            $hint,
            $operation->languages,
            $operation->queryParameters,
        );
    }

    /**
     * one error per position of a missing identifier in the request linkage,
     * so an identifier the request names twice is pointed at twice
     *
     * @throws UnexpectedValueException when the operation carries no linkage of a relationship
     *                                  the store named, or no identifier the store reported
     */
    private static function missingRelatedResources(
        RelatedResourceNotFoundException $failure,
        Operation $operation,
    ): NotFoundException {
        $descriptions = [];

        foreach ($failure->missing as $key => $identifiers) {
            // a relationship name of digits arrives as an int key
            $name = (string) $key;
            $linkage = self::referencedLinkage($operation, $name);
            // a create or an update nests the linkage under the relationship name;
            // a relationship write sends it as its primary data
            $data = $operation->relationship() === null ? '/data/relationships/' . $name . '/data' : '/data';
            $members = $linkage->identifiers();

            foreach ($identifiers as $identifier) {
                $places = [];

                foreach ($members as $place => $member) {
                    if ($member->type === $identifier->type && $member->id === $identifier->id) {
                        $places[] = $place;
                    }
                }

                if ($places === []) {
                    throw new UnexpectedValueException(sprintf(
                        'the store reports the %s resource "%s" missing from relationship "%s", whose linkage'
                        . ' the request does not name it in, and a pointer "MUST point to a value in the'
                        . ' request document that exists" [spec]',
                        Printable::of($identifier->type),
                        Printable::of($identifier->id),
                        Printable::of($name),
                    ));
                }

                foreach ($places as $place) {
                    $descriptions[] = new ErrorDescription(
                        ErrorCode::RelatedResourceNotFound,
                        ['relationship' => $name, 'type' => $identifier->type, 'id' => $identifier->id],
                        pointer: $linkage->isToMany() ? $data . '/' . $place : $data,
                    );
                }
            }
        }

        return new NotFoundException($descriptions, $failure);
    }

    /**
     * @throws UnexpectedValueException when the kind references no related resource at all,
     *                                  or carries no linkage of that name
     */
    private static function referencedLinkage(Operation $operation, string $name): Linkage
    {
        if ($operation instanceof CreateResource || $operation instanceof UpdateResource) {
            if (!$operation->relationships->has($name)) {
                throw new UnexpectedValueException(sprintf(
                    'the store reports related resources missing from relationship "%s", which the document'
                    . ' of the %s operation does not carry',
                    Printable::of($name),
                    $operation->kind()->value,
                ));
            }

            return $operation->relationships->get($name);
        }

        if ($operation instanceof ReplaceRelationship
            || $operation instanceof AddToRelationship
            || $operation instanceof RemoveFromRelationship
        ) {
            if ($operation->relationship !== $name) {
                throw new UnexpectedValueException(sprintf(
                    'the store reports related resources missing from relationship "%s", and the %s operation'
                    . ' writes relationship "%s"',
                    Printable::of($name),
                    $operation->kind()->value,
                    Printable::of($operation->relationship),
                ));
            }

            return $operation->linkage;
        }

        throw new UnexpectedValueException(sprintf(
            'the store reports related resources missing from a %s operation, and a create, an update and'
            . ' the three relationship writes are the operations that reference one',
            $operation->kind()->value,
        ));
    }

    /**
     * @throws UnexpectedValueException when the operation is no create or sends no id,
     *                                  leaving `/data/id` pointing at nothing
     */
    private static function existingResource(
        ResourceExistsException $failure,
        Operation $operation,
    ): ConflictException {
        if (!$operation instanceof CreateResource) {
            throw new UnexpectedValueException(sprintf(
                'the store reports the client-generated id of a %s operation taken, and a create is the'
                . ' operation that names one',
                $operation->kind()->value,
            ));
        }

        if ($operation->id === null) {
            throw new UnexpectedValueException(sprintf(
                'the store reports the client-generated id of a create of type "%s" taken, and the request'
                . ' sends none, so the pointer /data/id names no value of its document',
                Printable::of($operation->type),
            ));
        }

        return new ConflictException([new ErrorDescription(
            ErrorCode::ResourceAlreadyExists,
            ['type' => $operation->type, 'id' => $operation->id],
            pointer: '/data/id',
        )], $failure);
    }

    /**
     * @throws UnexpectedValueException when the operation is no write, which breaks no constraint
     */
    private static function violatedConstraint(
        ConstraintViolationException $failure,
        Operation $operation,
    ): ConflictException {
        return new ConflictException(self::constraintSources($failure, $operation), $failure);
    }

    /**
     * one error per pointer: each violated field a create or an update carries, else `/data`;
     * `/data` on a relationship write; no source on a delete, whose request has no document
     *
     * @return non-empty-list<ErrorDescription>
     *
     * @throws UnexpectedValueException when the operation is no write, which breaks no constraint
     */
    private static function constraintSources(
        ConstraintViolationException $failure,
        Operation $operation,
    ): array {
        $parameters = ['constraint' => $failure->constraint];

        if ($operation instanceof CreateResource || $operation instanceof UpdateResource) {
            $fields = [];

            foreach ($failure->fields as $field) {
                $pointer = match (true) {
                    $operation->attributes->has($field) => '/data/attributes/' . $field,
                    $operation->relationships->has($field) => '/data/relationships/' . $field,
                    // a pointer must name a value the request document holds
                    default => null,
                };

                if ($pointer !== null) {
                    $fields[] = new ErrorDescription(ErrorCode::ConstraintViolation, $parameters, pointer: $pointer);
                }
            }

            return $fields === []
                ? [new ErrorDescription(ErrorCode::ConstraintViolation, $parameters, pointer: '/data')]
                : $fields;
        }

        if ($operation instanceof ReplaceRelationship
            || $operation instanceof AddToRelationship
            || $operation instanceof RemoveFromRelationship
        ) {
            return [new ErrorDescription(ErrorCode::ConstraintViolation, $parameters, pointer: '/data')];
        }

        if ($operation instanceof DeleteResource) {
            return [new ErrorDescription(ErrorCode::ConstraintViolation, $parameters)];
        }

        throw new UnexpectedValueException(sprintf(
            'the store reports constraint "%s" violated by a %s operation, and a constraint is broken by a'
            . ' write',
            $failure->constraint,
            $operation->kind()->value,
        ));
    }

    /**
     * @throws UnexpectedValueException when the operation carries no precondition that could fail
     */
    private static function failedPrecondition(Operation $operation): PreconditionFailedException
    {
        if (!$operation instanceof ConditionalWrite) {
            throw new UnexpectedValueException(sprintf(
                'the store reports the precondition of a %s operation refused, and the five writes that'
                . ' change an existing resource are the operations that carry one',
                $operation->kind()->value,
            ));
        }

        $precondition = $operation->precondition();

        if ($precondition === null) {
            throw new UnexpectedValueException(sprintf(
                'the store reports the precondition of a %s operation refused, and the write carries none,'
                . ' so it has no condition that could fail',
                $operation->kind()->value,
            ));
        }

        if ($precondition->versions === null) {
            throw new UnexpectedValueException(sprintf(
                'the store reports the precondition of a %s operation refused, and the write carries If-Match: *,'
                . ' which admits every stored version; a record that is gone is not found instead',
                $operation->kind()->value,
            ));
        }

        return Preconditions::preconditionFailed(FieldName::IF_MATCH);
    }
}
