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

use Closure;
use LogicException;
use Polunich\WpJsonApi\Codec\DecodeException;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Document\Member;
use Polunich\WpJsonApi\Document\OperationCode;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorList;
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Error\StatusSelector;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Metadata\RelationshipMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Metadata\RouteQuery;
use Polunich\WpJsonApi\Operation\AddToRelationship;
use Polunich\WpJsonApi\Operation\AtomicOperation;
use Polunich\WpJsonApi\Operation\AtomicOperations;
use Polunich\WpJsonApi\Operation\CreateResource;
use Polunich\WpJsonApi\Operation\DeleteResource;
use Polunich\WpJsonApi\Operation\Operation;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Operation\RemoveFromRelationship;
use Polunich\WpJsonApi\Operation\ReplaceRelationship;
use Polunich\WpJsonApi\Operation\UpdateResource;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\ParameterFamily;
use stdClass;

/**
 * reads the document of an atomic route into `Operation\AtomicOperation`s, in document order.
 * each builds its operation from a closure:
 * the id behind a `lid` exists only once the earlier create that declares it has run.
 *
 * `checkQuery()` and `parse()` are split so `If-Match` is evaluated before the document is read.
 *
 * - `href` is 400: `ref` names every target an `href` can
 * - a `lid` must be declared by an earlier create: `unknown_lid` otherwise,
 *   and `duplicate_lid` when a create declares it again for its type
 * - it checks only that each type and relationship read is declared
 *   and that the route takes each target type;
 *   whether the declarations enable an operation is checked after reading
 * - an operation carries the fieldsets of the request, but no include path,
 *   since atomic results carry no `included`, and no precondition or query parameter,
 *   since an operation object has no member for either
 *
 * @internal
 */
final readonly class AtomicParser
{
    public function __construct(
        private ResourceRegistry $resources,
        private QueryParameterGuard $guard,
        private FieldsetParser $fieldsets,
        private JsonBodyDecoder $json,
        private DocumentParser $documents,
        private StatusSelector $statuses,
    ) {}

    /**
     * @throws JsonApiException every problem of the query string
     */
    public function checkQuery(ServerRequest $request): SparseFieldsets
    {
        $errors = new ErrorList($this->statuses);

        $this->guard->check($request->query, RouteQuery::ofAtomicRoute(), $errors);
        $fields = $this->fieldsets->parse(ParameterFamily::Fields->valueIn($request->query), $errors);

        $errors->throwIfAny();

        return $fields;
    }

    /**
     * @param SparseFieldsets        $fields what `checkQuery()` returned
     * @param non-empty-list<string> $types  the types an operation of the route may target
     *
     * @throws JsonApiException every problem of the document
     */
    public function parse(
        ServerRequest $request,
        SparseFieldsets $fields,
        LanguagePreferences $languages,
        array $types,
    ): AtomicOperations {
        $errors = new ErrorList($this->statuses);

        $objects = $this->objects($this->json->decode($request->body), $errors);

        $errors->throwIfAny();

        if ($objects === null) {
            throw self::silent();
        }

        $operations = [];
        $declaredLids = [];

        foreach ($objects as $position => $object) {
            $pointer = Pointer::root()->descend(Member::ATOMIC_OPERATIONS)->descend((string) $position);
            $operation = $this->operation($object, $pointer, $fields, $languages, $types, $declaredLids, $errors);

            if ($operation === null) {
                continue;
            }

            if ($this->refuseRequiredQueryParameter($operation, $pointer, $errors)) {
                continue;
            }

            if ($operation->lid !== null) {
                if (isset($declaredLids[$operation->type][$operation->lid])) {
                    JsonDocument::refuse(
                        $errors,
                        JsonDocument::BAD_REQUEST,
                        ErrorCode::DuplicateLid,
                        ['lid' => $operation->lid, 'type' => $operation->type],
                        $pointer->descend(Member::DATA)->descend(Member::LID),
                    );

                    continue;
                }

                $declaredLids[$operation->type][$operation->lid] = true;
            }

            $operations[] = $operation;
        }

        $errors->throwIfAny();

        return new AtomicOperations($operations);
    }

    /**
     * @return ?non-empty-list<mixed>
     */
    private function objects(mixed $document, ErrorList $errors): ?array
    {
        $root = Pointer::root();

        if (!$document instanceof stdClass || !JsonDocument::has($document, Member::ATOMIC_OPERATIONS)) {
            JsonDocument::malformed($errors, $root);

            return null;
        }

        $operations = JsonDocument::member($document, Member::ATOMIC_OPERATIONS);

        if (!is_array($operations) || !array_is_list($operations) || $operations === []) {
            JsonDocument::malformed($errors, $root->descend(Member::ATOMIC_OPERATIONS));

            return null;
        }

        return $operations;
    }

    /**
     * @param list<string>                             $types
     * @param array<array-key, array<array-key, true>> $declaredLids `lid`s declared so far, by type
     */
    private function operation(
        mixed $object,
        Pointer $pointer,
        SparseFieldsets $fields,
        LanguagePreferences $languages,
        array $types,
        array $declaredLids,
        ErrorList $errors,
    ): ?AtomicOperation {
        if (!$object instanceof stdClass) {
            JsonDocument::malformed($errors, $pointer);

            return null;
        }

        if (JsonDocument::has($object, Member::HREF)) {
            JsonDocument::malformed($errors, $pointer->descend(Member::HREF));

            return null;
        }

        $code = self::code($object, $pointer, $errors);

        if ($code === null) {
            return null;
        }

        if (!JsonDocument::has($object, Member::REF)) {
            $reference = null;
        } else {
            $reference = $this->reference(
                JsonDocument::member($object, Member::REF),
                $pointer->descend(Member::REF),
                $code,
                $types,
                $declaredLids,
                $errors,
            );

            if ($reference === null) {
                return null;
            }
        }

        $kind = self::kindOf($code, $reference, $pointer, $errors);

        if ($kind === null) {
            return null;
        }

        return match ($kind) {
            OperationKind::CreateResource => $this->create(
                $object,
                $pointer,
                $fields,
                $languages,
                $types,
                $declaredLids,
                $errors,
            ),
            OperationKind::UpdateResource => $this->update(
                $object,
                $pointer,
                $reference,
                $fields,
                $languages,
                $types,
                $declaredLids,
                $errors,
            ),
            OperationKind::DeleteResource => $this->delete($reference, $languages),
            default => $this->relationshipWrite(
                $kind,
                $object,
                $pointer,
                $reference,
                $fields,
                $languages,
                $declaredLids,
                $errors,
            ),
        };
    }

    private static function code(stdClass $object, Pointer $pointer, ErrorList $errors): ?OperationCode
    {
        if (!JsonDocument::has($object, Member::OP)) {
            JsonDocument::malformed($errors, $pointer);

            return null;
        }

        $written = JsonDocument::member($object, Member::OP);
        $code = is_string($written) ? OperationCode::tryFrom($written) : null;

        if ($code === null) {
            JsonDocument::malformed($errors, $pointer->descend(Member::OP));
        }

        return $code;
    }

    /**
     * @param ?array{LocalIdentifier, ?RelationshipMetadata} $reference null without a `ref`
     */
    private static function kindOf(
        OperationCode $code,
        ?array $reference,
        Pointer $pointer,
        ErrorList $errors,
    ): ?OperationKind {
        $relationship = $reference[1] ?? null;

        if ($code === OperationCode::Add) {
            if ($reference === null) {
                return OperationKind::CreateResource;
            }

            if ($relationship === null) {
                // `add` on a resource ref: a create targets no existing resource
                JsonDocument::malformed($errors, $pointer->descend(Member::REF));

                return null;
            }

            return OperationKind::AddToRelationship;
        }

        if ($code === OperationCode::Update) {
            return $relationship === null ? OperationKind::UpdateResource : OperationKind::ReplaceRelationship;
        }

        if ($reference === null) {
            // ext-atomic: "An operation that deletes a resource MUST target that resource through
            // the operation's ref or href members"
            JsonDocument::malformed($errors, $pointer);

            return null;
        }

        return $relationship === null ? OperationKind::DeleteResource : OperationKind::RemoveFromRelationship;
    }

    /**
     * an undeclared relationship is refused as one that declares no update is:
     * 403 `relationship_update_not_supported`, a code that names the relationship,
     * which `operation_not_supported` cannot
     *
     * @param OperationCode                            $code         for the kind a refusal names
     * @param list<string>                             $types
     * @param array<array-key, array<array-key, true>> $declaredLids
     *
     * @return ?array{LocalIdentifier, ?RelationshipMetadata}
     */
    private function reference(
        mixed $ref,
        Pointer $pointer,
        OperationCode $code,
        array $types,
        array $declaredLids,
        ErrorList $errors,
    ): ?array {
        if (!$ref instanceof stdClass) {
            JsonDocument::malformed($errors, $pointer);

            return null;
        }

        $target = $this->localIdentifier($ref, $pointer, $types, $declaredLids, $errors);

        if ($target === null) {
            return null;
        }

        if (!JsonDocument::has($ref, Member::RELATIONSHIP)) {
            return [$target, null];
        }

        $name = JsonDocument::member($ref, Member::RELATIONSHIP);

        if (!is_string($name) || $name === '') {
            JsonDocument::malformed($errors, $pointer->descend(Member::RELATIONSHIP));

            return null;
        }

        $relationship = $this->resources->get($target->type)->relationships[$name] ?? null;

        if ($relationship === null) {
            JsonDocument::refuse(
                $errors,
                JsonDocument::FORBIDDEN,
                ErrorCode::RelationshipUpdateNotSupported,
                [
                    'relationship' => $name,
                    'type' => $target->type,
                    'operation' => self::relationshipKind($code)->value,
                ],
                $pointer->descend(Member::RELATIONSHIP),
            );

            return null;
        }

        return [$target, $relationship];
    }

    private static function relationshipKind(OperationCode $code): OperationKind
    {
        return match ($code) {
            OperationCode::Add => OperationKind::AddToRelationship,
            OperationCode::Update => OperationKind::ReplaceRelationship,
            OperationCode::Remove => OperationKind::RemoveFromRelationship,
        };
    }

    /**
     * @param list<string>                             $types
     * @param array<array-key, array<array-key, true>> $declaredLids
     */
    private function localIdentifier(
        stdClass $object,
        Pointer $pointer,
        array $types,
        array $declaredLids,
        ErrorList $errors,
    ): ?LocalIdentifier {
        $type = $this->declaredType($object, $pointer, $types, $errors);
        $named = JsonDocument::has($object, Member::ID) ? Member::ID : Member::LID;

        if ($type === null) {
            return null;
        }

        if (JsonDocument::has($object, Member::ID) === JsonDocument::has($object, Member::LID)) {
            // a target is named by exactly one of `id` and `lid`
            JsonDocument::malformed($errors, $pointer);

            return null;
        }

        $written = JsonDocument::member($object, $named);

        if (!is_string($written) || $written === '') {
            JsonDocument::malformed($errors, $pointer->descend($named));

            return null;
        }

        if ($named === Member::ID) {
            // an id its type's codec refuses addresses no resource: 404, not a value's 422;
            // it is collected like any other problem, and the statuses merge
            $idPointer = $pointer->descend(Member::ID);

            try {
                $id = $this->resources->get($type)->id->codec->decode($written, $idPointer);
            } catch (DecodeException) {
                $id = '';
            }

            if ($id === '') {
                JsonDocument::refuse($errors, JsonDocument::NOT_FOUND, ErrorCode::NotFound, [], $idPointer);

                return null;
            }

            return LocalIdentifier::ofId($type, $id);
        }

        if (!isset($declaredLids[$type][$written])) {
            JsonDocument::refuse(
                $errors,
                JsonDocument::BAD_REQUEST,
                ErrorCode::UnknownLid,
                ['lid' => $written, 'type' => $type],
                $pointer->descend(Member::LID),
            );

            return null;
        }

        return LocalIdentifier::ofLid($type, $written);
    }

    /**
     * an undeclared type, or one the route does not take, is 409 `type_mismatch`:
     * an atomic request is a POST, and the spec's response to a type outside the collections
     * of the endpoint is 409.
     * it is refused while reading, since later checks read the declaration of the type
     *
     * @param list<string> $types
     */
    private function declaredType(stdClass $object, Pointer $pointer, array $types, ErrorList $errors): ?string
    {
        if (!JsonDocument::has($object, Member::TYPE)) {
            JsonDocument::malformed($errors, $pointer);

            return null;
        }

        $type = JsonDocument::member($object, Member::TYPE);

        if (!is_string($type)) {
            JsonDocument::malformed($errors, $pointer->descend(Member::TYPE));

            return null;
        }

        if (!in_array($type, $types, true) || !$this->resources->has($type)) {
            JsonDocument::refuse(
                $errors,
                JsonDocument::CONFLICT,
                ErrorCode::TypeMismatch,
                ['type' => $type],
                $pointer->descend(Member::TYPE),
            );

            return null;
        }

        return $type;
    }

    /**
     * @param list<string>                             $types
     * @param array<array-key, array<array-key, true>> $declaredLids
     */
    private function create(
        stdClass $object,
        Pointer $pointer,
        SparseFieldsets $fields,
        LanguagePreferences $languages,
        array $types,
        array $declaredLids,
        ErrorList $errors,
    ): ?AtomicOperation {
        $resource = $this->documents->parseCreateResource($object, $pointer, $types, $errors, $declaredLids);

        if ($resource === null) {
            return null;
        }

        return new AtomicOperation(
            OperationKind::CreateResource,
            $resource->type,
            null,
            $resource->lid,
            static function (Closure $idOfLid) use ($resource, $fields, $languages): Operation {
                return new CreateResource(
                    $resource->type,
                    $resource->id,
                    $resource->attributes,
                    $resource->relationshipsWithIds(self::typedIdOfLid($idOfLid)),
                    null,
                    $fields,
                    $languages,
                    new Values([]),
                );
            },
        );
    }

    /**
     * without a `ref`, the resource object names the target, as the example of the extension does
     *
     * @param ?array{LocalIdentifier, ?RelationshipMetadata} $reference
     * @param list<string>                                   $types
     * @param array<array-key, array<array-key, true>>       $declaredLids
     */
    private function update(
        stdClass $object,
        Pointer $pointer,
        ?array $reference,
        SparseFieldsets $fields,
        LanguagePreferences $languages,
        array $types,
        array $declaredLids,
        ErrorList $errors,
    ): ?AtomicOperation {
        $target = $reference[0] ?? $this->targetOfData($object, $pointer, $types, $declaredLids, $errors);

        if ($target === null) {
            return null;
        }

        $resource = $this->documents->parseUpdateResource(
            $object,
            $pointer,
            $target->type,
            $target->id(),
            $target->lid(),
            $errors,
            $declaredLids,
        );

        if ($resource === null) {
            return null;
        }

        return new AtomicOperation(
            OperationKind::UpdateResource,
            $target->type,
            null,
            null,
            static function (Closure $idOfLid) use ($target, $resource, $fields, $languages): Operation {
                return new UpdateResource(
                    $target->toIdentifier(self::typedIdOfLid($idOfLid)),
                    $resource->attributes,
                    $resource->relationshipsWithIds(self::typedIdOfLid($idOfLid)),
                    null,
                    null,
                    $fields,
                    $languages,
                    new Values([]),
                );
            },
        );
    }

    /**
     * @param list<string>                             $types
     * @param array<array-key, array<array-key, true>> $declaredLids
     */
    private function targetOfData(
        stdClass $object,
        Pointer $pointer,
        array $types,
        array $declaredLids,
        ErrorList $errors,
    ): ?LocalIdentifier {
        if (!JsonDocument::has($object, Member::DATA)) {
            JsonDocument::malformed($errors, $pointer);

            return null;
        }

        $data = JsonDocument::member($object, Member::DATA);

        if (!$data instanceof stdClass) {
            JsonDocument::malformed($errors, $pointer->descend(Member::DATA));

            return null;
        }

        return $this->localIdentifier($data, $pointer->descend(Member::DATA), $types, $declaredLids, $errors);
    }

    /**
     * @param ?array{LocalIdentifier, ?RelationshipMetadata} $reference a delete always has one
     */
    private function delete(?array $reference, LanguagePreferences $languages): AtomicOperation
    {
        if ($reference === null) {
            throw self::silent();
        }

        $target = $reference[0];

        return new AtomicOperation(
            OperationKind::DeleteResource,
            $target->type,
            null,
            null,
            static function (Closure $idOfLid) use ($target, $languages): Operation {
                return new DeleteResource(
                    $target->toIdentifier(self::typedIdOfLid($idOfLid)),
                    null,
                    $languages,
                    new Values([]),
                );
            },
        );
    }

    /**
     * @param ?array{LocalIdentifier, ?RelationshipMetadata} $reference
     * @param array<array-key, array<array-key, true>>       $declaredLids
     */
    private function relationshipWrite(
        OperationKind $kind,
        stdClass $object,
        Pointer $pointer,
        ?array $reference,
        SparseFieldsets $fields,
        LanguagePreferences $languages,
        array $declaredLids,
        ErrorList $errors,
    ): ?AtomicOperation {
        $relationship = $reference[1] ?? null;

        if ($reference === null || $relationship === null) {
            throw self::silent();
        }

        $parent = $reference[0];

        $linkage = match ($kind) {
            OperationKind::ReplaceRelationship => $this->documents->parseReplaceRelationship(
                $object,
                $pointer,
                $relationship,
                $errors,
                $declaredLids,
            ),
            OperationKind::AddToRelationship => $this->documents->parseAddToRelationship(
                $object,
                $pointer,
                $relationship,
                $errors,
                $declaredLids,
            ),
            default => $this->documents->parseRemoveFromRelationship(
                $object,
                $pointer,
                $relationship,
                $errors,
                $declaredLids,
            ),
        };

        if ($linkage === null) {
            return null;
        }

        $name = $relationship->name;

        return new AtomicOperation(
            $kind,
            $parent->type,
            $name,
            null,
            static function (Closure $idOfLid) use ($kind, $parent, $name, $linkage, $fields, $languages): Operation {
                return match ($kind) {
                    OperationKind::ReplaceRelationship => new ReplaceRelationship(
                        $parent->toIdentifier(self::typedIdOfLid($idOfLid)),
                        $name,
                        $linkage->toLinkage(self::typedIdOfLid($idOfLid)),
                        null,
                        null,
                        $fields,
                        $languages,
                        new Values([]),
                    ),
                    OperationKind::AddToRelationship => new AddToRelationship(
                        $parent->toIdentifier(self::typedIdOfLid($idOfLid)),
                        $name,
                        $linkage->toLinkage(self::typedIdOfLid($idOfLid)),
                        null,
                        null,
                        $fields,
                        $languages,
                        new Values([]),
                    ),
                    default => new RemoveFromRelationship(
                        $parent->toIdentifier(self::typedIdOfLid($idOfLid)),
                        $name,
                        $linkage->toLinkage(self::typedIdOfLid($idOfLid)),
                        null,
                        null,
                        $fields,
                        $languages,
                        new Values([]),
                    ),
                };
            },
        );
    }

    /**
     * an operation whose route requires a query parameter cannot be performed:
     * it gets 400 `missing_query_parameter` at the operation,
     * as the library responds to a required precondition with 428 there
     *
     * @return bool whether the operation was refused
     */
    private function refuseRequiredQueryParameter(AtomicOperation $operation, Pointer $pointer, ErrorList $errors): bool
    {
        $declared = $this->resources->queryParametersOf($operation->kind, $operation->type, $operation->relationship);

        foreach ($declared as $parameter) {
            if ($parameter->required) {
                JsonDocument::refuse(
                    $errors,
                    JsonDocument::BAD_REQUEST,
                    ErrorCode::MissingQueryParameter,
                    ['name' => $parameter->name],
                    $pointer,
                );

                return true;
            }
        }

        return false;
    }

    /**
     * narrows the lookup `Operation\AtomicOperation` hands over
     * to the signature `LocalIdentifier::toIdentifier()` takes
     *
     * @return Closure(string $type, string $lid): string
     */
    private static function typedIdOfLid(Closure $idOfLid): Closure
    {
        /** @var Closure(string $type, string $lid): string $idOfLid */
        return $idOfLid;
    }

    /**
     * a step returned null without collecting a problem, which no step here does
     */
    private static function silent(): LogicException
    {
        return new LogicException(
            'an operation of the atomic document was refused without a problem being named, and every refusal'
            . ' names one',
        );
    }
}
