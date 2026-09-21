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

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Override;
use Polunich\WpJsonApi\Codec\Schema\Format;
use Polunich\WpJsonApi\Codec\Schema\JsonType;
use Polunich\WpJsonApi\Codec\Schema\Schema;

/**
 * the `date-time` of RFC 3339, the `date-time` format of JSON Schema.
 *
 * a decoded value keeps the offset of the text.
 * PHP holds microseconds, so a longer fraction is truncated,
 * and the leap second `60` becomes second `0` of the next minute.
 * `encode()` writes the fraction only when it is not zero; RFC 3339 makes it optional
 *
 * @implements Codec<DateTimeImmutable>
 */
final readonly class DateTimeCodec implements Codec
{
    private const PATTERN = '/^(\d{4})-(\d{2})-(\d{2})'
        . '[Tt](\d{2}):(\d{2}):(\d{2})(?:\.\d+)?'
        . '(?:[Zz]|[+-](\d{2}):(\d{2}))$/D';

    #[Override]
    public function decode(mixed $input, Pointer $pointer): DateTimeImmutable
    {
        if (!is_string($input)) {
            throw new DecodeException([
                ViolationCode::InvalidType->at($pointer, JsonType::String->value),
            ]);
        }

        $parts = [];

        if (preg_match(self::PATTERN, $input, $parts) !== 1 || !self::isRealMoment($parts)) {
            throw new DecodeException([
                ViolationCode::InvalidFormat->at($pointer, Format::DateTime->value),
            ]);
        }

        return new DateTimeImmutable($input);
    }

    #[Override]
    public function encode(mixed $value): string
    {
        if (!$value instanceof DateTimeInterface) {
            throw new InvalidArgumentException('the value must implement ' . DateTimeInterface::class);
        }

        return $value->format('u') === '000000'
            ? $value->format(DateTimeInterface::RFC3339)
            : $value->format('Y-m-d\TH:i:s.uP');
    }

    #[Override]
    public function schema(): Schema
    {
        return new Schema(type: JsonType::String, format: Format::DateTime);
    }

    /**
     * second `60` is the leap second RFC 3339 (section 5.6) allows;
     * with `Z` the offset groups are unset
     *
     * @param array<int, string> $parts
     */
    private static function isRealMoment(array $parts): bool
    {
        if (!checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return false;
        }

        if ((int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 60) {
            return false;
        }

        if (!isset($parts[7], $parts[8])) {
            return true;
        }

        return (int) $parts[7] <= 23 && (int) $parts[8] <= 59;
    }
}
