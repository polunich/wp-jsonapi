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

namespace Polunich\WpJsonApi\Metadata;

use Closure;
use InvalidArgumentException;

/**
 * the implementation of a port the project provides,
 * named by exactly one of a container entry id, an instance,
 * or a factory closure, which reaches a container that is not PSR-11.
 * nothing is looked up or called until an `ImplementationResolver` resolves the reference,
 * so an API is declared without a container
 *
 * @internal
 */
final readonly class ImplementationReference
{
    private function __construct(
        public ?string $entryId,
        public ?object $instance,
        public ?Closure $factory,
    ) {}

    /**
     * @throws InvalidArgumentException when the entry id is empty
     */
    public static function service(string $entryId): self
    {
        if ($entryId === '') {
            throw new InvalidArgumentException('an implementation is looked up in the container by its entry id,'
                . ' which is never empty');
        }

        return new self($entryId, null, null);
    }

    public static function instance(object $implementation): self
    {
        return new self(null, $implementation, null);
    }

    /**
     * @param Closure(): object $factory
     */
    public static function factory(Closure $factory): self
    {
        return new self(null, null, $factory);
    }
}
