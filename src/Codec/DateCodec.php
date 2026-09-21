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
use DateTimeZone;
use InvalidArgumentException;
use Override;
use Polunich\WpJsonApi\Codec\Schema\Format;
use Polunich\WpJsonApi\Codec\Schema\JsonType;
use Polunich\WpJsonApi\Codec\Schema\Schema;

/**
 * the `full-date` of RFC 3339, the `date` format of JSON Schema.
 * PHP has no date-only type, so a decoded day is a `DateTimeImmutable` at midnight UTC
 *
 * @implements Codec<DateTimeImmutable>
 */
final readonly class DateCodec implements Codec
{
    private const PATTERN = '/^(\d{4})-(\d{2})-(\d{2})$/D';

    #[Override]
    public function decode(mixed $input, Pointer $pointer): DateTimeImmutable
    {
        if (!is_string($input)) {
            throw new DecodeException([
                ViolationCode::InvalidType->at($pointer, JsonType::String->value),
            ]);
        }

        $parts = [];

        if (preg_match(self::PATTERN, $input, $parts) !== 1) {
            throw new DecodeException([
                ViolationCode::InvalidFormat->at($pointer, Format::Date->value),
            ]);
        }

        $year = (int) $parts[1];
        $month = (int) $parts[2];
        $day = (int) $parts[3];

        if (!checkdate($month, $day, $year)) {
            throw new DecodeException([
                ViolationCode::InvalidFormat->at($pointer, Format::Date->value),
            ]);
        }

        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setDate($year, $month, $day)
            ->setTime(0, 0);
    }

    #[Override]
    public function encode(mixed $value): string
    {
        if (!$value instanceof DateTimeInterface) {
            throw new InvalidArgumentException('the value must implement ' . DateTimeInterface::class);
        }

        return $value->format('Y-m-d');
    }

    #[Override]
    public function schema(): Schema
    {
        return new Schema(type: JsonType::String, format: Format::Date);
    }
}
