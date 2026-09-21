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
use Polunich\WpJsonApi\Text\Printable;

/**
 * a length counts code points, as JSON Schema 2020-12 (validation, section 6.3.2) defines it.
 * `pattern` is a search, not a whole-string match: section 6.3.3 does not anchor it
 *
 * @implements Codec<string>
 */
final readonly class StringCodec implements Codec
{
    /**
     * BEL as the delimiter leaves every printable character of a pattern unescaped.
     * `compile()` adds `u`, so `.` is one code point, and `D`, so `$` is the end of the subject,
     * the way opis/json-schema compiles the keyword
     */
    private const PATTERN_DELIMITER = "\x07";

    public function __construct(
        private ?int $minLength = null,
        private ?int $maxLength = null,
        private ?string $pattern = null,
    ) {
        if ($minLength !== null && $minLength < 0) {
            throw new InvalidArgumentException('the minimum length must not be negative');
        }

        if ($maxLength !== null && $maxLength < 0) {
            throw new InvalidArgumentException('the maximum length must not be negative');
        }

        if ($minLength !== null && $maxLength !== null && $minLength > $maxLength) {
            throw new InvalidArgumentException('the minimum length must not be greater than the maximum length');
        }

        if ($pattern !== null && @preg_match(self::compile($pattern), '') === false) {
            throw new InvalidArgumentException('the pattern is not a valid regular'
                . ' expression: ' . Printable::of($pattern));
        }
    }

    #[Override]
    public function decode(mixed $input, Pointer $pointer): string
    {
        if (!is_string($input)) {
            throw new DecodeException([
                ViolationCode::InvalidType->at($pointer, JsonType::String->value),
            ]);
        }

        $violations = [];
        $length = self::length($input);

        if ($this->minLength !== null && $length < $this->minLength) {
            $violations[] = ViolationCode::ValueTooShort->at($pointer, $this->minLength);
        }

        if ($this->maxLength !== null && $length > $this->maxLength) {
            $violations[] = ViolationCode::ValueTooLong->at($pointer, $this->maxLength);
        }

        if ($this->pattern !== null && preg_match(self::compile($this->pattern), $input) !== 1) {
            $violations[] = ViolationCode::PatternMismatch->at($pointer, $this->pattern);
        }

        if ($violations !== []) {
            throw new DecodeException($violations);
        }

        return $input;
    }

    #[Override]
    public function encode(mixed $value): string
    {
        if (!is_string($value)) {
            throw new InvalidArgumentException('the value must be a string');
        }

        try {
            return $this->decode($value, Pointer::root());
        } catch (DecodeException $refusal) {
            throw DecodeException::encodeRefusal($refusal->violations(), $refusal);
        }
    }

    #[Override]
    public function schema(): Schema
    {
        return new Schema(
            type: JsonType::String,
            minLength: $this->minLength,
            maxLength: $this->maxLength,
            pattern: $this->pattern,
        );
    }

    private static function compile(string $pattern): string
    {
        return self::PATTERN_DELIMITER . $pattern . self::PATTERN_DELIMITER . 'uD';
    }

    /**
     * invalid UTF-8, which no decoded JSON text holds, is counted in bytes
     */
    private static function length(string $value): int
    {
        $length = preg_match_all('/./us', $value);

        return $length === false ? strlen($value) : $length;
    }
}
