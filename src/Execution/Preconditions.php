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

use OutOfBoundsException;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\PreconditionFailedException;
use Polunich\WpJsonApi\Error\PreconditionRequiredException;
use Polunich\WpJsonApi\Execution\Result\NotModifiedResult;
use Polunich\WpJsonApi\Http\EntityTag;
use Polunich\WpJsonApi\Http\FieldName;
use Polunich\WpJsonApi\Http\IfMatch;
use Polunich\WpJsonApi\Http\IfNoneMatch;
use Polunich\WpJsonApi\Http\Method;
use Polunich\WpJsonApi\Http\VersionTag;
use Polunich\WpJsonApi\Metadata\EndpointMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Operation\Precondition;
use Polunich\WpJsonApi\Text\Printable;
use UnexpectedValueException;

/**
 * every entity tag the library writes and every precondition it evaluates,
 * kept in one class so that the tag and its comparison cannot drift apart.
 *
 * a tag covers the record version, the content language and the applied ext and profile.
 * none is written where a representation is not one record under one negotiation:
 *
 * - inside an atomic request, where a failing version accessor would roll back a success;
 * - for a request that supplies `include`;
 * - for a type without a version policy;
 * - for a collection, the related route, a 202 or a 204, whose results carry none by construction.
 *
 * a representation without a tag still matches `If-Match: *` and never a list of tags,
 * so `evaluate()` runs for every kind, the collection route and a create included.
 *
 * `evaluate()` belongs after the target load and its record-level permission,
 * and before the content of a write is parsed (RFC 9110 13.2.1).
 * it checks 428 first, since that refuses a request carrying no precondition at all (RFC 6585 3),
 * then `If-Match` and `If-None-Match` in the order of RFC 9110 13.2.2.
 *
 * the operations of an atomic request carry no precondition of their own,
 * so a type that requires one refuses their writes to existing resources with 428.
 *
 * `evaluateEndpoint()` evaluates an endpoint against its precondition record,
 * the one record whose tag it carries;
 * `If-Match` is required where the endpoint writes that record and its type requires preconditions
 *
 * @internal
 */
final readonly class Preconditions
{
    public function __construct(private ResourceRegistry $resources) {}

    /**
     * @throws OutOfBoundsException     when the type is not declared
     * @throws UnexpectedValueException when the project's version accessor returns no string
     */
    public function entityTag(string $type, ?object $record, ExecutionContext $context): ?EntityTag
    {
        if ($context->isAtomic() || $context->includeSupplied || $record === null) {
            return null;
        }

        $policy = $this->resources->get($type)->versionPolicy;

        if ($policy === null) {
            return null;
        }

        return VersionTag::encode($policy->versionOf($record), $context->contentLanguage, $context->contentType);
    }

    /**
     * @param ?object $record `null` for a collection fetch or a create: both address the collection
     *
     * @return ?NotModifiedResult a 304 to send instead of running the operation
     *
     * @throws PreconditionRequiredException 428 precondition_required, source.header If-Match
     * @throws PreconditionFailedException   412 invalid_precondition or precondition_failed
     * @throws OutOfBoundsException          when the type is not declared
     * @throws UnexpectedValueException      when the project's version accessor returns no string
     */
    public function evaluate(
        OperationKind $kind,
        string $type,
        ?object $record,
        ExecutionContext $context,
    ): ?NotModifiedResult {
        // the related route carries no tag, though the loaded parent could give one
        $current = $kind === OperationKind::FetchRelated ? null : $this->entityTag($type, $record, $context);

        return $this->evaluateTag($current, $this->requiresPrecondition($kind, $type), $context);
    }

    /**
     * @param ?string $type   the type of the precondition record,
     *                        `null` for an endpoint without one
     * @param ?object $record the precondition record
     *
     * @return ?NotModifiedResult a 304 to send instead of running the handler
     *
     * @throws PreconditionRequiredException 428 precondition_required, source.header If-Match
     * @throws PreconditionFailedException   412 invalid_precondition or precondition_failed
     * @throws OutOfBoundsException          when the type is not declared
     * @throws UnexpectedValueException      when the project's version accessor returns no string
     */
    public function evaluateEndpoint(
        EndpointMetadata $endpoint,
        ?string $type,
        ?object $record,
        ExecutionContext $context,
    ): ?NotModifiedResult {
        if ($type === null || $record === null) {
            return $this->evaluateTag(null, false, $context);
        }

        $required = $endpoint->writesPreconditionRecord() && $this->resources->get($type)->requiresPreconditions();

        return $this->evaluateTag($this->entityTag($type, $record, $context), $required, $context);
    }

    /**
     * the precondition the store evaluates inside the write;
     * call it only after `evaluate()` or `evaluateEndpoint()` held
     *
     * @throws UnexpectedValueException when the field is malformed or decodes to no version,
     *                                  neither of which can happen once either evaluation held
     */
    public function precondition(ExecutionContext $context): ?Precondition
    {
        $line = $context->request->headers->line(FieldName::IF_MATCH);

        if ($line === null) {
            return null;
        }

        $ifMatch = IfMatch::parse($line);

        if ($ifMatch === null) {
            throw new UnexpectedValueException(sprintf(
                'the If-Match field value "%s" is neither * nor a list of entity tags, and a precondition is'
                . ' read only once the evaluation of the preconditions held, which refuses such a value with 412',
                Printable::of($line),
            ));
        }

        $versions = $ifMatch->versions($context->contentLanguage, $context->contentType);

        if ($versions === null) {
            return Precondition::anyVersion();
        }

        if ($versions === []) {
            throw new UnexpectedValueException(sprintf(
                'the If-Match field value "%s" names no version of this negotiation, and a list of entity tags'
                . ' holds only where one of them matches the tag of the loaded record',
                Printable::of($line),
            ));
        }

        return Precondition::listedVersions($versions);
    }

    /**
     * @param ?EntityTag $current  the tag of the representation, `null` for one without a tag
     * @param bool       $required whether the request must carry If-Match
     *
     * @throws PreconditionRequiredException 428 precondition_required, source.header If-Match
     * @throws PreconditionFailedException   412 invalid_precondition or precondition_failed
     */
    private function evaluateTag(?EntityTag $current, bool $required, ExecutionContext $context): ?NotModifiedResult
    {
        $headers = $context->request->headers;

        if ($required && !$headers->has(FieldName::IF_MATCH)) {
            throw self::preconditionRequired();
        }

        $ifMatchLine = $headers->line(FieldName::IF_MATCH);

        if ($ifMatchLine !== null) {
            $ifMatch = IfMatch::parse($ifMatchLine);

            if ($ifMatch === null) {
                throw new PreconditionFailedException([
                    new ErrorDescription(ErrorCode::InvalidPrecondition, header: FieldName::IF_MATCH),
                ]);
            }

            if (!$ifMatch->matches($current)) {
                throw self::preconditionFailed(FieldName::IF_MATCH);
            }
        }

        $ifNoneMatchLine = $headers->line(FieldName::IF_NONE_MATCH);

        if ($ifNoneMatchLine === null) {
            return null;
        }

        $ifNoneMatch = IfNoneMatch::parse($ifNoneMatchLine);

        if ($ifNoneMatch === null || !$ifNoneMatch->matches($current)) {
            return null;
        }

        if (Method::retrieves($context->request->method)) {
            return new NotModifiedResult($current);
        }

        throw self::preconditionFailed(FieldName::IF_NONE_MATCH);
    }

    /**
     * the POST of an atomic route addresses no representation:
     * `If-Match` never holds there, and `If-None-Match` always does.
     * `IfMatch::matches()` is not asked: its `null` is a tagless representation, which `*` matches
     *
     * @throws PreconditionFailedException 412 invalid_precondition when the field is malformed,
     *                                     412 precondition_failed when it is sent at all
     */
    public function evaluateAtomicRoute(ExecutionContext $context): void
    {
        $line = $context->request->headers->line(FieldName::IF_MATCH);

        if ($line === null) {
            return;
        }

        if (IfMatch::parse($line) === null) {
            throw new PreconditionFailedException([
                new ErrorDescription(ErrorCode::InvalidPrecondition, header: FieldName::IF_MATCH),
            ]);
        }

        throw self::preconditionFailed(FieldName::IF_MATCH);
    }

    /**
     * @throws PreconditionRequiredException 428 precondition_required, source.header If-Match
     * @throws OutOfBoundsException          when the type is not declared
     */
    public function refuseUnconditionalOperation(OperationKind $kind, string $type): void
    {
        if ($this->requiresPrecondition($kind, $type)) {
            throw self::preconditionRequired();
        }
    }

    /**
     * shared by the evaluation before the write and a failure the store reports inside it,
     * so that the library responds to both alike
     */
    public static function preconditionFailed(string $field): PreconditionFailedException
    {
        return new PreconditionFailedException([
            new ErrorDescription(ErrorCode::PreconditionFailed, ['field' => $field], header: $field),
        ]);
    }

    /**
     * @throws OutOfBoundsException when the type is not declared
     */
    private function requiresPrecondition(OperationKind $kind, string $type): bool
    {
        return $kind->isConditionalWrite() && $this->resources->get($type)->requiresPreconditions();
    }

    private static function preconditionRequired(): PreconditionRequiredException
    {
        return new PreconditionRequiredException([
            new ErrorDescription(ErrorCode::PreconditionRequired, header: FieldName::IF_MATCH),
        ]);
    }
}
