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

use Closure;
use InvalidArgumentException;
use Override;
use Polunich\WpJsonApi\Codec\Schema\JsonType;
use Polunich\WpJsonApi\Codec\Schema\Schema;
use Polunich\WpJsonApi\Text\Printable;
use stdClass;
use TypeError;

/**
 * the value of one discriminating member picks the variant, which decodes the whole object.
 *
 * each variant should pin that member with a `ConstCodec`,
 * since `oneOf` requires exactly one subschema to match.
 *
 * a discriminator value of digits is an int key in `$variants`, hence the `int|string` key type;
 * the string the document carries still finds it, because PHP casts a digit string key on lookup
 *
 * @template T
 *
 * @implements Codec<T>
 */
final readonly class OneOfCodec implements Codec
{
    /**
     * @param array<int|string, Codec<T>> $variants        discriminator value => codec
     * @param Closure(T): string          $discriminatorOf the discriminator of a decoded value
     */
    public function __construct(
        private string $discriminator,
        private array $variants,
        private Closure $discriminatorOf,
    ) {
        if ($variants === []) {
            throw new InvalidArgumentException('the codec must have at least one variant');
        }
    }

    /**
     * @return T
     */
    #[Override]
    public function decode(mixed $input, Pointer $pointer): mixed
    {
        if (!$input instanceof stdClass) {
            throw new DecodeException([
                ViolationCode::InvalidType->at($pointer, JsonType::Object->value),
            ]);
        }

        $members = get_object_vars($input);

        if (!array_key_exists($this->discriminator, $members)) {
            throw new DecodeException([
                ViolationCode::MissingMember->at($pointer, $this->discriminator),
            ]);
        }

        $member = $pointer->descend($this->discriminator);
        $value = $members[$this->discriminator];

        if (!is_string($value)) {
            throw new DecodeException([
                ViolationCode::InvalidType->at($member, JsonType::String->value),
            ]);
        }

        $variant = $this->variants[$value] ?? null;

        if ($variant === null) {
            throw new DecodeException([
                ViolationCode::ValueNotAllowed->at($member, implode(', ', array_keys($this->variants))),
            ]);
        }

        return $variant->decode($input, $pointer);
    }

    /**
     * a value of the wrong type fails the parameter type of `$discriminatorOf` with a `TypeError`,
     * rethrown as the `InvalidArgumentException` `Codec::encode()` promises
     *
     * @param T $value
     */
    #[Override]
    public function encode(mixed $value): mixed
    {
        try {
            $key = ($this->discriminatorOf)($value);
        } catch (TypeError $error) {
            throw new InvalidArgumentException('the value is not one this codec decodes', 0, $error);
        }

        if (!isset($this->variants[$key])) {
            throw new InvalidArgumentException('the value belongs to no declared'
                . ' variant: ' . Printable::of((string) $key));
        }

        return $this->variants[$key]->encode($value);
    }

    #[Override]
    public function schema(): Schema
    {
        $variants = [];

        foreach ($this->variants as $variant) {
            $variants[] = $variant->schema();
        }

        return new Schema(oneOf: $variants);
    }
}
