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

use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\ErrorList;
use Polunich\WpJsonApi\Error\JsonApiException;
use stdClass;

/**
 * each refusal becomes its own error object in the step's error list,
 * so one response names every mistake.
 * the caller passes the absolute pointer:
 * the root and below for a single request, `/atomic:operations/<n>` and below for an operation
 *
 * @internal
 */
final readonly class JsonDocument
{
    /**
     * a document this server cannot read is 400;
     * a readable value that breaks a rule takes the status of that rule,
     * 404 being an id its type's codec refuses where it addresses a target
     */
    public const BAD_REQUEST = 400;

    public const FORBIDDEN = 403;

    public const NOT_FOUND = 404;

    public const CONFLICT = 409;

    public const UNPROCESSABLE_CONTENT = 422;

    /**
     * a digits-only member name sits under an int key in `get_object_vars()`,
     * and `array_key_exists()` with the string still finds it
     */
    public static function has(stdClass $object, string $name): bool
    {
        return array_key_exists($name, get_object_vars($object));
    }

    /**
     * null both for a missing member and for a member set to `null`;
     * `has()` tells them apart where `null` is a value, as when it empties a to-one relationship
     */
    public static function member(stdClass $object, string $name): mixed
    {
        return get_object_vars($object)[$name] ?? null;
    }

    /**
     * a document or object that breaks the structure the spec prescribes:
     * the problem is the status itself, so it takes the generic code of 400
     */
    public static function malformed(ErrorList $errors, Pointer $pointer): void
    {
        self::refuse($errors, self::BAD_REQUEST, ErrorCode::BadRequest, [], $pointer);
    }

    /**
     * @param array<string, scalar> $parameters
     */
    public static function refuse(
        ErrorList $errors,
        int $status,
        ErrorCode $code,
        array $parameters,
        Pointer $pointer,
    ): void {
        $errors->add(JsonApiException::ofStatus($status, [
            new ErrorDescription($code, $parameters, $pointer->toString()),
        ]));
    }
}
