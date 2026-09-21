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

namespace Polunich\WpJsonApi\Tests\Fixtures\Metadata;

use Override;
use Polunich\WpJsonApi\Metadata\ImplementationReference;
use Polunich\WpJsonApi\Metadata\ImplementationResolver;
use UnexpectedValueException;

/**
 * resolves each port to the implementation bound for it, whatever the reference names,
 * and records the ports asked for, in the order asked
 *
 * @internal
 */
final class BoundImplementations implements ImplementationResolver
{
    /**
     * @var list<class-string>
     */
    public array $ports = [];

    /**
     * @param array<class-string, object> $bound port => implementation
     */
    private function __construct(
        private readonly array $bound,
        private readonly ?object $serving,
    ) {}

    /**
     * @param array<class-string, object> $implementations port => implementation
     */
    public static function of(array $implementations): self
    {
        return new self($implementations, null);
    }

    /**
     * every port the implementation implements resolves to it
     */
    public static function serving(object $implementation): self
    {
        return new self([], $implementation);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $port
     *
     * @return T
     */
    #[Override]
    public function resolve(ImplementationReference $reference, string $port): object
    {
        $this->ports[] = $port;
        $implementation = $this->bound[$port] ?? $this->serving;

        if (!$implementation instanceof $port) {
            throw new UnexpectedValueException(sprintf('no implementation is bound as %s', $port));
        }

        return $implementation;
    }
}
