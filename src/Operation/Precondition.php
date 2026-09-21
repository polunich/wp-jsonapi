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

use InvalidArgumentException;

/**
 * the If-Match condition of a conditional write, evaluated again inside the write itself,
 * since only the write is atomic.
 *
 * `anyVersion()` stands for `If-Match: *` and admits any version.
 * `listedVersions()` stands for a list of entity tags, each decoded to the version it carries,
 * so the write compares versions and never reads an entity tag.
 *
 * a version is an opaque string, the empty one included, as an entity tag may be `""`
 */
final readonly class Precondition
{
    /**
     * @param non-empty-list<string>|null $versions null for any version
     */
    private function __construct(
        public ?array $versions,
    ) {}

    public static function anyVersion(): self
    {
        return new self(null);
    }

    /**
     * a version listed twice is kept once, at its first position
     *
     * @param list<string> $versions in the order of the field
     *
     * @throws InvalidArgumentException when the list is empty, which would admit no record
     */
    public static function listedVersions(array $versions): self
    {
        if ($versions === []) {
            throw new InvalidArgumentException('a listed precondition names one or more versions, and this one names'
                . ' none');
        }

        return new self(array_values(array_unique($versions, SORT_STRING)));
    }

    /**
     * compares as strings, so `10`, `010` and `1e1` are three versions
     */
    public function admits(string $version): bool
    {
        return $this->versions === null || in_array($version, $this->versions, true);
    }
}
