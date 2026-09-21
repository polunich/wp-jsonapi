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

namespace Polunich\WpJsonApi\Document;

/**
 * link name => link, encoded in insertion order.
 * a null value is a link that does not exist, which the specification writes as `null`
 *
 * @internal
 */
final readonly class Links
{
    /**
     * @var array<array-key, Link|null>
     */
    private array $links;

    /**
     * @param array<array-key, Link|string|null> $links a string is an href
     */
    public function __construct(array $links)
    {
        $normalized = [];

        foreach ($links as $name => $link) {
            $normalized[$name] = is_string($link) ? new Link($link) : $link;
        }

        $this->links = $normalized;
    }

    /**
     * @return array<array-key, Link|null>
     */
    public function all(): array
    {
        return $this->links;
    }

    /**
     * a name whose value is null carries no link,
     * so it does not satisfy a required `self` or `related`
     */
    public function has(string $name): bool
    {
        return ($this->links[$name] ?? null) !== null;
    }

    public function isEmpty(): bool
    {
        return $this->links === [];
    }
}
