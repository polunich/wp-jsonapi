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

namespace Polunich\WpJsonApi\Execution;

use LogicException;
use Override;
use Polunich\WpJsonApi\Metadata\ImplementationReference;
use Polunich\WpJsonApi\Metadata\ImplementationResolver;
use Polunich\WpJsonApi\Text\Printable;
use Psr\Container\ContainerInterface;
use UnexpectedValueException;

/**
 * nothing is cached: whether an entry is shared is the container's decision,
 * and a factory runs on every resolution.
 * a project that wants one hand-built implementation binds it as an instance.
 * an exception of the container passes through unwrapped,
 * so an exception mapper registered for its class still matches it
 *
 * @internal
 */
final readonly class ContainerImplementationResolver implements ImplementationResolver
{
    public function __construct(private ?ContainerInterface $container = null) {}

    /**
     * @template T of object
     *
     * @param class-string<T> $port
     *
     * @return T
     *
     * @throws LogicException           when the reference names a container entry
     *                                  and no container was given, which registration refuses first
     * @throws UnexpectedValueException when the implementation is no instance of `$port`
     */
    #[Override]
    public function resolve(ImplementationReference $reference, string $port): object
    {
        if ($reference->entryId !== null) {
            $implementation = $this->fromContainer($reference->entryId);
        } elseif ($reference->factory !== null) {
            $implementation = ($reference->factory)();
        } else {
            $implementation = $reference->instance;
        }

        if (!$implementation instanceof $port) {
            throw new UnexpectedValueException(sprintf(
                'the implementation bound as %s is %s, and an implementation is an instance of the port it is'
                . ' bound to',
                $port,
                get_debug_type($implementation),
            ));
        }

        return $implementation;
    }

    /**
     * @throws LogicException when no container was given
     */
    private function fromContainer(string $entryId): mixed
    {
        $container = $this->container ?? throw new LogicException(sprintf(
            'the implementation of the container entry "%s" cannot be resolved, the API having been built without'
            . ' a container',
            Printable::of($entryId),
        ));

        return $container->get($entryId);
    }
}
