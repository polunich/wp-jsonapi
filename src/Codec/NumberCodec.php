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
 * every JSON number decodes to a float; an integer is a number too
 * (JSON Schema 2020-12, validation, section 6.1.1).
 *
 * a number beyond `PHP_FLOAT_MAX` arrives as an infinite float.
 * it is out of range, not of another type, so the schema always writes `minimum` and `maximum`,
 * the float limits when none is declared.
 * NaN, which no JSON text holds, is refused as another type: it stands in no order with a bound
 *
 * @implements Codec<float>
 */
final readonly class NumberCodec implements Codec
{
    private const MINIMUM = -PHP_FLOAT_MAX;

    private const MAXIMUM = PHP_FLOAT_MAX;

    public function __construct(private ?float $minimum = null, private ?float $maximum = null)
    {
        if ($minimum !== null && !is_finite($minimum)) {
            throw new InvalidArgumentException('the minimum must be a finite number');
        }

        if ($maximum !== null && !is_finite($maximum)) {
            throw new InvalidArgumentException('the maximum must be a finite number');
        }

        if ($minimum !== null && $maximum !== null && $minimum > $maximum) {
            throw new InvalidArgumentException('the minimum must not be greater than the maximum');
        }
    }

    #[Override]
    public function decode(mixed $input, Pointer $pointer): float
    {
        if (is_int($input)) {
            $number = (float) $input;
        } elseif (is_float($input) && !is_nan($input)) {
            $number = $input;
        } else {
            throw new DecodeException([
                ViolationCode::InvalidType->at($pointer, JsonType::Number->value),
            ]);
        }

        $minimum = $this->minimum ?? self::MINIMUM;
        $maximum = $this->maximum ?? self::MAXIMUM;

        if ($number < $minimum) {
            throw new DecodeException([
                ViolationCode::ValueTooSmall->at($pointer, $minimum),
            ]);
        }

        if ($number > $maximum) {
            throw new DecodeException([
                ViolationCode::ValueTooLarge->at($pointer, $maximum),
            ]);
        }

        return $number;
    }

    #[Override]
    public function encode(mixed $value): float
    {
        if (!is_int($value) && (!is_float($value) || !is_finite($value))) {
            throw new InvalidArgumentException('the value must be a finite number');
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
            type: JsonType::Number,
            minimum: $this->minimum ?? self::MINIMUM,
            maximum: $this->maximum ?? self::MAXIMUM,
        );
    }
}
