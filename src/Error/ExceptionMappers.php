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

namespace Polunich\WpJsonApi\Error;

use InvalidArgumentException;
use LogicException;
use Polunich\WpJsonApi\Text\Printable;
use ReflectionClass;
use Throwable;

/**
 * turns any throwable into the library exception its response is built from,
 * by the exception mapping rules of Jakarta RESTful Web Services 3.1:
 *
 * - a `JsonApiException` maps to itself, since it already describes its response;
 * - otherwise the mapper of the nearest type wins (section 4.4);
 * - a throwable no mapper claims, and a throwable a mapper throws, go to the default mapper
 *   and become 500 (sections 4.4 and 3.3.4);
 *   what a mapper throws is never mapped again, but becomes the previous exception
 *   of a `LogicException` naming the mapper and the throwable it was mapping.
 *
 * Java compares classes only; PHP adds interfaces, `Throwable` among them,
 * so the nearest type is decided by three keys in order:
 *
 * 1. the level, lowest first: the position, on the chain from the class of the throwable
 *    up through its parents, of the farthest class that is the type or implements it.
 *    an interface keeps the level of the class that brings it in,
 *    even where a nearer class declares it again;
 * 2. within one level, a subtype before its supertype;
 * 3. between unrelated types of one level, the mapper registered first,
 *    where section 4.4 lets priority decide.
 *
 * a subtype never has a higher level than its supertype,
 * so the second key never contradicts the first,
 * and for related types the chosen mapper does not depend on the order of registration.
 *
 * a mapper that could never be chosen is refused at construction
 *
 * @internal
 */
final readonly class ExceptionMappers
{
    /**
     * in registration order, which decides between equally near types;
     * each type spelled as its declaration
     *
     * @var list<array{class-string<Throwable>, ExceptionMapper<Throwable>}>
     */
    private array $mappers;

    /**
     * @param list<ExceptionMapper<Throwable>> $mappers
     *
     * @throws InvalidArgumentException when a mapper names no throwable class or interface,
     *                                  a `JsonApiException` type, or a type an earlier mapper names
     */
    public function __construct(array $mappers, private DefaultExceptionMapper $defaultMapper)
    {
        $registered = [];

        foreach ($mappers as $mapper) {
            $type = $mapper->exceptionClass();

            // `class-string` is a docblock promise PHP does not enforce
            if (!class_exists($type) && !interface_exists($type)) {
                throw new InvalidArgumentException(
                    'an exception mapper maps a class or an interface, got "' . Printable::of($type) . '"',
                );
            }

            $reflection = new ReflectionClass($type);

            if (!$reflection->implementsInterface(Throwable::class)) {
                throw new InvalidArgumentException(
                    'an exception mapper maps a throwable, got ' . Printable::of($reflection->getName()),
                );
            }

            // class names are case-insensitive:
            // two spellings of one type compare by the declared name
            $name = $reflection->getName();

            if (is_a($name, JsonApiException::class, true)) {
                throw new InvalidArgumentException(
                    'an exception mapper cannot map ' . Printable::of($name)
                    . ': a JSON:API exception maps to itself',
                );
            }

            foreach ($registered as [$earlier]) {
                if ($earlier === $name) {
                    throw new InvalidArgumentException(
                        'two exception mappers map ' . Printable::of($name)
                        . ', and only the first could ever be chosen',
                    );
                }
            }

            $registered[] = [$name, $mapper];
        }

        $this->mappers = $registered;
    }

    public function map(Throwable $throwable): JsonApiException
    {
        if ($throwable instanceof JsonApiException) {
            return $throwable;
        }

        $nearest = $this->nearest($throwable);

        if ($nearest === null) {
            return $this->defaultMapper->map($throwable);
        }

        [$type, $mapper] = $nearest;

        try {
            return $mapper->map($throwable);
        } catch (Throwable $failure) {
            return $this->defaultMapper->map(new LogicException(
                'the exception mapper of ' . Printable::of($type) . ' threw while it mapped '
                . Printable::of($throwable::class) . ' "' . Printable::of($throwable->getMessage()) . '"',
                0,
                $failure,
            ));
        }
    }

    /**
     * @return array{class-string<Throwable>, ExceptionMapper<Throwable>}|null
     */
    private function nearest(Throwable $throwable): ?array
    {
        $chain = [];

        for ($class = new ReflectionClass($throwable); $class !== false; $class = $class->getParentClass()) {
            $chain[] = $class;
        }

        $nearest = [];
        $nearestLevel = null;

        foreach ($this->mappers as $registered) {
            if (!$throwable instanceof $registered[0]) {
                continue;
            }

            $level = self::level($chain, $registered[0]);

            if ($nearestLevel === null || $level < $nearestLevel) {
                $nearest = [$registered];
                $nearestLevel = $level;
            } elseif ($level === $nearestLevel) {
                $nearest[] = $registered;
            }
        }

        foreach ($nearest as $candidate) {
            foreach ($nearest as $other) {
                if (is_subclass_of($other[0], $candidate[0])) {
                    continue 2;
                }
            }

            return $candidate;
        }

        return null;
    }

    /**
     * @param list<ReflectionClass<object>> $chain the class of the throwable, then each parent
     * @param class-string<Throwable>       $type  a type the throwable is an instance of
     */
    private static function level(array $chain, string $type): int
    {
        $level = 0;

        foreach ($chain as $position => $class) {
            if ($class->getName() === $type || $class->isSubclassOf($type)) {
                $level = $position;
            }
        }

        return $level;
    }
}
