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
use stdClass;

/**
 * a decoded `{"0":"a","1":"b"}` passes `array_is_list()`:
 * a decoded map is known by its codec, never by the shape of its keys
 *
 * @template T
 *
 * @implements Codec<array<int|string, T>>
 */
final readonly class MapCodec implements Codec
{
    /**
     * @param Codec<T> $values
     */
    public function __construct(private Codec $values) {}

    /**
     * @return array<int|string, T>
     */
    #[Override]
    public function decode(mixed $input, Pointer $pointer): array
    {
        if (!$input instanceof stdClass) {
            throw new DecodeException([
                ViolationCode::InvalidType->at($pointer, JsonType::Object->value),
            ]);
        }

        $violations = [];
        $decoded = [];

        foreach (get_object_vars($input) as $key => $member) {
            $name = (string) $key;

            try {
                $decoded[$name] = $this->values->decode($member, $pointer->descend($name));
            } catch (DecodeException $exception) {
                foreach ($exception->violations() as $violation) {
                    $violations[] = $violation;
                }
            }
        }

        if ($violations !== []) {
            throw new DecodeException($violations);
        }

        return $decoded;
    }

    #[Override]
    public function encode(mixed $value): stdClass
    {
        if (!is_array($value)) {
            throw new InvalidArgumentException('the value must be an array of members');
        }

        $encoded = [];

        foreach ($value as $key => $member) {
            $encoded[(string) $key] = $this->values->encode($member);
        }

        return (object) $encoded;
    }

    #[Override]
    public function schema(): Schema
    {
        return new Schema(type: JsonType::Object, additionalProperties: $this->values->schema());
    }
}
