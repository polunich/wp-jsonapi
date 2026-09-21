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

namespace Polunich\WpJsonApi\Declaration;

use Closure;
use InvalidArgumentException;
use Polunich\WpJsonApi\Metadata\ImplementationReference;

/**
 * the project object a declaration binds to a port: a container entry, an instance,
 * or a factory closure, which reaches a container that is not PSR-11.
 * an entry is looked up only when the implementation is resolved, never at build time,
 * so an API builds without a container
 */
final readonly class Implementation
{
    private function __construct(private ImplementationReference $reference) {}

    /**
     * @throws InvalidArgumentException when the entry id is empty
     */
    public static function service(string $entryId): self
    {
        return new self(ImplementationReference::service($entryId));
    }

    /**
     * @param object $implementation an instance of the port it is bound to
     */
    public static function instance(object $implementation): self
    {
        return new self(ImplementationReference::instance($implementation));
    }

    /**
     * @param Closure(): object $factory called when the implementation is resolved;
     *                                   returns an instance of the port it is bound to
     */
    public static function factory(Closure $factory): self
    {
        return new self(ImplementationReference::factory($factory));
    }

    /**
     * @internal
     */
    public function build(): ImplementationReference
    {
        return $this->reference;
    }
}
