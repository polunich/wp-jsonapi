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
 * numbers compare by mathematical value, as in JSON Schema 2020-12 (core, section 4.2.2),
 * so a constant `1` accepts `1.0` and the other way round.
 * every other type compares by identity, and `true` never matches `1`
 *
 * @template T of string|int|float|bool|null
 *
 * @implements Codec<T>
 */
final readonly class ConstCodec implements Codec
{
    /**
     * @param T $value
     */
    public function __construct(private string|int|float|bool|null $value) {}

    /**
     * @return T
     */
    #[Override]
    public function decode(mixed $input, Pointer $pointer): string|int|float|bool|null
    {
        if ($this->isConstant($input)) {
            return $this->value;
        }

        $violation = $this->value === null
            ? ViolationCode::InvalidType->at($pointer, JsonType::Null->value)
            : ViolationCode::InvalidValue->at($pointer, $this->value);

        throw new DecodeException([$violation]);
    }

    /**
     * @return T
     */
    #[Override]
    public function encode(mixed $value): string|int|float|bool|null
    {
        if (!$this->isConstant($value)) {
            throw new InvalidArgumentException('the value must be the constant of the codec');
        }

        return $this->value;
    }

    #[Override]
    public function schema(): Schema
    {
        if ($this->value === null) {
            return new Schema(type: JsonType::Null);
        }

        return new Schema(type: self::typeOf($this->value), const: $this->value);
    }

    private function isConstant(mixed $value): bool
    {
        if (!is_int($value) && !is_float($value)) {
            return $value === $this->value;
        }

        if (!is_int($this->value) && !is_float($this->value)) {
            return false;
        }

        if (is_int($value) && is_int($this->value)) {
            return $value === $this->value;
        }

        return (float) $value === (float) $this->value;
    }

    private static function typeOf(string|int|float|bool $value): JsonType
    {
        return match (true) {
            is_bool($value) => JsonType::Boolean,
            is_int($value) => JsonType::Integer,
            is_float($value) => JsonType::Number,
            default => JsonType::String,
        };
    }
}
