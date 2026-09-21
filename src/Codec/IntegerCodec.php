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
 * a float with a zero fractional part decodes to the integer it names:
 * JSON Schema 2020-12 (validation, section 6.1.1) counts such a number as an integer.
 *
 * a whole number beyond the platform range is out of range, not of another type,
 * so the schema always writes `minimum` and `maximum`, the platform bounds when none is declared.
 * an infinite float names no number and is refused as another type
 *
 * @implements Codec<int>
 */
final readonly class IntegerCodec implements Codec
{
    /**
     * `-2^63` is exact as a float too, so both wire forms of it decode to the same integer
     */
    private const MINIMUM = PHP_INT_MIN;

    /**
     * the largest integer a float still names exactly: doubles below `2^63` lie 1024 apart.
     * a JSON number above `PHP_INT_MAX` arrives as a float, so the bound applies to an int as well:
     * one published maximum holds for both wire forms, and the float-to-int cast stays exact
     */
    private const MAXIMUM = 9223372036854774784;

    /**
     * a bound above `MAXIMUM` is refused. as a maximum it would admit floats no int names exactly;
     * as a minimum it would exceed the effective maximum, so no number could pass
     */
    public function __construct(private ?int $minimum = null, private ?int $maximum = null)
    {
        if ($minimum !== null && $minimum > self::MAXIMUM) {
            throw new InvalidArgumentException(
                'the minimum must not be above the largest integer a JSON number names exactly: ' . self::MAXIMUM,
            );
        }

        if ($maximum !== null && $maximum > self::MAXIMUM) {
            throw new InvalidArgumentException(
                'the maximum must not be above the largest integer a JSON number names exactly: ' . self::MAXIMUM,
            );
        }

        if ($minimum !== null && $maximum !== null && $minimum > $maximum) {
            throw new InvalidArgumentException('the minimum must not be greater than the maximum');
        }
    }

    #[Override]
    public function decode(mixed $input, Pointer $pointer): int
    {
        if (is_int($input)) {
            $number = $input;
        } elseif (is_float($input) && is_finite($input) && floor($input) === $input) {
            $number = $input;
        } else {
            throw new DecodeException([
                ViolationCode::InvalidType->at($pointer, JsonType::Integer->value),
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

        return (int) $number;
    }

    #[Override]
    public function encode(mixed $value): int
    {
        if (!is_int($value)) {
            throw new InvalidArgumentException('the value must be an integer');
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
            type: JsonType::Integer,
            minimum: $this->minimum ?? self::MINIMUM,
            maximum: $this->maximum ?? self::MAXIMUM,
        );
    }
}
