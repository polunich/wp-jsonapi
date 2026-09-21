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

namespace Polunich\WpJsonApi\Tests\Fixtures\Api;

use LogicException;

/**
 * one shape for every type of the fixture API,
 * so a filter, a sort or a cursor reads a field by its name, with no mapping per type
 *
 * @internal
 */
final readonly class Record
{
    /**
     * @param string                      $version    the version an entity tag is built from
     * @param array<string, mixed>        $attributes decoded values by attribute name
     * @param array<string, ?string>      $toOne      null for an empty relationship
     * @param array<string, list<string>> $toMany     ids in the order they were added
     */
    public function __construct(
        public string $type,
        public string $id,
        public string $version,
        public array $attributes,
        public array $toOne = [],
        public array $toMany = [],
    ) {}

    /**
     * @throws LogicException when the record has no such attribute
     */
    public function attribute(string $name): mixed
    {
        if (!array_key_exists($name, $this->attributes)) {
            throw new LogicException(sprintf('a record of type "%s" has no attribute "%s"', $this->type, $name));
        }

        return $this->attributes[$name];
    }

    /**
     * @throws LogicException when the record has no such relationship
     */
    public function toOne(string $name): ?string
    {
        if (!array_key_exists($name, $this->toOne)) {
            throw new LogicException(sprintf(
                'a record of type "%s" has no to-one relationship "%s"',
                $this->type,
                $name,
            ));
        }

        return $this->toOne[$name];
    }

    /**
     * @return list<string>
     *
     * @throws LogicException when the record has no such relationship
     */
    public function toMany(string $name): array
    {
        if (!array_key_exists($name, $this->toMany)) {
            throw new LogicException(sprintf(
                'a record of type "%s" has no to-many relationship "%s"',
                $this->type,
                $name,
            ));
        }

        return $this->toMany[$name];
    }

    /**
     * @param array<string, mixed>        $attributes
     * @param array<string, ?string>      $toOne
     * @param array<string, list<string>> $toMany
     */
    public function revised(array $attributes = [], array $toOne = [], array $toMany = []): self
    {
        return new self(
            $this->type,
            $this->id,
            (string) ((int) $this->version + 1),
            array_replace($this->attributes, $attributes),
            array_replace($this->toOne, $toOne),
            array_replace($this->toMany, $toMany),
        );
    }
}
