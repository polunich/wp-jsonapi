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

namespace Polunich\WpJsonApi\Tests\Fixtures\Store;

/**
 * a record of `PetStore`; a pet with `$visible` false is one the client may not see
 *
 * @internal
 */
final readonly class StoredPet
{
    /**
     * @param list<string> $littermates ids, in the order they were added
     */
    public function __construct(
        public string $id,
        public string $version,
        public string $name,
        public array $littermates = [],
        public bool $visible = true,
    ) {}

    /**
     * @param list<string> $littermates
     */
    public function revised(string $name, array $littermates): self
    {
        return new self($this->id, (string) ((int) $this->version + 1), $name, $littermates, $this->visible);
    }
}
