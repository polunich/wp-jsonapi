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
 * @template T
 *
 * @implements Codec<list<T>>
 */
final readonly class ListCodec implements Codec
{
    /**
     * @param Codec<T> $items
     */
    public function __construct(
        private Codec $items,
        private ?int $minItems = null,
        private ?int $maxItems = null,
    ) {
        if ($minItems !== null && $minItems < 0) {
            throw new InvalidArgumentException('the minimum number of items must not be negative');
        }

        if ($maxItems !== null && $maxItems < 0) {
            throw new InvalidArgumentException('the maximum number of items must not be negative');
        }

        if ($minItems !== null && $maxItems !== null && $minItems > $maxItems) {
            throw new InvalidArgumentException('the minimum number of items must not be greater than the maximum');
        }
    }

    /**
     * @return list<T>
     */
    #[Override]
    public function decode(mixed $input, Pointer $pointer): array
    {
        if (!is_array($input) || !array_is_list($input)) {
            throw new DecodeException([
                ViolationCode::InvalidType->at($pointer, JsonType::Array->value),
            ]);
        }

        $violations = $this->countViolations(count($input), $pointer);
        $values = [];

        foreach ($input as $index => $item) {
            try {
                $values[] = $this->items->decode($item, $pointer->descend((string) $index));
            } catch (DecodeException $exception) {
                foreach ($exception->violations() as $violation) {
                    $violations[] = $violation;
                }
            }
        }

        if ($violations !== []) {
            throw new DecodeException($violations);
        }

        return $values;
    }

    /**
     * @return list<mixed>
     */
    #[Override]
    public function encode(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('the value must be a list');
        }

        $violations = $this->countViolations(count($value), Pointer::root());

        if ($violations !== []) {
            throw DecodeException::encodeRefusal($violations);
        }

        $encoded = [];

        foreach ($value as $item) {
            $encoded[] = $this->items->encode($item);
        }

        return $encoded;
    }

    #[Override]
    public function schema(): Schema
    {
        return new Schema(
            type: JsonType::Array,
            items: $this->items->schema(),
            minItems: $this->minItems,
            maxItems: $this->maxItems,
        );
    }

    /**
     * @return list<Violation>
     */
    private function countViolations(int $count, Pointer $pointer): array
    {
        $violations = [];

        if ($this->minItems !== null && $count < $this->minItems) {
            $violations[] = ViolationCode::TooFewItems->at($pointer, $this->minItems);
        }

        if ($this->maxItems !== null && $count > $this->maxItems) {
            $violations[] = ViolationCode::TooManyItems->at($pointer, $this->maxItems);
        }

        return $violations;
    }
}
