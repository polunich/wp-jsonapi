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

namespace Polunich\WpJsonApi\Access;

use InvalidArgumentException;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Text\Printable;

/**
 * `$type` is the type of the collection, of the resource or of the parent.
 * for an atomic request `$request` is the whole request, whichever of its operations is attempted.
 *
 * `forRecord()` refuses a kind whose `actsOnExistingResource()` is false:
 * a collection has no single record to check, and a resource to create has none yet.
 *
 * an attempt carries no operation: the `forKind()` check comes before any operation is built,
 * and the `forRecord()` check of a single write comes before its content is parsed
 */
final readonly class OperationAttempt
{
    /**
     * @throws InvalidArgumentException
     */
    private function __construct(
        public OperationKind $kind,
        public string $type,
        public ?string $relationship,
        public ServerRequest $request,
        public ?object $record,
    ) {
        if ($type === '') {
            throw new InvalidArgumentException(sprintf(
                'an attempted %s operation names a resource type, and a type is never empty',
                $kind->value,
            ));
        }

        if ($relationship === null && $kind->targetsRelationship()) {
            throw new InvalidArgumentException(sprintf(
                'an attempted %s operation targets a relationship of a parent of type "%s", and it names none',
                $kind->value,
                Printable::of($type),
            ));
        }

        if ($relationship !== null && !$kind->targetsRelationship()) {
            throw new InvalidArgumentException(sprintf(
                'an attempted %s operation on type "%s" targets no relationship, and it names relationship "%s"',
                $kind->value,
                Printable::of($type),
                Printable::of($relationship),
            ));
        }

        if ($relationship === '') {
            throw new InvalidArgumentException(sprintf(
                'an attempted %s operation targets a relationship of a parent of type "%s", whose name is never empty',
                $kind->value,
                Printable::of($type),
            ));
        }

        if ($record !== null && !$kind->actsOnExistingResource()) {
            throw new InvalidArgumentException(sprintf(
                'an attempted %s operation acts on no existing resource, so its permission is checked without a'
                . ' record',
                $kind->value,
            ));
        }
    }

    /**
     * @throws InvalidArgumentException when the type or the relationship is empty,
     *                                  or the relationship does not match the kind
     */
    public static function forKind(
        OperationKind $kind,
        string $type,
        ?string $relationship,
        ServerRequest $request,
    ): self {
        return new self($kind, $type, $relationship, $request, null);
    }

    /**
     * @throws InvalidArgumentException when the type or the relationship is empty,
     *                                  the relationship does not match the kind,
     *                                  or the kind acts on no existing resource
     */
    public static function forRecord(
        OperationKind $kind,
        string $type,
        ?string $relationship,
        ServerRequest $request,
        object $record,
    ): self {
        return new self($kind, $type, $relationship, $request, $record);
    }
}
