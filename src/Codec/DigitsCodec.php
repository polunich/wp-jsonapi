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
use Polunich\WpJsonApi\Codec\Schema\JsonType;
use Polunich\WpJsonApi\Codec\Schema\Schema;

/**
 * a numeric id: a string of decimal digits without a leading zero,
 * so one number has one text, as JSON:API (Identification) requires:
 * "each resource object's type and id pair MUST identify a single, unique resource".
 * the value stays a string of any length, never a PHP int, which would stop at `PHP_INT_MAX`
 */
final readonly class DigitsCodec implements SegmentCodec
{
    private const SEGMENT = '0|[1-9][0-9]*';

    /**
     * anchored by hand: JSON Schema 2020-12 (validation, section 6.3.3) does not anchor a `pattern`
     */
    private const SCHEMA_PATTERN = '^(?:' . self::SEGMENT . ')$';

    private const PATTERN = '/' . self::SCHEMA_PATTERN . '/D';

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
                ViolationCode::PatternMismatch->at($pointer, self::SCHEMA_PATTERN),
            ]);
        }

        return $input;
    }

    #[Override]
    public function encode(mixed $value): string
    {
        if (!is_string($value) || preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException('the value must be a string of decimal digits without a leading zero');
        }

        return $value;
    }

    #[Override]
    public function schema(): Schema
    {
        return new Schema(type: JsonType::String, pattern: self::SCHEMA_PATTERN);
    }

    #[Override]
    public function segmentPattern(): string
    {
        return self::SEGMENT;
    }
}
