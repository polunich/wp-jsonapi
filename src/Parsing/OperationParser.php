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

use InvalidArgumentException;
use LogicException;
use OutOfBoundsException;
use Polunich\WpJsonApi\Codec\DecodeException;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\ErrorList;
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Error\NotFoundException;
use Polunich\WpJsonApi\Error\StatusSelector;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Metadata\Cardinality;
use Polunich\WpJsonApi\Metadata\RelationshipMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Metadata\RouteQuery;
use Polunich\WpJsonApi\Operation\AddToRelationship;
use Polunich\WpJsonApi\Operation\CreateResource;
use Polunich\WpJsonApi\Operation\DeleteResource;
use Polunich\WpJsonApi\Operation\FetchCollection;
use Polunich\WpJsonApi\Operation\FetchRelated;
use Polunich\WpJsonApi\Operation\FetchRelationship;
use Polunich\WpJsonApi\Operation\FetchResource;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\Operation;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Operation\PendingWrite;
use Polunich\WpJsonApi\Operation\RemoveFromRelationship;
use Polunich\WpJsonApi\Operation\ReplaceRelationship;
use Polunich\WpJsonApi\Operation\UpdateResource;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\Inclusion\IncludePaths;
use Polunich\WpJsonApi\Query\Pagination\PageRequest;
use Polunich\WpJsonApi\Query\ParameterFamily;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Routing\RouteKind;
use Polunich\WpJsonApi\Routing\RouteTarget;
use Polunich\WpJsonApi\Text\Printable;

/**
 * reads a request of a resource or relationship route into its operation;
 * an atomic route has its own parser, since its document carries the kinds.
 *
 * each phase runs only when the ones before it found no error:
 *
 * 1. `Negotiator::negotiateRoute()`, which the caller runs: 406 and 415.
 *    `parse()` takes its result: a requested cursor profile makes the cursor the default strategy.
 * 2. `parse()`: the route id, then the query string,
 *    every mistake of it collected in one error list.
 *    fetches and the delete end here; a write ends in an `Operation\PendingWrite`.
 * 3. `parseContent()`: the body of a write.
 *
 * the body is read apart because in between the caller responds with the target's 404 and 403
 * and evaluates the preconditions.
 * a write is built without a precondition, and the caller attaches it with `withPrecondition()`
 *
 * @internal
 */
final readonly class OperationParser
{
    public function __construct(
        private ResourceRegistry $resources,
        private QueryParameterGuard $guard,
        private CollectionReadingParser $collections,
        private PageParser $pages,
        private IncludeParser $includes,
        private FieldsetParser $fieldsets,
        private JsonBodyDecoder $json,
        private DocumentParser $documents,
        private StatusSelector $statuses,
    ) {}

    /**
     * @param Negotiation $negotiation what `Negotiator::negotiateRoute()` returned
     *
     * @throws JsonApiException         404 for a route id its type's codec refuses,
     *                                  else every problem of the query string
     * @throws InvalidArgumentException for an atomic route,
     *                                  or a method no route of its kind registers
     * @throws OutOfBoundsException     when the route's type or relationship is not declared
     */
    public function parse(
        ServerRequest $request,
        RouteTarget $target,
        Negotiation $negotiation,
        LanguagePreferences $languages,
    ): Operation|PendingWrite {
        $kind = self::kindOf($request, $target);
        $id = $target->id === null ? null : $this->routeId($target);
        $route = RouteQuery::ofKind($kind, self::typeOf($target), $target->relationship, $this->resources);
        $errors = new ErrorList($this->statuses);
        $this->guard->check($request->query, $route, $errors);
        $queryParameters = QueryParameterReader::read($request->query, $route->declared, $errors);

        if ($kind->carriesContent()) {
            [$include, $fields] = $this->writeFeatures($kind, $request, $target, $errors);

            $errors->throwIfAny();

            $type = self::typeOf($target);

            return new PendingWrite(
                $kind,
                $type,
                $id === null ? null : new Identifier($type, $id),
                $kind->targetsRelationship() ? $this->relationshipOf($target)->name : null,
                $include,
                $fields,
                $languages,
                $queryParameters,
            );
        }

        return match ($kind) {
            OperationKind::FetchCollection => $this->fetchCollection(
                $request,
                $target,
                $negotiation,
                $languages,
                $queryParameters,
                $errors,
            ),
            OperationKind::FetchResource => $this->fetchResource(
                $request,
                $target,
                $id,
                $languages,
                $queryParameters,
                $errors,
            ),
            OperationKind::FetchRelated => $this->fetchRelated(
                $request,
                $target,
                $id,
                $negotiation,
                $languages,
                $queryParameters,
                $errors,
            ),
            OperationKind::FetchRelationship => $this->fetchRelationship(
                $request,
                $target,
                $id,
                $negotiation,
                $languages,
                $queryParameters,
                $errors,
            ),
            // the delete, the one write without content
            default => $this->deleteResource($target, $id, $languages, $queryParameters, $errors),
        };
    }

    /**
     * @param PendingWrite $write what `parse()` returned
     *
     * @throws JsonApiException     every problem of the content
     * @throws OutOfBoundsException when the type or the relationship of the write is not declared
     */
    public function parseContent(ServerRequest $request, PendingWrite $write): Operation
    {
        $errors = new ErrorList($this->statuses);

        return match ($write->kind) {
            OperationKind::CreateResource => $this->createResource($request, $write, $errors),
            OperationKind::UpdateResource => $this->updateResource($request, $write, $errors),
            // the three relationship writes
            default => $this->relationshipWrite($request, $write, $errors),
        };
    }

    /**
     * @param Values<mixed> $queryParameters
     *
     * @throws JsonApiException
     */
    private function fetchCollection(
        ServerRequest $request,
        RouteTarget $target,
        Negotiation $negotiation,
        LanguagePreferences $languages,
        Values $queryParameters,
        ErrorList $errors,
    ): FetchCollection {
        $type = self::typeOf($target);
        $resource = $this->resources->get($type);
        $include = $this->includePaths($request, $type, null, $errors);
        $fields = $this->fieldsetsOf($request, $errors);
        $reading = $this->collections->parse(
            $request->query,
            $type,
            $resource->paginationPolicy,
            $resource->defaultSort,
            $negotiation->defaultPageStrategy(),
            $errors,
        );

        $errors->throwIfAny();

        return new FetchCollection(
            $type,
            $reading->filter,
            $reading->sort,
            $reading->page,
            $include,
            $fields,
            $languages,
            $queryParameters,
        );
    }

    /**
     * @param Values<mixed> $queryParameters
     *
     * @throws JsonApiException
     */
    private function fetchResource(
        ServerRequest $request,
        RouteTarget $target,
        ?string $id,
        LanguagePreferences $languages,
        Values $queryParameters,
        ErrorList $errors,
    ): FetchResource {
        $type = self::typeOf($target);
        $include = $this->includePaths($request, $type, null, $errors);
        $fields = $this->fieldsetsOf($request, $errors);

        $errors->throwIfAny();

        return new FetchResource(
            new Identifier($type, self::decoded($id)),
            $include,
            $fields,
            $languages,
            $queryParameters,
        );
    }

    /**
     * @param Values<mixed> $queryParameters
     *
     * @throws JsonApiException
     */
    private function fetchRelated(
        ServerRequest $request,
        RouteTarget $target,
        ?string $id,
        Negotiation $negotiation,
        LanguagePreferences $languages,
        Values $queryParameters,
        ErrorList $errors,
    ): FetchRelated {
        $type = self::typeOf($target);
        $relationship = $this->relationshipOf($target);
        $related = $relationship->relatedType;
        $include = $this->includePaths($request, $related, null, $errors);
        $fields = $this->fieldsetsOf($request, $errors);
        $reading = $relationship->cardinality === Cardinality::ToMany
            ? $this->collections->parse(
                $request->query,
                $related,
                $this->resources->relationshipPaginationPolicy($type, $relationship->name),
                $relationship->defaultSort,
                $negotiation->defaultPageStrategy(),
                $errors,
            )
            : null;

        $errors->throwIfAny();

        return new FetchRelated(
            new Identifier($type, self::decoded($id)),
            $relationship->name,
            $reading?->filter,
            $reading->sort ?? new Sort([]),
            $reading->page ?? PageRequest::pageNumber(),
            $include,
            $fields,
            $languages,
            $queryParameters,
        );
    }

    /**
     * the cursor conditions are the whole filter of the reading,
     * since the relationship route refuses `filter`,
     * and they are built on `relationshipReadingSort()`, since it reads no `sort` either
     *
     * @param Values<mixed> $queryParameters
     *
     * @throws JsonApiException
     */
    private function fetchRelationship(
        ServerRequest $request,
        RouteTarget $target,
        ?string $id,
        Negotiation $negotiation,
        LanguagePreferences $languages,
        Values $queryParameters,
        ErrorList $errors,
    ): FetchRelationship {
        $type = self::typeOf($target);
        $relationship = $this->relationshipOf($target);
        $collection = $relationship->cardinality === Cardinality::ToMany;
        $include = $this->includePaths($request, $type, $relationship->name, $errors);
        $fields = $this->fieldsetsOf($request, $errors);
        $page = $collection
            ? $this->pages->parse(
                ParameterFamily::Page->valueIn($request->query),
                $this->resources->relationshipPaginationPolicy($type, $relationship->name),
                $errors,
                $negotiation->defaultPageStrategy(),
            )
            : PageRequest::pageNumber();
        $conditions = $collection
            ? $this->collections->conditions(
                $page,
                $relationship->relatedType,
                $this->resources->relationshipReadingSort($type, $relationship->name),
                $errors,
            )
            : null;

        $errors->throwIfAny();

        return new FetchRelationship(
            new Identifier($type, self::decoded($id)),
            $relationship->name,
            $conditions,
            $page,
            $include,
            $fields,
            $languages,
            $queryParameters,
        );
    }

    /**
     * @throws JsonApiException
     */
    private function createResource(ServerRequest $request, PendingWrite $write, ErrorList $errors): CreateResource
    {
        $resource = $this->documents->parseCreateResource(
            $this->json->decode($request->body),
            Pointer::root(),
            [$write->type],
            $errors,
        );

        $errors->throwIfAny();

        if ($resource === null) {
            throw self::silent();
        }

        return new CreateResource(
            $resource->type,
            $resource->id,
            $resource->attributes,
            $resource->relationshipsWithIds(),
            $write->include,
            $write->fields,
            $write->languages,
            $write->queryParameters,
        );
    }

    /**
     * @throws JsonApiException
     */
    private function updateResource(ServerRequest $request, PendingWrite $write, ErrorList $errors): UpdateResource
    {
        $target = self::targetOf($write);
        $resource = $this->documents->parseUpdateResource(
            $this->json->decode($request->body),
            Pointer::root(),
            $target->type,
            $target->id,
            null,
            $errors,
        );

        $errors->throwIfAny();

        if ($resource === null) {
            throw self::silent();
        }

        return new UpdateResource(
            $target,
            $resource->attributes,
            $resource->relationshipsWithIds(),
            null,
            $write->include,
            $write->fields,
            $write->languages,
            $write->queryParameters,
        );
    }

    /**
     * @param Values<mixed> $queryParameters
     *
     * @throws JsonApiException
     */
    private function deleteResource(
        RouteTarget $target,
        ?string $id,
        LanguagePreferences $languages,
        Values $queryParameters,
        ErrorList $errors,
    ): DeleteResource {
        $errors->throwIfAny();

        return new DeleteResource(
            new Identifier(self::typeOf($target), self::decoded($id)),
            null,
            $languages,
            $queryParameters,
        );
    }

    /**
     * @throws JsonApiException
     * @throws OutOfBoundsException when the relationship of the write is not declared
     */
    private function relationshipWrite(ServerRequest $request, PendingWrite $write, ErrorList $errors): Operation
    {
        $parent = self::targetOf($write);
        $relationship = $this->resources->relationshipOf(
            $write->type,
            $write->relationship ?? throw new LogicException(sprintf(
                'a %s write names its relationship, which Operation\PendingWrite holds',
                $write->kind->value,
            )),
        );
        $document = $this->json->decode($request->body);
        $pointer = Pointer::root();
        $kind = $write->kind;

        $linkage = match ($kind) {
            OperationKind::ReplaceRelationship => $this->documents->parseReplaceRelationship(
                $document,
                $pointer,
                $relationship,
                $errors,
            ),
            OperationKind::AddToRelationship => $this->documents->parseAddToRelationship(
                $document,
                $pointer,
                $relationship,
                $errors,
            ),
            default => $this->documents->parseRemoveFromRelationship($document, $pointer, $relationship, $errors),
        };

        $errors->throwIfAny();

        if ($linkage === null) {
            throw self::silent();
        }

        $members = $linkage->toLinkage();

        return match ($kind) {
            OperationKind::ReplaceRelationship => new ReplaceRelationship(
                $parent,
                $relationship->name,
                $members,
                null,
                $write->include,
                $write->fields,
                $write->languages,
                $write->queryParameters,
            ),
            OperationKind::AddToRelationship => new AddToRelationship(
                $parent,
                $relationship->name,
                $members,
                null,
                $write->include,
                $write->fields,
                $write->languages,
                $write->queryParameters,
            ),
            default => new RemoveFromRelationship(
                $parent,
                $relationship->name,
                $members,
                null,
                $write->include,
                $write->fields,
                $write->languages,
                $write->queryParameters,
            ),
        };
    }

    /**
     * @return array{?IncludePaths, SparseFieldsets}
     */
    private function writeFeatures(
        OperationKind $kind,
        ServerRequest $request,
        RouteTarget $target,
        ErrorList $errors,
    ): array {
        $type = self::typeOf($target);
        $relationship = $kind->targetsRelationship() ? $this->relationshipOf($target)->name : null;

        return [
            $this->includePaths($request, $type, $relationship, $errors),
            $this->fieldsetsOf($request, $errors),
        ];
    }

    private function includePaths(
        ServerRequest $request,
        string $type,
        ?string $relationship,
        ErrorList $errors,
    ): ?IncludePaths {
        return $this->includes->parse(
            ParameterFamily::Include->valueIn($request->query),
            $type,
            $relationship,
            $errors,
        );
    }

    private function fieldsetsOf(ServerRequest $request, ErrorList $errors): SparseFieldsets
    {
        return $this->fieldsets->parse(ParameterFamily::Fields->valueIn($request->query), $errors);
    }

    /**
     * @throws InvalidArgumentException for an atomic route,
     *                                  or a method no route of its kind registers
     */
    private static function kindOf(ServerRequest $request, RouteTarget $target): OperationKind
    {
        if ($target->kind === RouteKind::Atomic) {
            throw new InvalidArgumentException(
                'an atomic route carries the kinds of its operations in its document, and'
                . ' Parsing\AtomicParser reads it',
            );
        }

        return OperationKind::onRoute($target->kind, $request->method) ?? throw new InvalidArgumentException(sprintf(
            'the %s route carries no %s handler, and a route responds only to the methods of its operation kinds',
            $target->kind->value,
            Printable::of($request->method),
        ));
    }

    /**
     * every route but the atomic one names a type, and `kindOf()` refuses the atomic route
     */
    private static function typeOf(RouteTarget $target): string
    {
        if ($target->type === null) {
            throw new LogicException('the ' . $target->kind->value . ' route addresses no type');
        }

        return $target->type;
    }

    /**
     * a text the id codec refuses addresses no resource of the type: the 404 of a missing target.
     * it is thrown before the query string gets its error list, without the codec's violations:
     * a route segment has no pointer or parameter name to carry them
     *
     * @throws JsonApiException     404 when the codec refuses the id or decodes it to `''`
     * @throws LogicException       when the route addresses no id, which the caller checks
     * @throws OutOfBoundsException when the type of the route is not declared
     */
    private function routeId(RouteTarget $target): string
    {
        $type = self::typeOf($target);

        if ($target->id === null) {
            throw new LogicException('the ' . $target->kind->value . ' route addresses no id');
        }

        try {
            $id = $this->resources->get($type)->id->codec->decode($target->id, Pointer::root());
        } catch (DecodeException $refusal) {
            throw self::notFound($refusal);
        }

        if ($id === '') {
            throw self::notFound();
        }

        return $id;
    }

    /**
     * `parse()` decodes the route id before it dispatches on the kind and carries it as nullable;
     * the kinds that address an id narrow it here
     */
    private static function decoded(?string $id): string
    {
        if ($id === null) {
            throw new LogicException(
                'this kind of operation addresses an id, and parse() decodes the id of the route before it'
                . ' dispatches on the kind',
            );
        }

        return $id;
    }

    /**
     * the target of an update, or the parent of a relationship write
     */
    private static function targetOf(PendingWrite $write): Identifier
    {
        return $write->target ?? throw new LogicException(sprintf(
            'a %s write acts on an existing resource, which Operation\PendingWrite names',
            $write->kind->value,
        ));
    }

    private static function notFound(?DecodeException $refusal = null): NotFoundException
    {
        return new NotFoundException([new ErrorDescription(ErrorCode::NotFound)], $refusal);
    }

    /**
     * @throws LogicException       when the route addresses no type or no relationship
     * @throws OutOfBoundsException when the type or the relationship is not declared
     */
    private function relationshipOf(RouteTarget $target): RelationshipMetadata
    {
        $type = self::typeOf($target);
        $name = $target->relationship;

        if ($name === null) {
            throw new LogicException('the ' . $target->kind->value . ' route addresses no relationship');
        }

        $declared = $this->resources->get($type)->relationships[$name] ?? null;

        if ($declared === null) {
            throw new OutOfBoundsException(sprintf(
                'type "%s" declares no relationship "%s"',
                $type,
                Printable::of($name),
            ));
        }

        return $declared;
    }

    /**
     * a parser returned null without collecting a problem, which `DocumentParser` never does
     */
    private static function silent(): LogicException
    {
        return new LogicException(
            'Parsing\DocumentParser refused the content of the request and named no problem, and it names one for'
            . ' every refusal',
        );
    }
}
