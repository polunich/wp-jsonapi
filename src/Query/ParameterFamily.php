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

namespace Polunich\WpJsonApi\Query;

/**
 * the query parameter families the spec reserves and the library processes;
 * the library defines none of its own.
 * the value is the base name, spelled here only.
 * the case order is the canonical order in which the families are listed
 */
enum ParameterFamily: string
{
    case Filter = 'filter';

    case Sort = 'sort';

    case Page = 'page';

    case Include = 'include';

    case Fields = 'fields';

    /**
     * null where the query carries no such family;
     * text where the client wrote the base name alone, arrays where it wrote brackets
     *
     * @param array<array-key, mixed> $query
     */
    public function valueIn(array $query): mixed
    {
        return $query[$this->value] ?? null;
    }

    /**
     * whatever the value: `include=` carries `include` as much as `include=owner` does
     *
     * @param array<array-key, mixed> $query
     */
    public function isIn(array $query): bool
    {
        return array_key_exists($this->value, $query);
    }

    /**
     * `fields[pets]`, `page[size]`
     */
    public function parameter(string $member): string
    {
        return $this->value . '[' . $member . ']';
    }
}
