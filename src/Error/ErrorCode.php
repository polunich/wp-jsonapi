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

use InvalidArgumentException;

/**
 * the machine codes the library writes into the `code` member of an error object.
 *
 * a client matches on the value, so changing one breaks clients.
 *
 * a generic case stands for a status and is what `ofStatus()` returns;
 * its title is the fallback for any code no catalog words.
 * a specific case names one problem and belongs to no status,
 * since the library may respond to one problem with different statuses
 */
enum ErrorCode: string
{
    case BadRequest = 'bad_request';

    case Unauthorized = 'unauthorized';

    case Forbidden = 'forbidden';

    case NotFound = 'not_found';

    /**
     * the library raises no 405 of its own; the case exists so that a 405 raised elsewhere
     * is titled "method not allowed" instead of falling back to "bad request"
     */
    case MethodNotAllowed = 'method_not_allowed';

    case NotAcceptable = 'not_acceptable';

    case Conflict = 'conflict';

    case PreconditionFailed = 'precondition_failed';

    case ContentTooLarge = 'content_too_large';

    case UnsupportedMediaType = 'unsupported_media_type';

    case UnprocessableContent = 'unprocessable_content';

    case PreconditionRequired = 'precondition_required';

    case InternalError = 'internal_error';

    /**
     * 415 on `Content-Type`, 406 on `Accept`:
     * the JSON:API media type with a parameter other than `ext` and `profile`
     */
    case UnsupportedMediaTypeParameter = 'unsupported_media_type_parameter';

    /**
     * 415 on `Content-Type`, 406 on `Accept`:
     * an `ext` parameter naming an extension the server does not apply
     */
    case UnsupportedExtension = 'unsupported_extension';

    /**
     * 415: content whose `Content-Type` omits, from its `ext` parameter,
     * an extension the route applies to every document it reads;
     * a document with an extension applied must declare it.
     *
     * unlike `unsupported_extension`, the server applies the extension and the request omits it,
     * so the detail has to say which extension to name
     */
    case ExtensionRequired = 'extension_required';

    /** 400: a query parameter the route does not process */
    case UnsupportedQueryParameter = 'unsupported_query_parameter';

    /**
     * 400: a query parameter the route processes, with a value it cannot read;
     * also the Invalid Parameter Value Error of the cursor pagination profile
     */
    case InvalidQueryParameter = 'invalid_query_parameter';

    /**
     * 400: a query parameter an endpoint or a route requires, missing from the request;
     * the detail and `source.parameter` name it,
     * or `source.pointer` the atomic operation whose route requires it
     */
    case MissingQueryParameter = 'missing_query_parameter';

    /**
     * 400: page parameters of more than one pagination strategy in one request,
     * one error per conflicting parameter
     */
    case MixedPaginationStrategies = 'mixed_pagination_strategies';

    /**
     * 400: a page size above the maximum of the route,
     * with the maximum in `meta.page` and the type link,
     * as the cursor pagination profile defines the error
     */
    case MaxPageSizeExceeded = 'max_page_size_exceeded';

    /**
     * 400: a sort field the route does not sort by: a field that is not sortable,
     * a path through a to-many relationship, or a nullable field under cursor pagination
     */
    case UnsupportedSort = 'unsupported_sort';

    /** 400: one field sorted by twice in one `sort` parameter */
    case DuplicateSortField = 'duplicate_sort_field';

    /**
     * 400: a relationship path the route does not include: a path it cannot identify,
     * a relationship that forbids inclusion, or a path deeper than the maximum
     */
    case UnsupportedInclude = 'unsupported_include';

    /**
     * 400: a field the type cannot be filtered by: an undeclared field or relationship,
     * a field that declares no operator, or a relationship path beyond the include depth
     */
    case UnsupportedFilterField = 'unsupported_filter_field';

    /** 400: an operator the filtered field does not admit, built-in or custom alike */
    case UnsupportedFilterOperator = 'unsupported_filter_operator';

    /** 400: a request body that is no JSON text, ill-formed UTF-8 included (RFC 8259 8.1) */
    case InvalidJson = 'invalid_json';

    /**
     * 403: a resource object to create that carries an `id`
     * where the type accepts no client-generated id
     */
    case ClientIdNotSupported = 'client_id_not_supported';

    /**
     * 409: a type the request does not accept where it stands:
     * a resource object outside the collection of the route or not matching the target,
     * or an identifier of a type the relationship does not accept
     */
    case TypeMismatch = 'type_mismatch';

    /** 409: a resource object to update whose `id` is not the id of the target */
    case IdMismatch = 'id_mismatch';

    /**
     * 400 in a `fields[TYPE]` parameter, 422 in a request document:
     * a field the resource type does not declare
     */
    case UnknownField = 'unknown_field';

    /**
     * 422: a field the type requires on create that the resource object lacks,
     * pointing at the `attributes` or `relationships` object that would carry it,
     * else at the resource object
     */
    case MissingField = 'missing_field';

    /**
     * 422: a field the request may not write:
     * not creatable in a create, or not updatable in an update
     */
    case ReadOnlyField = 'read_only_field';

    /** 400: one lid declared twice for one resource type in an atomic document */
    case DuplicateLid = 'duplicate_lid';

    /**
     * 400: a lid referenced before an operation declares it, or, in a single request,
     * an identifier without `id` that names no resource of the primary data
     */
    case UnknownLid = 'unknown_lid';

    /**
     * 403: a lid referenced inside the resource object that declares it,
     * whose resource does not exist before the write, refused as an unsupported create
     * (JSON:API, Creating Resources)
     */
    case SelfReferencingLid = 'self_referencing_lid';

    /**
     * 400: a resource object to create, or a resource identifier, that carries both `id` and `lid`,
     * pointing at the `lid`, which stands only where the `id` is omitted
     */
    case LidWithId = 'lid_with_id';

    /**
     * 403: a kind of operation the declaration enables on no resource of the type;
     * its detail names the type and the kind, the problem the type alone decides
     */
    case OperationNotSupported = 'operation_not_supported';

    /**
     * 403: an update a relationship of the type does not declare.
     *
     * unlike `operation_not_supported`, the type may enable the kind on another relationship,
     * so the detail has to name the relationship that refused it
     */
    case RelationshipUpdateNotSupported = 'relationship_update_not_supported';

    /**
     * 403: a read of a relationship route the relationship excludes.
     *
     * as with `relationship_update_not_supported`,
     * another relationship of the type may offer the route,
     * so the detail names the relationship that excludes it
     */
    case RelationshipFetchNotSupported = 'relationship_fetch_not_supported';

    /**
     * 403: a denied request without identity for which no challenge applies:
     * a 401 must carry at least one challenge (RFC 9110 15.5.2)
     */
    case AuthenticationRequired = 'authentication_required';

    /**
     * 412: an `If-Match` value the grammar of the field does not admit, with `source.header`;
     * RFC 9110 13.1.1 then takes the condition as false.
     *
     * unlike `precondition_failed`, nothing was evaluated against the resource,
     * so the detail points the client at the field value, not at the state of the resource
     */
    case InvalidPrecondition = 'invalid_precondition';

    /**
     * 404: a write whose linkage names a related resource
     * that does not exist or the client may not see, one error per missing identifier.
     *
     * unlike the generic `not_found`, which names a missing target, the target exists here,
     * so the detail has to name the relationship and the identifier
     */
    case RelatedResourceNotFound = 'related_resource_not_found';

    /**
     * 409: a create whose client-generated id already identifies a resource of the type,
     * pointing at `/data/id`
     */
    case ResourceAlreadyExists = 'resource_already_exists';

    /**
     * 409: a write that breaks a constraint the server enforces, other than id uniqueness.
     *
     * the detail carries the lower_snake name of the constraint,
     * so one code serves every constraint and a client tells them apart by that name
     */
    case ConstraintViolation = 'constraint_violation';

    /**
     * 400: a file of a form PHP received cut, `UPLOAD_ERR_PARTIAL`,
     * with the field in the error's meta
     */
    case UploadIncomplete = 'upload_incomplete';

    /**
     * 422: a field or a file the form of the endpoint requires and the request lacks,
     * with the field in the error's meta
     */
    case MissingFormField = 'missing_form_field';

    /**
     * 422: a field or a file the form of the endpoint does not declare,
     * with the field in the error's meta
     */
    case UnknownFormField = 'unknown_form_field';

    /**
     * 422: several files under a name the form declares for one file,
     * with the field in the error's meta
     */
    case InvalidFormFile = 'invalid_form_file';

    /**
     * any other 4xx falls back to `BadRequest` and any other 5xx to `InternalError`,
     * as a client treats an unrecognized status
     * "as being equivalent to the x00 status code of that class" (RFC 9110 15)
     *
     * @throws InvalidArgumentException when the status is not 4xx or 5xx
     */
    public static function ofStatus(int $status): self
    {
        return match (true) {
            $status === 401 => self::Unauthorized,
            $status === 403 => self::Forbidden,
            $status === 404 => self::NotFound,
            $status === 405 => self::MethodNotAllowed,
            $status === 406 => self::NotAcceptable,
            $status === 409 => self::Conflict,
            $status === 412 => self::PreconditionFailed,
            $status === 413 => self::ContentTooLarge,
            $status === 415 => self::UnsupportedMediaType,
            $status === 422 => self::UnprocessableContent,
            $status === 428 => self::PreconditionRequired,
            $status >= 400 && $status <= 499 => self::BadRequest,
            $status >= 500 && $status <= 599 => self::InternalError,
            default => throw new InvalidArgumentException(
                'a generic error code is looked up for a 4xx or 5xx status code, got ' . $status,
            ),
        };
    }
}
