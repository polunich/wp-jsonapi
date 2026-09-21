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
use Polunich\WpJsonApi\Codec\DecodeException;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Document\Member;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorList;
use Polunich\WpJsonApi\Error\UnprocessableContentException;
use Polunich\WpJsonApi\Metadata\Cardinality;
use Polunich\WpJsonApi\Metadata\ClientIdPolicy;
use Polunich\WpJsonApi\Metadata\RelationshipMetadata;
use Polunich\WpJsonApi\Metadata\RelationshipUpdate;
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Text\Printable;
use stdClass;

/**
 * every public method takes:
 * - the object that carries `data`: a decoded body, or an operation object of an atomic request
 * - its pointer, the root or `/atomic:operations/<n>`, so every pointer written here is absolute
 * - but for `parseLinkage()`, `$declaredLids`: the `lid`s declared so far by type;
 *   a single request declares none
 *
 * every problem goes into the error list, and a method that found one returns null.
 * where an id stands decides the response to a value its codec refuses:
 * the id of a create or a linkage identifier is a written value: 422 with the codec's violations;
 * the id of an update names the target: 409 `id_mismatch`.
 * no record is loaded: an identifier of a missing resource is refused when the write runs
 *
 * @internal
 */
final readonly class DocumentParser
{
    /**
     * an @-member is no field: it is skipped, never refused as unknown
     */
    private const AT = '@';

    public function __construct(private ResourceRegistry $resources) {}

    /**
     * @param list<string>                             $types        the types the place accepts
     * @param array<array-key, array<array-key, true>> $declaredLids
     */
    public function parseCreateResource(
        mixed $document,
        Pointer $pointer,
        array $types,
        ErrorList $errors,
        array $declaredLids = [],
    ): ?ResourceBody {
        $data = $this->resourceObject($document, $pointer, $errors);

        if ($data === null) {
            return null;
        }

        $dataPointer = $pointer->descend(Member::DATA);
        $resource = $this->declaredType($data, $dataPointer, $types, $errors);

        if ($resource === null) {
            return null;
        }

        $id = null;
        $lid = null;
        $named = true;

        if (JsonDocument::has($data, Member::ID)) {
            $id = $this->clientId(
                JsonDocument::member($data, Member::ID),
                $dataPointer->descend(Member::ID),
                $resource,
                $errors,
            );
            $named = $id !== null;
        } elseif ($resource->clientIdPolicy === ClientIdPolicy::Required) {
            JsonDocument::refuse(
                $errors,
                JsonDocument::UNPROCESSABLE_CONTENT,
                ErrorCode::MissingField,
                ['type' => $resource->type, 'field' => Member::ID],
                $dataPointer,
            );
            $named = false;
        }

        if (JsonDocument::has($data, Member::LID)) {
            $lid = self::idOrLid(JsonDocument::member($data, Member::LID), $dataPointer->descend(Member::LID), $errors);

            if ($lid !== null && $id !== null) {
                JsonDocument::refuse(
                    $errors,
                    JsonDocument::BAD_REQUEST,
                    ErrorCode::LidWithId,
                    ['id' => $id, 'lid' => $lid],
                    $dataPointer->descend(Member::LID),
                );
                $named = false;
            }

            $named = $lid !== null && $named;
        }

        $own = $lid === null ? null : LocalIdentifier::ofLid($resource->type, $lid);
        $attributes = $this->attributes($data, $dataPointer, $resource, true, $errors);
        $relationships = $this->relationships($data, $dataPointer, $resource, true, $errors, $declaredLids, $own);
        $complete = self::checkRequiredFields($data, $dataPointer, $resource, $errors);

        if (!$named || !$complete || $attributes === null || $relationships === null) {
            return null;
        }

        return new ResourceBody($resource->type, $id, $lid, $attributes, $relationships);
    }

    /**
     * the resource object names the target by the member the target is named by:
     * `id`, or `lid` for an atomic target that an earlier operation creates
     *
     * @param array<array-key, array<array-key, true>> $declaredLids
     *
     * @throws InvalidArgumentException when both or neither of `$id` and `$lid` are given
     */
    public function parseUpdateResource(
        mixed $document,
        Pointer $pointer,
        string $type,
        ?string $id,
        ?string $lid,
        ErrorList $errors,
        array $declaredLids = [],
    ): ?ResourceBody {
        if (($id === null) === ($lid === null)) {
            throw new InvalidArgumentException(sprintf(
                'an update of type "%s" targets its resource by an id or by a lid, and names exactly'
                . ' one of the two',
                Printable::of($type),
            ));
        }

        $data = $this->resourceObject($document, $pointer, $errors);

        if ($data === null) {
            return null;
        }

        $dataPointer = $pointer->descend(Member::DATA);
        $resource = $this->declaredType($data, $dataPointer, [$type], $errors);

        if ($resource === null) {
            return null;
        }

        $named = self::targets($data, $dataPointer, $id, $lid, $resource, $errors);
        $attributes = $this->attributes($data, $dataPointer, $resource, false, $errors);
        $relationships = $this->relationships($data, $dataPointer, $resource, false, $errors, $declaredLids, null);

        if (!$named || $attributes === null || $relationships === null) {
            return null;
        }

        return new ResourceBody($resource->type, $id, $lid, $attributes, $relationships);
    }

    /**
     * whether the relationship accepts a replacement, an addition or a removal
     * is checked before the body is read
     *
     * @param array<array-key, array<array-key, true>> $declaredLids
     */
    public function parseReplaceRelationship(
        mixed $document,
        Pointer $pointer,
        RelationshipMetadata $relationship,
        ErrorList $errors,
        array $declaredLids = [],
    ): ?LocalLinkage {
        if (!$document instanceof stdClass || !JsonDocument::has($document, Member::DATA)) {
            JsonDocument::malformed($errors, $pointer);

            return null;
        }

        return $this->linkage(
            JsonDocument::member($document, Member::DATA),
            $pointer->descend(Member::DATA),
            $relationship->relatedType,
            $relationship->cardinality,
            $relationship->nullable,
            $errors,
            $declaredLids,
            null,
        );
    }

    /**
     * the linkage an endpoint reads as its body:
     * one identifier of the type or null, or a list of them
     */
    public function parseLinkage(
        mixed $document,
        Pointer $pointer,
        string $type,
        Cardinality $cardinality,
        ErrorList $errors,
    ): ?LocalLinkage {
        if (!$document instanceof stdClass || !JsonDocument::has($document, Member::DATA)) {
            JsonDocument::malformed($errors, $pointer);

            return null;
        }

        return $this->linkage(
            JsonDocument::member($document, Member::DATA),
            $pointer->descend(Member::DATA),
            $type,
            $cardinality,
            true,
            $errors,
            [],
            null,
        );
    }

    /**
     * @param array<array-key, array<array-key, true>> $declaredLids
     */
    public function parseAddToRelationship(
        mixed $document,
        Pointer $pointer,
        RelationshipMetadata $relationship,
        ErrorList $errors,
        array $declaredLids = [],
    ): ?LocalLinkage {
        return $this->parseMembers($document, $pointer, $relationship, $errors, $declaredLids);
    }

    /**
     * @param array<array-key, array<array-key, true>> $declaredLids
     */
    public function parseRemoveFromRelationship(
        mixed $document,
        Pointer $pointer,
        RelationshipMetadata $relationship,
        ErrorList $errors,
        array $declaredLids = [],
    ): ?LocalLinkage {
        return $this->parseMembers($document, $pointer, $relationship, $errors, $declaredLids);
    }

    /**
     * an addition or a removal writes an array of identifiers whatever the cardinality
     *
     * @param array<array-key, array<array-key, true>> $declaredLids
     */
    private function parseMembers(
        mixed $document,
        Pointer $pointer,
        RelationshipMetadata $relationship,
        ErrorList $errors,
        array $declaredLids,
    ): ?LocalLinkage {
        if (!$document instanceof stdClass || !JsonDocument::has($document, Member::DATA)) {
            JsonDocument::malformed($errors, $pointer);

            return null;
        }

        return $this->members(
            JsonDocument::member($document, Member::DATA),
            $pointer->descend(Member::DATA),
            $relationship->relatedType,
            $errors,
            $declaredLids,
            null,
        );
    }

    private function resourceObject(mixed $document, Pointer $pointer, ErrorList $errors): ?stdClass
    {
        if (!$document instanceof stdClass || !JsonDocument::has($document, Member::DATA)) {
            JsonDocument::malformed($errors, $pointer);

            return null;
        }

        $data = JsonDocument::member($document, Member::DATA);

        if (!$data instanceof stdClass) {
            JsonDocument::malformed($errors, $pointer->descend(Member::DATA));

            return null;
        }

        return $data;
    }

    /**
     * a type the place does not accept, or one the API does not declare, is 409 `type_mismatch`,
     * the spec's response to a type outside the endpoint's collection
     *
     * @param list<string> $types
     */
    private function declaredType(stdClass $data, Pointer $pointer, array $types, ErrorList $errors): ?ResourceMetadata
    {
        if (!JsonDocument::has($data, Member::TYPE)) {
            JsonDocument::malformed($errors, $pointer);

            return null;
        }

        $written = JsonDocument::member($data, Member::TYPE);

        if (!is_string($written)) {
            JsonDocument::malformed($errors, $pointer->descend(Member::TYPE));

            return null;
        }

        if (!in_array($written, $types, true) || !$this->resources->has($written)) {
            JsonDocument::refuse(
                $errors,
                JsonDocument::CONFLICT,
                ErrorCode::TypeMismatch,
                ['type' => $written],
                $pointer->descend(Member::TYPE),
            );

            return null;
        }

        return $this->resources->get($written);
    }

    /**
     * a type that forbids client ids responds to any id with 403, as the spec asks
     */
    private function clientId(mixed $written, Pointer $pointer, ResourceMetadata $resource, ErrorList $errors): ?string
    {
        if ($resource->clientIdPolicy === ClientIdPolicy::Forbidden) {
            JsonDocument::refuse(
                $errors,
                JsonDocument::FORBIDDEN,
                ErrorCode::ClientIdNotSupported,
                ['type' => $resource->type],
                $pointer,
            );

            return null;
        }

        if (!is_string($written)) {
            JsonDocument::malformed($errors, $pointer);

            return null;
        }

        try {
            $id = $resource->id->codec->decode($written, $pointer);
        } catch (DecodeException $exception) {
            self::violations($errors, $exception);

            return null;
        }

        if ($id === '') {
            self::invalidValue($errors, $pointer);

            return null;
        }

        return $id;
    }

    /**
     * an empty id or `lid` is a well-formed string that names no resource: 422, not 400
     */
    private static function idOrLid(mixed $written, Pointer $pointer, ErrorList $errors): ?string
    {
        if (!is_string($written)) {
            JsonDocument::malformed($errors, $pointer);

            return null;
        }

        if ($written === '') {
            self::invalidValue($errors, $pointer);

            return null;
        }

        return $written;
    }

    /**
     * another id is 409 `id_mismatch`; another `lid` is the generic 409 `conflict`,
     * since the spec's MUST covers ids and `id_mismatch` words an id.
     * `id_mismatch` names the id as written beside the target as decoded.
     * both members at once are the generic 400 of a malformed document:
     * a `lid` stands only where the id is omitted, and `lid_with_id` words a create
     */
    private static function targets(
        stdClass $data,
        Pointer $pointer,
        ?string $id,
        ?string $lid,
        ResourceMetadata $resource,
        ErrorList $errors,
    ): bool {
        $target = $id ?? $lid ?? '';
        $names = $id !== null ? Member::ID : Member::LID;
        $other = $id !== null ? Member::LID : Member::ID;

        if (!JsonDocument::has($data, $names)) {
            JsonDocument::malformed($errors, $pointer);

            return false;
        }

        $written = JsonDocument::member($data, $names);

        if (!is_string($written)) {
            JsonDocument::malformed($errors, $pointer->descend($names));

            return false;
        }

        if (!self::identifies($written, $target, $id !== null ? $resource : null, $pointer->descend($names))) {
            JsonDocument::refuse(
                $errors,
                JsonDocument::CONFLICT,
                $id !== null ? ErrorCode::IdMismatch : ErrorCode::Conflict,
                $id !== null ? ['id' => $written, 'target' => $target] : [],
                $pointer->descend($names),
            );

            return false;
        }

        if (!JsonDocument::has($data, $other)) {
            return true;
        }

        $beside = JsonDocument::member($data, $other);

        if (!is_string($beside)) {
            JsonDocument::malformed($errors, $pointer->descend($other));

            return false;
        }

        JsonDocument::malformed($errors, $pointer->descend(Member::LID));

        return false;
    }

    /**
     * an id is compared as the codec decodes it: two spellings of one id match,
     * and a value the codec refuses matches nothing.
     * a `lid`, passed with a null `$resource`, is compared as text
     */
    private static function identifies(
        string $written,
        string $target,
        ?ResourceMetadata $resource,
        Pointer $pointer,
    ): bool {
        if ($resource === null) {
            return $written === $target;
        }

        try {
            return $resource->id->codec->decode($written, $pointer) === $target;
        } catch (DecodeException) {
            return false;
        }
    }

    /**
     * refuses every field a create requires and the data leaves out; true when none is missing.
     * a missing field is pointed at the object that would carry it, or at `data` without one:
     * a pointer must reference a value that exists
     */
    private static function checkRequiredFields(
        stdClass $data,
        Pointer $pointer,
        ResourceMetadata $resource,
        ErrorList $errors,
    ): bool {
        $objects = [
            Member::ATTRIBUTES => $resource->attributes,
            Member::RELATIONSHIPS => $resource->relationships,
        ];
        $complete = true;

        foreach ($objects as $member => $fields) {
            $object = JsonDocument::member($data, $member);

            foreach ($fields as $field) {
                if (
                    !$field->writeRule->required
                    || ($object instanceof stdClass && JsonDocument::has($object, $field->name))
                ) {
                    continue;
                }

                JsonDocument::refuse(
                    $errors,
                    JsonDocument::UNPROCESSABLE_CONTENT,
                    ErrorCode::MissingField,
                    ['type' => $resource->type, 'field' => $field->name],
                    $object instanceof stdClass ? $pointer->descend($member) : $pointer,
                );
                $complete = false;
            }
        }

        return $complete;
    }

    /**
     * @param bool $creating picks the create or the update write rule
     *
     * @return ?Values<mixed>
     */
    private function attributes(
        stdClass $data,
        Pointer $pointer,
        ResourceMetadata $resource,
        bool $creating,
        ErrorList $errors,
    ): ?Values {
        if (!JsonDocument::has($data, Member::ATTRIBUTES)) {
            return new Values([]);
        }

        $object = JsonDocument::member($data, Member::ATTRIBUTES);
        $attributes = $pointer->descend(Member::ATTRIBUTES);

        if (!$object instanceof stdClass) {
            JsonDocument::malformed($errors, $attributes);

            return null;
        }

        $values = [];
        $written = true;

        foreach (get_object_vars($object) as $key => $value) {
            $name = (string) $key;

            if (str_starts_with($name, self::AT)) {
                continue;
            }

            $member = $attributes->descend($name);
            $attribute = $resource->attributes[$name] ?? null;

            if ($attribute === null) {
                self::unknownField($errors, $resource, $name, $member);
                $written = false;

                continue;
            }

            if (!($creating ? $attribute->writeRule->creatable : $attribute->writeRule->updatable)) {
                self::readOnlyField($errors, $resource, $name, $member);
                $written = false;

                continue;
            }

            try {
                $values[$name] = $attribute->codec->decode($value, $member);
            } catch (DecodeException $exception) {
                self::violations($errors, $exception);
                $written = false;
            }
        }

        return $written ? new Values($values) : null;
    }

    /**
     * `$creating` picks the write rule, and whether a to-many linkage replaces the relationship;
     * `$own` is the object's own `lid`, which its linkage may not name
     *
     * @param array<array-key, array<array-key, true>> $declaredLids
     *
     * @return ?Values<LocalLinkage>
     */
    private function relationships(
        stdClass $data,
        Pointer $pointer,
        ResourceMetadata $resource,
        bool $creating,
        ErrorList $errors,
        array $declaredLids,
        ?LocalIdentifier $own,
    ): ?Values {
        if (!JsonDocument::has($data, Member::RELATIONSHIPS)) {
            return new Values([]);
        }

        $object = JsonDocument::member($data, Member::RELATIONSHIPS);
        $relationships = $pointer->descend(Member::RELATIONSHIPS);

        if (!$object instanceof stdClass) {
            JsonDocument::malformed($errors, $relationships);

            return null;
        }

        $values = [];
        $written = true;

        foreach (get_object_vars($object) as $key => $value) {
            $name = (string) $key;

            if (str_starts_with($name, self::AT)) {
                continue;
            }

            $member = $relationships->descend($name);
            $relationship = $resource->relationships[$name] ?? null;

            if ($relationship === null) {
                self::unknownField($errors, $resource, $name, $member);
                $written = false;

                continue;
            }

            if (!($creating ? $relationship->writeRule->creatable : $relationship->writeRule->updatable)) {
                self::readOnlyField($errors, $resource, $name, $member);
                $written = false;

                continue;
            }

            if (
                !$creating
                && $relationship->cardinality === Cardinality::ToMany
                && !$relationship->accepts(RelationshipUpdate::Replace)
            ) {
                // the spec lets a server refuse a full to-many replacement with 403;
                // the code names the relationship, since the type may accept it on another one
                JsonDocument::refuse(
                    $errors,
                    JsonDocument::FORBIDDEN,
                    ErrorCode::RelationshipUpdateNotSupported,
                    [
                        'relationship' => $name,
                        'type' => $resource->type,
                        'operation' => OperationKind::ReplaceRelationship->value,
                    ],
                    $member,
                );
                $written = false;

                continue;
            }

            if (!$value instanceof stdClass || !JsonDocument::has($value, Member::DATA)) {
                // in a request, a relationship object must carry `data`
                JsonDocument::malformed($errors, $member);
                $written = false;

                continue;
            }

            $linkage = $this->linkage(
                JsonDocument::member($value, Member::DATA),
                $member->descend(Member::DATA),
                $relationship->relatedType,
                $relationship->cardinality,
                $relationship->nullable,
                $errors,
                $declaredLids,
                $own,
            );

            if ($linkage === null) {
                $written = false;

                continue;
            }

            $values[$name] = $linkage;
        }

        return $written ? new Values($values) : null;
    }

    /**
     * the linkage of identifiers of one type, as a relationship or the body of an endpoint takes it
     *
     * @param string                                   $type         the type every identifier names
     * @param bool                                     $nullable     whether a to-one takes `null`
     * @param array<array-key, array<array-key, true>> $declaredLids
     */
    private function linkage(
        mixed $data,
        Pointer $pointer,
        string $type,
        Cardinality $cardinality,
        bool $nullable,
        ErrorList $errors,
        array $declaredLids,
        ?LocalIdentifier $own,
    ): ?LocalLinkage {
        if ($cardinality === Cardinality::ToMany) {
            return $this->members($data, $pointer, $type, $errors, $declaredLids, $own);
        }

        if ($data === null) {
            if ($nullable) {
                return LocalLinkage::toOne(null);
            }

            // null empties a nullable to-one relationship only;
            // elsewhere it is a readable value this API refuses, so 422
            self::invalidValue($errors, $pointer);

            return null;
        }

        $identifier = $this->identifier($data, $pointer, $type, $errors, $declaredLids, $own);

        return $identifier === null ? null : LocalLinkage::toOne($identifier);
    }

    /**
     * @param array<array-key, array<array-key, true>> $declaredLids
     */
    private function members(
        mixed $data,
        Pointer $pointer,
        string $type,
        ErrorList $errors,
        array $declaredLids,
        ?LocalIdentifier $own,
    ): ?LocalLinkage {
        if (!is_array($data) || !array_is_list($data)) {
            JsonDocument::malformed($errors, $pointer);

            return null;
        }

        $identifiers = [];
        $written = true;

        foreach ($data as $index => $value) {
            $identifier = $this->identifier(
                $value,
                $pointer->descend((string) $index),
                $type,
                $errors,
                $declaredLids,
                $own,
            );

            if ($identifier === null) {
                $written = false;

                continue;
            }

            $identifiers[] = $identifier;
        }

        return $written ? LocalLinkage::toMany($identifiers) : null;
    }

    /**
     * @param array<array-key, array<array-key, true>> $declaredLids
     */
    private function identifier(
        mixed $value,
        Pointer $pointer,
        string $relatedType,
        ErrorList $errors,
        array $declaredLids,
        ?LocalIdentifier $own,
    ): ?LocalIdentifier {
        if (!$value instanceof stdClass || !JsonDocument::has($value, Member::TYPE)) {
            JsonDocument::malformed($errors, $pointer);

            return null;
        }

        $type = JsonDocument::member($value, Member::TYPE);

        if (!is_string($type)) {
            JsonDocument::malformed($errors, $pointer->descend(Member::TYPE));

            return null;
        }

        if ($type !== $relatedType) {
            JsonDocument::refuse(
                $errors,
                JsonDocument::CONFLICT,
                ErrorCode::TypeMismatch,
                ['type' => $type],
                $pointer->descend(Member::TYPE),
            );

            return null;
        }

        $id = null;

        if (JsonDocument::has($value, Member::ID)) {
            $id = self::idOrLid(JsonDocument::member($value, Member::ID), $pointer->descend(Member::ID), $errors);

            if ($id === null) {
                return null;
            }
        }

        if (!JsonDocument::has($value, Member::LID)) {
            if ($id === null) {
                // an identifier without `id` must carry a `lid`
                JsonDocument::malformed($errors, $pointer);

                return null;
            }

            // decoded only here, so `lid_with_id` below names the id as the client wrote it
            $idPointer = $pointer->descend(Member::ID);

            try {
                $decoded = $this->resources->get($relatedType)->id->codec->decode($id, $idPointer);
            } catch (DecodeException $exception) {
                self::violations($errors, $exception);

                return null;
            }

            if ($decoded === '') {
                self::invalidValue($errors, $idPointer);

                return null;
            }

            return LocalIdentifier::ofId($type, $decoded);
        }

        $lid = self::idOrLid(JsonDocument::member($value, Member::LID), $pointer->descend(Member::LID), $errors);

        if ($lid === null) {
            return null;
        }

        if ($id !== null) {
            JsonDocument::refuse(
                $errors,
                JsonDocument::BAD_REQUEST,
                ErrorCode::LidWithId,
                ['id' => $id, 'lid' => $lid],
                $pointer->descend(Member::LID),
            );

            return null;
        }

        if ($own !== null && $own->type === $type && $own->lid() === $lid) {
            JsonDocument::refuse(
                $errors,
                JsonDocument::FORBIDDEN,
                ErrorCode::SelfReferencingLid,
                ['lid' => $lid],
                $pointer->descend(Member::LID),
            );

            return null;
        }

        if (!isset($declaredLids[$type][$lid])) {
            JsonDocument::refuse(
                $errors,
                JsonDocument::BAD_REQUEST,
                ErrorCode::UnknownLid,
                ['lid' => $lid, 'type' => $type],
                $pointer->descend(Member::LID),
            );

            return null;
        }

        return LocalIdentifier::ofLid($type, $lid);
    }

    private static function unknownField(
        ErrorList $errors,
        ResourceMetadata $resource,
        string $name,
        Pointer $pointer,
    ): void {
        JsonDocument::refuse(
            $errors,
            JsonDocument::UNPROCESSABLE_CONTENT,
            ErrorCode::UnknownField,
            ['type' => $resource->type, 'field' => $name],
            $pointer,
        );
    }

    private static function readOnlyField(
        ErrorList $errors,
        ResourceMetadata $resource,
        string $name,
        Pointer $pointer,
    ): void {
        JsonDocument::refuse(
            $errors,
            JsonDocument::UNPROCESSABLE_CONTENT,
            ErrorCode::ReadOnlyField,
            ['type' => $resource->type, 'field' => $name],
            $pointer,
        );
    }

    private static function invalidValue(ErrorList $errors, Pointer $pointer): void
    {
        JsonDocument::refuse(
            $errors,
            JsonDocument::UNPROCESSABLE_CONTENT,
            ErrorCode::UnprocessableContent,
            [],
            $pointer,
        );
    }

    private static function violations(ErrorList $errors, DecodeException $exception): void
    {
        $errors->add(new UnprocessableContentException(ViolationMapper::describe($exception)));
    }
}
