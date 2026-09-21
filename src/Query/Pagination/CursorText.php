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

namespace Polunich\WpJsonApi\Query\Pagination;

use InvalidArgumentException;
use JsonException;
use stdClass;
use Throwable;

/**
 * the text form of a cursor: base64url JSON, unsigned.
 * an edited cursor yields another page of the same collection,
 * never a record the client may not see:
 * a cursor only adds filter conditions to a reading that runs anyway.
 *
 * base64url (RFC 4648 section 5) travels in a query string unescaped.
 * the padding is left out, since the length is that of the parameter value;
 * RFC 4648 section 3.2 requires padding unless the referring specification says otherwise,
 * and this is where it says so.
 *
 * `decode()` accepts only the exact text `encode()` writes for the decoded bytes,
 * so it refuses every other spelling of the same bytes,
 * including what strict `base64_decode()` lets through:
 * spaces, tabs and line feeds, and a last character whose unused bits are not zero.
 *
 * the value rules are `Cursor`'s alone: this class checks the shape,
 * and keeps a refusal of `Cursor` or of the JSON decoder as the previous exception.
 *
 * not a `Codec\Codec`: a codec maps JSON values, this maps text
 */
final readonly class CursorText
{
    private const SORT = 'sort';

    private const ID = 'id';

    /**
     * @throws InvalidArgumentException when a sort value is a string that is no valid UTF-8
     */
    public static function encode(Cursor $cursor): string
    {
        $payload = new stdClass();
        $payload->{self::SORT} = (object) $cursor->sortValues;
        $payload->{self::ID} = $cursor->id;

        try {
            // unescaped slashes and unicode only shorten the text;
            // the zero fraction keeps `2.0` a float: `json_decode()` would read `2` as an int
            $json = json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'the sort values of the cursor cannot be written as JSON text: ' . $exception->getMessage(),
                0,
                $exception,
            );
        }

        return self::base64url($json);
    }

    /**
     * @throws InvalidArgumentException when the text is not a cursor this class wrote
     */
    public static function decode(string $cursor): Cursor
    {
        $json = base64_decode(strtr($cursor, '-_', '+/'), true);

        if ($json === false || self::base64url($json) !== $cursor) {
            throw self::refuse('it is not the base64url text without padding this codec writes');
        }

        try {
            $payload = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw self::refuse('it carries no JSON text', $exception);
        }

        if (!$payload instanceof stdClass) {
            throw self::refuse('it carries no JSON object');
        }

        $members = get_object_vars($payload);

        if (
            count($members) !== 2
            || !array_key_exists(self::SORT, $members)
            || !array_key_exists(self::ID, $members)
        ) {
            throw self::refuse(sprintf('a cursor carries "%s" and "%s" and nothing else', self::SORT, self::ID));
        }

        $sort = $members[self::SORT];
        $id = $members[self::ID];

        if (!$sort instanceof stdClass) {
            throw self::refuse(sprintf('its "%s" is no JSON object', self::SORT));
        }

        if (!is_string($id)) {
            throw self::refuse(sprintf('its "%s" is no JSON string', self::ID));
        }

        $sortValues = [];

        foreach (get_object_vars($sort) as $field => $value) {
            if (!is_bool($value) && !is_float($value) && !is_int($value) && !is_string($value)) {
                throw self::refuse(sprintf('its sort value of field "%s" is no scalar', (string) $field));
            }

            $sortValues[$field] = $value;
        }

        try {
            return new Cursor($sortValues, $id);
        } catch (InvalidArgumentException $exception) {
            throw self::refuse('what it carries makes no cursor', $exception);
        }
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function refuse(string $reason, ?Throwable $previous = null): InvalidArgumentException
    {
        return new InvalidArgumentException(
            'the text is no cursor of this API, because ' . $reason,
            0,
            $previous,
        );
    }
}
