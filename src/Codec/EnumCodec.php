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

use BackedEnum;
use InvalidArgumentException;
use Override;
use Polunich\WpJsonApi\Codec\Schema\JsonType;
use Polunich\WpJsonApi\Codec\Schema\Schema;
use ReflectionEnum;

/**
 * a pure enum is refused at construction: its cases carry no value a document could hold.
 *
 * the backing value is decoded by `IntegerCodec` or `StringCodec`,
 * so an enum reads a JSON number as an integer does:
 * `{"type":"integer","enum":[1,2]}` accepts `1.0`, and so does this codec
 *
 * @template T of BackedEnum
 *
 * @implements Codec<T>
 */
final readonly class EnumCodec implements Codec
{
    /**
     * @var non-empty-list<T>
     */
    private array $cases;

    private JsonType $jsonType;

    private IntegerCodec|StringCodec $backing;

    /**
     * @param class-string<T> $enumClass
     */
    public function __construct(private string $enumClass)
    {
        $backingType = (new ReflectionEnum($enumClass))->getBackingType();

        if ($backingType === null) {
            throw new InvalidArgumentException('the enum must be a backed enum: ' . $enumClass);
        }

        $cases = $enumClass::cases();

        if ($cases === []) {
            throw new InvalidArgumentException('the enum must declare at least one case');
        }

        $this->cases = $cases;
        $this->jsonType = $backingType->getName() === 'int' ? JsonType::Integer : JsonType::String;
        $this->backing = $this->jsonType === JsonType::Integer ? new IntegerCodec() : new StringCodec();
    }

    /**
     * @return T
     */
    #[Override]
    public function decode(mixed $input, Pointer $pointer): BackedEnum
    {
        $case = $this->enumClass::tryFrom($this->backingValue($input, $pointer));

        if ($case === null) {
            throw self::notAllowed($pointer, $this->allowedValues());
        }

        return $case;
    }

    #[Override]
    public function encode(mixed $value): int|string
    {
        if (!$value instanceof BackedEnum || !$value instanceof $this->enumClass) {
            throw new InvalidArgumentException('the value must be a case of the enum of the codec');
        }

        return $value->value;
    }

    #[Override]
    public function schema(): Schema
    {
        return new Schema(
            type: $this->jsonType,
            enum: array_map(static function (BackedEnum $case): int|string {
                return $case->value;
            }, $this->cases),
        );
    }

    /**
     * a range violation of the backing codec becomes `value_not_allowed`:
     * it would name a bound this schema never writes, and the number is no case either way.
     * a type violation passes through, since this schema names the same type
     */
    private function backingValue(mixed $input, Pointer $pointer): int|string
    {
        try {
            return $this->backing->decode($input, $pointer);
        } catch (DecodeException $exception) {
            foreach ($exception->violations() as $violation) {
                if ($violation->code === ViolationCode::InvalidType->value) {
                    throw $exception;
                }
            }

            throw self::notAllowed($pointer, $this->allowedValues());
        }
    }

    private static function notAllowed(Pointer $pointer, string $allowed): DecodeException
    {
        return new DecodeException([
            ViolationCode::ValueNotAllowed->at($pointer, $allowed),
        ]);
    }

    private function allowedValues(): string
    {
        return implode(', ', array_map(static function (BackedEnum $case): string {
            return (string) $case->value;
        }, $this->cases));
    }
}
