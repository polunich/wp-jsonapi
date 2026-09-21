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
use JsonException;
use Polunich\WpJsonApi\Document\ErrorObject;
use Polunich\WpJsonApi\Document\ErrorSource;
use Polunich\WpJsonApi\Document\Links;
use Polunich\WpJsonApi\Document\Member;
use ReflectionReference;
use stdClass;

/**
 * builds the error object of a description when the response is built.
 *
 * a title always results: the last fallback is the library's title of the generic code
 * of the problem's status, and the library words every generic code.
 * a detail has no such fallback, since a generic text would explain no occurrence.
 *
 * an error of a failed atomic operation at position n points into it:
 * a pointer p, relative to the operation, becomes `/atomic:operations/n` followed by p;
 * any other source, or none, becomes `/atomic:operations/n`,
 * "a pointer to the invalid operation" (Atomic Operations extension).
 *
 * every string of the error object is made well-formed UTF-8 (RFC 8259 8.1):
 * each ill-formed sequence becomes U+FFFD, and a well-formed string stays byte for byte
 *
 * @internal
 */
final readonly class ErrorFactory
{
    /**
     * keeps `1.0` a float through the round trip
     */
    private const SUBSTITUTE = JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    /**
     * `json_decode()` counts the scalar inside the deepest array as a level of its own,
     * so reading back what `json_encode()` writes at its default depth of 512 takes 513
     */
    private const DECODE_DEPTH = 513;

    public function __construct(
        private LibraryErrorCatalog $libraryCatalog,
        private ?ErrorCatalog $projectCatalog = null,
    ) {}

    /**
     * @param int      $status            the status of the exception that carries the description
     * @param int|null $operationPosition zero-based, null for a single request
     *
     * @throws InvalidArgumentException when the status is not 4xx or 5xx,
     *                                  or the position is negative
     */
    public function create(ErrorDescription $description, int $status, ?int $operationPosition = null): ErrorObject
    {
        if ($status < 400 || $status > 599) {
            throw new InvalidArgumentException(
                'an error object is created for a 4xx or 5xx status code, got ' . $status,
            );
        }

        if ($operationPosition !== null && $operationPosition < 0) {
            throw new InvalidArgumentException(
                'the position of an atomic operation is zero or more, got ' . $operationPosition,
            );
        }

        $code = $description->code instanceof ErrorCode ? $description->code->value : $description->code;
        $problemStatus = $description->status ?? $status;
        $detail = $description->detail
            ?? $this->projectCatalog?->detail($code, $description->parameters)
            ?? $this->libraryCatalog->detail($code, $description->parameters);

        return new ErrorObject(
            (string) $problemStatus,
            self::text($code),
            self::text($description->title ?? $this->title($code, $problemStatus)),
            $detail === null ? null : self::text($detail),
            self::source($description, $operationPosition),
            $description->typeLink === null ? null : new Links([Member::TYPE => self::text($description->typeLink)]),
            $description->meta === [] ? null : self::map($description->meta),
        );
    }

    private function title(string $code, int $problemStatus): string
    {
        $generic = ErrorCode::ofStatus($problemStatus);

        return $this->projectCatalog?->title($code)
            ?? $this->libraryCatalog->title($code)
            ?? $this->projectCatalog?->title($generic->value)
            ?? $this->libraryCatalog->titleOf($generic);
    }

    private static function source(ErrorDescription $description, ?int $operationPosition): ?ErrorSource
    {
        if ($operationPosition !== null) {
            $operation = '/atomic:operations/' . $operationPosition;

            return ErrorSource::pointer(
                $description->pointer === null ? $operation : $operation . self::text($description->pointer),
            );
        }

        if ($description->pointer !== null) {
            return ErrorSource::pointer(self::text($description->pointer));
        }

        if ($description->parameter !== null) {
            return ErrorSource::parameter(self::text($description->parameter));
        }

        if ($description->header !== null) {
            return ErrorSource::header(self::text($description->header));
        }

        return null;
    }

    private static function text(string $text): string
    {
        $substituted = json_decode(json_encode($text, self::SUBSTITUTE), flags: JSON_THROW_ON_ERROR);

        // unreachable: a JSON string decodes to a string;
        // the fallback only satisfies the type checker
        return is_string($substituted) ? $substituted : $text;
    }

    /**
     * the map with every string of its keys and values made well-formed, at any depth;
     * keys that become equal keep the later value.
     *
     * an array or a `stdClass` that holds itself stays as it is
     * where the walk would enter it again, as every value `json_encode()` refuses does;
     * otherwise the walk would recurse until PHP runs out of memory,
     * a fatal error no handler catches
     *
     * @param array<array-key, mixed> $map
     * @param array<string, true>     $path the references and objects entered, by identity
     *
     * @return array<array-key, mixed>
     */
    private static function map(array $map, array $path = []): array
    {
        $substituted = [];

        foreach ($map as $key => $value) {
            $name = is_string($key) ? self::text($key) : $key;
            $reference = ReflectionReference::fromArrayElement($map, $key);

            if ($reference === null) {
                $substituted[$name] = self::value($value, $path);

                continue;
            }

            $identity = 'reference ' . $reference->getId();
            $substituted[$name] = isset($path[$identity]) ? $value : self::value($value, $path + [$identity => true]);
        }

        return $substituted;
    }

    /**
     * a `stdClass` is copied and walked; any other object and an enum case become
     * what `json_encode()` writes for them, read back with objects as `stdClass`;
     * a value `json_encode()` refuses stays as it is
     *
     * @param array<string, true> $path
     */
    private static function value(mixed $value, array $path): mixed
    {
        if (is_string($value)) {
            return self::text($value);
        }

        if (is_array($value)) {
            return self::map($value, $path);
        }

        if (!is_object($value)) {
            return $value;
        }

        if ($value::class === stdClass::class) {
            $identity = 'object ' . spl_object_id($value);

            return isset($path[$identity]) ? $value : (object) self::map(
                get_object_vars($value),
                $path + [$identity => true],
            );
        }

        try {
            return json_decode(json_encode($value, self::SUBSTITUTE), false, self::DECODE_DEPTH, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $value;
        }
    }
}
