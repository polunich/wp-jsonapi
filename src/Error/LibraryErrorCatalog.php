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

namespace Polunich\WpJsonApi\Error;

use Override;
use Polunich\WpJsonApi\Codec\ViolationCode;

/**
 * the catalog of the library: texts that start in lower case for every case of `ErrorCode`
 * and of `Codec\ViolationCode`, and null for any other code
 *
 * @internal
 */
final readonly class LibraryErrorCatalog implements ErrorCatalog
{
    #[Override]
    public function title(string $code): ?string
    {
        $case = ErrorCode::tryFrom($code);

        if ($case !== null) {
            return $this->titleOf($case);
        }

        $violation = ViolationCode::tryFrom($code);

        return $violation === null ? null : self::violationTitleOf($violation);
    }

    /**
     * a detail marks the values of its occurrence with `{name}` placeholders, the syntax of PSR-3.
     * a value is written as PHP casts it to a string, a boolean as `true` or `false`,
     * and a parameter no placeholder names is ignored.
     * without the value of one of its placeholders the detail is null,
     * since the text would not explain the occurrence
     *
     * @param array<string, scalar> $parameters
     */
    #[Override]
    public function detail(string $code, array $parameters): ?string
    {
        $template = self::template($code);

        if ($template === null) {
            return null;
        }

        $replacements = [];

        foreach (self::placeholders($template) as $name) {
            if (!array_key_exists($name, $parameters)) {
                return null;
            }

            $value = $parameters[$name];
            $replacements['{' . $name . '}'] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return strtr($template, $replacements);
    }

    /**
     * the match has no default arm, so PHPStan reports a case added without its title
     */
    public function titleOf(ErrorCode $code): string
    {
        return match ($code) {
            ErrorCode::BadRequest => 'bad request',
            ErrorCode::Unauthorized => 'unauthorized',
            ErrorCode::Forbidden => 'forbidden',
            ErrorCode::NotFound => 'not found',
            ErrorCode::MethodNotAllowed => 'method not allowed',
            ErrorCode::NotAcceptable => 'not acceptable',
            ErrorCode::Conflict => 'conflict',
            ErrorCode::PreconditionFailed => 'precondition failed',
            ErrorCode::ContentTooLarge => 'content too large',
            ErrorCode::UnsupportedMediaType => 'unsupported media type',
            ErrorCode::UnprocessableContent => 'unprocessable content',
            ErrorCode::PreconditionRequired => 'precondition required',
            ErrorCode::InternalError => 'internal server error',
            ErrorCode::UnsupportedMediaTypeParameter => 'unsupported media type parameter',
            ErrorCode::UnsupportedExtension => 'unsupported extension',
            ErrorCode::ExtensionRequired => 'extension required',
            ErrorCode::UnsupportedQueryParameter => 'unsupported query parameter',
            ErrorCode::InvalidQueryParameter => 'invalid query parameter',
            ErrorCode::MissingQueryParameter => 'missing query parameter',
            ErrorCode::MixedPaginationStrategies => 'mixed pagination strategies',
            ErrorCode::MaxPageSizeExceeded => 'max page size exceeded',
            ErrorCode::UnsupportedSort => 'unsupported sort',
            ErrorCode::DuplicateSortField => 'duplicate sort field',
            ErrorCode::UnsupportedInclude => 'unsupported include',
            ErrorCode::UnsupportedFilterField => 'unsupported filter field',
            ErrorCode::UnsupportedFilterOperator => 'unsupported filter operator',
            ErrorCode::InvalidJson => 'invalid JSON',
            ErrorCode::ClientIdNotSupported => 'client-generated id not supported',
            ErrorCode::TypeMismatch => 'type mismatch',
            ErrorCode::IdMismatch => 'id mismatch',
            ErrorCode::UnknownField => 'unknown field',
            ErrorCode::MissingField => 'missing field',
            ErrorCode::ReadOnlyField => 'read-only field',
            ErrorCode::DuplicateLid => 'duplicate lid',
            ErrorCode::UnknownLid => 'unknown lid',
            ErrorCode::SelfReferencingLid => 'self-referencing lid',
            ErrorCode::LidWithId => 'lid with id',
            ErrorCode::OperationNotSupported => 'operation not supported',
            ErrorCode::RelationshipUpdateNotSupported => 'relationship update not supported',
            ErrorCode::RelationshipFetchNotSupported => 'relationship fetch not supported',
            ErrorCode::AuthenticationRequired => 'authentication required',
            ErrorCode::InvalidPrecondition => 'invalid precondition',
            ErrorCode::RelatedResourceNotFound => 'related resource not found',
            ErrorCode::ResourceAlreadyExists => 'resource already exists',
            ErrorCode::ConstraintViolation => 'constraint violation',
            ErrorCode::UploadIncomplete => 'upload incomplete',
            ErrorCode::MissingFormField => 'missing form field',
            ErrorCode::UnknownFormField => 'unknown form field',
            ErrorCode::InvalidFormFile => 'invalid form file',
        };
    }

    /**
     * exhaustive like `titleOf()`.
     * `{operation}` is the kind of operation in lower_snake form, such as `add_to_relationship`
     */
    private static function detailOf(ErrorCode $code): string
    {
        return match ($code) {
            ErrorCode::BadRequest => 'the server cannot process the request because of something it takes for'
                . ' an error of the client',
            ErrorCode::Unauthorized => 'the request lacks valid authentication credentials for the target resource',
            ErrorCode::Forbidden => 'the server understood the request and refuses to fulfill it',
            ErrorCode::NotFound => 'the server has no current representation of the target resource, or does not'
                . ' disclose that one exists',
            ErrorCode::MethodNotAllowed => 'the method of the request is one this server knows and the target'
                . ' resource does not support. the Allow field of this response lists the methods it does',
            ErrorCode::NotAcceptable => 'the target resource has no representation that the Accept field of the'
                . ' request admits',
            ErrorCode::Conflict => 'the request conflicts with the current state of the target resource',
            ErrorCode::PreconditionFailed => 'the {field} precondition does not hold for the current representation'
                . ' of the target resource. an entity tag holds for the media type and the language of the response'
                . ' that carried it, and a response to a request with include carries none',
            ErrorCode::ContentTooLarge => 'the content of the request is larger than this server takes',
            ErrorCode::UnsupportedMediaType => 'the content of the request is in a format that this method on the'
                . ' target resource does not support',
            ErrorCode::UnprocessableContent => 'the server understands the content of the request and cannot process'
                . ' the instructions it contains',
            ErrorCode::PreconditionRequired => 'this resource is changed only by a conditional request. send If-Match'
                . ' with the entity tag of the resource, as a single request, since an operation of an atomic'
                . ' request carries no precondition',
            ErrorCode::InternalError => 'the server met an unexpected condition that kept it from fulfilling the'
                . ' request',
            ErrorCode::UnsupportedMediaTypeParameter => 'the JSON:API media type of the request carries the'
                . ' parameter {name}, and this specification defines only ext and profile',
            ErrorCode::UnsupportedExtension => 'the request names the extension {extension} in its media type,'
                . ' and this server does not apply it',
            ErrorCode::ExtensionRequired => 'this endpoint reads a document with the extension {extension}'
                . ' applied, and the media type of the content of the request does not name it in its ext'
                . ' parameter',
            ErrorCode::UnsupportedQueryParameter => 'this endpoint does not process the query parameter {name}',
            ErrorCode::InvalidQueryParameter => 'the query parameter {name} does not carry a value this endpoint'
                . ' can read',
            ErrorCode::MissingQueryParameter => 'this operation requires the query parameter {name}, which the'
                . ' request does not carry; an operation of an atomic request carries no query parameter',
            ErrorCode::MixedPaginationStrategies => 'the page parameters of one request all belong to one'
                . ' pagination strategy, and {name} belongs to another',
            ErrorCode::MaxPageSizeExceeded => 'the requested page size {size} is larger than {maxSize}, the'
                . ' maximum of this endpoint',
            ErrorCode::UnsupportedSort => 'this endpoint does not sort by {field}',
            ErrorCode::DuplicateSortField => 'the sort names the field {field} twice, and one sort orders by a'
                . ' field once',
            ErrorCode::UnsupportedInclude => 'this endpoint does not include the relationship path {path}',
            ErrorCode::UnsupportedFilterField => 'the resource type {type} is not filtered by {field}',
            ErrorCode::UnsupportedFilterOperator => 'the field {field} does not admit the operator {operator}',
            ErrorCode::InvalidJson => 'the content of the request is not a well-formed JSON text',
            ErrorCode::ClientIdNotSupported => 'the resource type {type} accepts no id from the client, and the'
                . ' server assigns one',
            ErrorCode::TypeMismatch => 'the type {type} is not among the resource types accepted at this place of'
                . ' the request',
            ErrorCode::IdMismatch => 'the resource object carries the id {id}, and the request targets the'
                . ' resource {target}',
            ErrorCode::UnknownField => 'the resource type {type} declares no field {field}',
            ErrorCode::MissingField => 'the resource type {type} requires the field {field} when a resource of it'
                . ' is created',
            ErrorCode::ReadOnlyField => 'the field {field} of the resource type {type} is not written by a request'
                . ' of this kind',
            ErrorCode::DuplicateLid => 'the lid {lid} is declared twice for the resource type {type}',
            ErrorCode::UnknownLid => 'the lid {lid} of the resource type {type} is referenced before it'
                . ' is declared',
            ErrorCode::SelfReferencingLid => 'the lid {lid} is referenced inside the resource object'
                . ' that declares it, whose resource does not exist yet',
            ErrorCode::LidWithId => 'the resource object to create carries the id {id} beside the lid'
                . ' {lid}, and a lid stands where an id is omitted',
            ErrorCode::OperationNotSupported => 'the resource type {type} does not support the operation'
                . ' {operation}',
            ErrorCode::RelationshipUpdateNotSupported => 'the relationship {relationship} of the resource type'
                . ' {type} does not accept the operation {operation}',
            ErrorCode::RelationshipFetchNotSupported => 'the relationship {relationship} of the resource type'
                . ' {type} does not offer the operation {operation}',
            ErrorCode::AuthenticationRequired => 'the request carries no identity this server accepts, and no'
                . ' authentication scheme applies to it. the contract of the API names the schemes it accepts',
            ErrorCode::InvalidPrecondition => 'the If-Match field value is neither * nor a list of entity tags,'
                . ' so the precondition does not hold',
            ErrorCode::RelatedResourceNotFound => 'the relationship {relationship} names the {type} resource'
                . ' {id}, which does not exist',
            ErrorCode::ResourceAlreadyExists => 'the id {id} already identifies a {type} resource, and a create'
                . ' names an id no resource of the type carries',
            ErrorCode::ConstraintViolation => 'the write breaks the constraint {constraint} this server'
                . ' enforces',
            ErrorCode::UploadIncomplete => 'the file {field} arrived only in part',
            ErrorCode::MissingFormField => 'this endpoint requires the form field {field}, and the request does not'
                . ' carry it',
            ErrorCode::UnknownFormField => 'this endpoint reads no form field {field}',
            ErrorCode::InvalidFormFile => 'the form field {field} holds one file, and the request sends several',
        };
    }

    /**
     * exhaustive like `titleOf()`
     */
    private static function violationTitleOf(ViolationCode $code): string
    {
        return match ($code) {
            ViolationCode::InvalidType => 'invalid type',
            ViolationCode::InvalidValue => 'invalid value',
            ViolationCode::ValueNotAllowed => 'value not allowed',
            ViolationCode::InvalidFormat => 'invalid format',
            ViolationCode::ValueTooSmall => 'value too small',
            ViolationCode::ValueTooLarge => 'value too large',
            ViolationCode::ValueTooShort => 'value too short',
            ViolationCode::ValueTooLong => 'value too long',
            ViolationCode::PatternMismatch => 'pattern mismatch',
            ViolationCode::TooFewItems => 'too few items',
            ViolationCode::TooManyItems => 'too many items',
            ViolationCode::MissingMember => 'missing member',
            ViolationCode::UnexpectedMember => 'unexpected member',
            ViolationCode::ReadOnlyMember => 'read-only member',
            ViolationCode::ValueRejected => 'value rejected',
        };
    }

    /**
     * a text holds at most one placeholder, the `ViolationCode::parameter()` of its case;
     * exhaustive like `titleOf()`
     */
    private static function violationDetailOf(ViolationCode $code): string
    {
        return match ($code) {
            ViolationCode::InvalidType => 'the value is not of the JSON type {expected}',
            ViolationCode::InvalidValue => 'the value is not {expected}, the only value allowed here',
            ViolationCode::ValueNotAllowed => 'the value is none of the allowed values: {allowed}',
            ViolationCode::InvalidFormat => 'the string is not written in the {format} format',
            ViolationCode::ValueTooSmall => 'the value is less than the minimum, {minimum}',
            ViolationCode::ValueTooLarge => 'the value is greater than the maximum, {maximum}',
            ViolationCode::ValueTooShort => 'the string is shorter than {minLength} characters, the minimum',
            ViolationCode::ValueTooLong => 'the string is longer than {maxLength} characters, the maximum',
            ViolationCode::PatternMismatch => 'the string does not match the pattern {pattern}',
            ViolationCode::TooFewItems => 'the array holds fewer items than the minimum, {minItems}',
            ViolationCode::TooManyItems => 'the array holds more items than the maximum, {maxItems}',
            ViolationCode::MissingMember => 'the object lacks the member {member}, which is required',
            ViolationCode::UnexpectedMember => 'the object holds the member {member}, which is not declared',
            ViolationCode::ReadOnlyMember => 'the object holds the member {member}, which is not written by a request',
            ViolationCode::ValueRejected => 'the value has the declared type and is not accepted',
        };
    }

    private static function template(string $code): ?string
    {
        $case = ErrorCode::tryFrom($code);

        if ($case !== null) {
            return self::detailOf($case);
        }

        $violation = ViolationCode::tryFrom($code);

        return $violation === null ? null : self::violationDetailOf($violation);
    }

    /**
     * @return list<string>
     */
    private static function placeholders(string $template): array
    {
        $names = [];
        $offset = 0;

        while (
            ($open = strpos($template, '{', $offset)) !== false
            && ($close = strpos($template, '}', $open)) !== false
        ) {
            $names[] = substr($template, $open + 1, $close - $open - 1);
            $offset = $close + 1;
        }

        return $names;
    }
}
