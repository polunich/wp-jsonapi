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

namespace Polunich\WpJsonApi\Query\Inclusion;

use InvalidArgumentException;
use Override;
use Polunich\WpJsonApi\Text\Printable;
use Stringable;

/**
 * the relationship paths of one `include` parameter, in the client's order.
 *
 * the empty set stands both for `include=` and for no `include` at all;
 * they differ only in whether the request carries the parameter.
 * a repeated path is kept once, at its first place, not refused:
 * the spec prescribes 400 only for a path the server "is unable to identify" or "does not support"
 */
final readonly class IncludePaths implements Stringable
{
    private const SEPARATOR = ',';

    /**
     * @var list<IncludePath>
     */
    private array $paths;

    /**
     * @param list<IncludePath> $paths
     */
    public function __construct(array $paths)
    {
        $unique = [];

        foreach ($paths as $path) {
            $unique[$path->toString()] ??= $path;
        }

        $this->paths = array_values($unique);
    }

    /**
     * @return list<IncludePath>
     */
    public function all(): array
    {
        return $this->paths;
    }

    public function has(IncludePath $path): bool
    {
        $written = $path->toString();

        foreach ($this->paths as $known) {
            if ($known->toString() === $written) {
                return true;
            }
        }

        return false;
    }

    public function isEmpty(): bool
    {
        return $this->paths === [];
    }

    /**
     * on the relationship route and in its writes, every path starts with the route's relationship,
     * whose linkage is the primary data every included resource is reached from
     *
     * @internal
     *
     * @throws InvalidArgumentException when a path starts with another relationship
     */
    public function checkRootedAt(string $relationship, string $parentType): void
    {
        foreach ($this->paths as $path) {
            if ($path->relationships()[0] !== $relationship) {
                throw new InvalidArgumentException(sprintf(
                    'include path "%s" does not start with relationship "%s" of a parent of type "%s", whose'
                    . ' linkage is the primary data every included resource is reached from',
                    $path->toString(),
                    Printable::of($relationship),
                    Printable::of($parentType),
                ));
            }
        }
    }

    public function toString(): string
    {
        return implode(self::SEPARATOR, array_map(
            static function (IncludePath $path): string {
                return $path->toString();
            },
            $this->paths,
        ));
    }

    #[Override]
    public function __toString(): string
    {
        return $this->toString();
    }
}
