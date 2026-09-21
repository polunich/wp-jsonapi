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

namespace Polunich\WpJsonApi\Codec;

use InvalidArgumentException;
use Override;
use Polunich\WpJsonApi\Codec\Schema\Format;
use Polunich\WpJsonApi\Codec\Schema\JsonType;
use Polunich\WpJsonApi\Codec\Schema\Schema;

/**
 * the hex-and-dash form of RFC 9562 (section 4), the `uuid` format of JSON Schema.
 * the form ignores case, so a decoded UUID is lowercased: one UUID, one string.
 *
 * version and variant are not checked: the format asks only for
 * "a valid string representation of a UUID" (JSON Schema 2020-12, validation, section 7.3.5)
 */
final readonly class UuidCodec implements SegmentCodec
{
    /**
     * both cases spelled out: the segment pattern carries no flag
     */
    private const SEGMENT = '[0-9A-Fa-f]{8}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{4}-[0-9A-Fa-f]{12}';

    private const PATTERN = '/^(?:' . self::SEGMENT . ')$/D';

    #[Override]
    public function decode(mixed $input, Pointer $pointer): string
    {
        if (!is_string($input)) {
            throw new DecodeException([
                ViolationCode::InvalidType->at($pointer, JsonType::String->value),
            ]);
        }

        if (preg_match(self::PATTERN, $input) !== 1) {
            throw new DecodeException([
                ViolationCode::InvalidFormat->at($pointer, Format::Uuid->value),
            ]);
        }

        return strtolower($input);
    }

    #[Override]
    public function encode(mixed $value): string
    {
        if (!is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('the value must be a UUID in the string form of RFC 9562');
        }

        return strtolower($value);
    }

    #[Override]
    public function schema(): Schema
    {
        return new Schema(type: JsonType::String, format: Format::Uuid);
    }

    #[Override]
    public function segmentPattern(): string
    {
        return self::SEGMENT;
    }
}
