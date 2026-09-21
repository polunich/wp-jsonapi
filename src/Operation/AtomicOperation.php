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

namespace Polunich\WpJsonApi\Operation;

use Closure;
use InvalidArgumentException;
use LogicException;
use Polunich\WpJsonApi\Text\Printable;

/**
 * one operation of an atomic request before it is built.
 *
 * a lid names a resource a prior operation created, and operations run in order,
 * so the id a lid stands for exists only once that operation has run.
 * `build()` builds the operation then, asking for the id of each lid.
 *
 * `$kind`, `$type` and `$relationship` are known before building,
 * so every operation can be checked before the first one runs,
 * and `build()` refuses a built operation that differs in them.
 * the kind is always a write: the atomic extension has no op code for a read
 *
 * @internal
 */
final readonly class AtomicOperation
{
    /**
     * @param ?string $lid the lid a create gives its resource
     * @param Closure(Closure(string $type, string $lid): string): Operation $build
     *
     * @throws InvalidArgumentException when the kind is a fetch or does not match the relationship,
     *                                  a lid comes with a kind that creates no resource,
     *                                  or the type, the relationship or the lid is empty
     */
    public function __construct(
        public OperationKind $kind,
        public string $type,
        public ?string $relationship,
        public ?string $lid,
        private Closure $build,
    ) {
        if ($kind->isFetch()) {
            throw new InvalidArgumentException(sprintf(
                'an atomic operation adds, updates or removes, and this one is %s',
                $kind->value,
            ));
        }

        if ($type === '') {
            throw new InvalidArgumentException(sprintf(
                'an atomic %s operation names a resource type, and a type is never empty',
                $kind->value,
            ));
        }

        if ($relationship === null && $kind->targetsRelationship()) {
            throw new InvalidArgumentException(sprintf(
                'an atomic %s operation on type "%s" targets a relationship, and it names none',
                $kind->value,
                Printable::of($type),
            ));
        }

        if ($relationship !== null && !$kind->targetsRelationship()) {
            throw new InvalidArgumentException(sprintf(
                'an atomic %s operation on type "%s" targets no relationship, and it names relationship "%s"',
                $kind->value,
                Printable::of($type),
                Printable::of($relationship),
            ));
        }

        if ($relationship === '') {
            throw new InvalidArgumentException(sprintf(
                'an atomic %s operation on type "%s" targets a relationship, whose name is never empty',
                $kind->value,
                Printable::of($type),
            ));
        }

        if ($lid !== null && $kind !== OperationKind::CreateResource) {
            throw new InvalidArgumentException(sprintf(
                'an atomic %s operation on type "%s" creates no resource, so it gives no lid',
                $kind->value,
                Printable::of($type),
            ));
        }

        if ($lid === '') {
            throw new InvalidArgumentException(sprintf(
                'an atomic %s operation gives the resource of type "%s" an empty lid, which identifies no resource',
                $kind->value,
                Printable::of($type),
            ));
        }
    }

    /**
     * an exception of `$idOfLid` or of the build closure passes through unchanged
     *
     * @param Closure(string $type, string $lid): string $idOfLid
     *
     * @throws LogicException when the built operation differs in its kind, type or relationship
     */
    public function build(Closure $idOfLid): Operation
    {
        $operation = ($this->build)($idOfLid);

        if (
            $operation->kind() !== $this->kind
            || $operation->type() !== $this->type
            || $operation->relationship() !== $this->relationship
        ) {
            throw new LogicException(sprintf(
                'the atomic operation %s was built as %s, and building keeps the kind, the type and the relationship',
                self::describe($this->kind, $this->type, $this->relationship),
                self::describe($operation->kind(), $operation->type(), $operation->relationship()),
            ));
        }

        return $operation;
    }

    private static function describe(OperationKind $kind, string $type, ?string $relationship): string
    {
        if ($relationship === null) {
            return sprintf('%s on type "%s"', $kind->value, Printable::of($type));
        }

        return sprintf(
            '%s on relationship "%s" of type "%s"',
            $kind->value,
            Printable::of($relationship),
            Printable::of($type),
        );
    }
}
