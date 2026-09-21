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
use Polunich\WpJsonApi\Codec\Schema\Schema;
use stdClass;
use TypeError;

/**
 * @template TIn
 * @template TOut of object
 *
 * @implements Codec<TOut>
 */
final readonly class MappedCodec implements Codec
{
    /**
     * an array index of RFC 6901, section 4: `0`, or digits without a leading zero
     */
    private const INDEX = '/^(?:0|[1-9][0-9]*)$/D';

    /**
     * @param Codec<TIn>         $inner
     * @param Closure(TIn): TOut $toValue   may throw `RejectedValueException`
     * @param Closure(TOut): TIn $fromValue
     */
    public function __construct(
        private Codec $inner,
        private Closure $toValue,
        private Closure $fromValue,
    ) {}

    /**
     * @return TOut
     */
    #[Override]
    public function decode(mixed $input, Pointer $pointer): object
    {
        $decoded = $this->inner->decode($input, $pointer);

        try {
            return ($this->toValue)($decoded);
        } catch (RejectedValueException $exception) {
            throw new DecodeException(
                [new Violation(
                    self::descend($pointer, $input, $exception->path()),
                    $exception->violationCode(),
                    $exception->parameters(),
                )],
                $exception,
            );
        }
    }

    /**
     * a value of the wrong type fails the parameter type of `$fromValue` with a `TypeError`,
     * rethrown as the `InvalidArgumentException` `Codec::encode()` promises
     *
     * @param TOut $value
     */
    #[Override]
    public function encode(mixed $value): mixed
    {
        try {
            $inner = ($this->fromValue)($value);
        } catch (TypeError $error) {
            throw new InvalidArgumentException('the value is not one this codec decodes', 0, $error);
        }

        return $this->inner->encode($inner);
    }

    #[Override]
    public function schema(): Schema
    {
        return $this->inner->schema();
    }

    /**
     * follows the path as far as the input holds it:
     * an error source "MUST point to a value in the request document that exists"
     * (JSON:API, Error Objects), so the violation stops at the deepest value the input holds
     *
     * @param list<string> $path
     */
    private static function descend(Pointer $pointer, mixed $input, array $path): Pointer
    {
        foreach ($path as $token) {
            if ($input instanceof stdClass) {
                $members = get_object_vars($input);

                if (!array_key_exists($token, $members)) {
                    break;
                }

                $input = $members[$token];
            } elseif (
                is_array($input)
                && preg_match(self::INDEX, $token) === 1
                && array_key_exists((int) $token, $input)
            ) {
                $input = $input[(int) $token];
            } else {
                break;
            }

            $pointer = $pointer->descend($token);
        }

        return $pointer;
    }
}
