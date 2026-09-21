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

namespace Polunich\WpJsonApi\Store;

use InvalidArgumentException;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\Fieldset\FieldHint;
use Polunich\WpJsonApi\Text\Printable;

/**
 * the records a `ResourceLoader` is asked for: ids of one type, batched into one call.
 * `$queryParameters` holds the query parameters of the route
 * whose primary data or target this reads, decoded;
 * it is empty where the library reads included resources or the records a cursor is read off,
 * which the route's parameters do not address
 */
final readonly class LoadRequest
{
    /**
     * each once, in the order the loader returns them
     *
     * @var non-empty-list<string>
     */
    public array $ids;

    /**
     * @param list<string>  $ids
     * @param Values<mixed> $queryParameters
     *
     * @throws InvalidArgumentException when the type is empty, there is no id,
     *                                  or an id is empty or named twice
     */
    public function __construct(
        public string $type,
        array $ids,
        public FieldHint $fields,
        public LanguagePreferences $languages,
        public Values $queryParameters,
    ) {
        if ($type === '') {
            throw new InvalidArgumentException('records are loaded for a resource type, and a type is never empty');
        }

        if ($ids === []) {
            throw new InvalidArgumentException(sprintf(
                'records of type "%s" are loaded by one or more ids, and this request names none',
                Printable::of($type),
            ));
        }

        // PHP casts only a canonical decimal string to an int key,
        // so two different ids never share a key of this set
        $seen = [];

        foreach ($ids as $id) {
            if ($id === '') {
                throw new InvalidArgumentException(sprintf(
                    'a request to load records of type "%s" names an empty id, which identifies no resource',
                    Printable::of($type),
                ));
            }

            if (array_key_exists($id, $seen)) {
                throw new InvalidArgumentException(sprintf(
                    'a request to load records of type "%s" names id "%s" twice, and each type and id pair is'
                    . ' loaded once',
                    Printable::of($type),
                    Printable::of($id),
                ));
            }

            $seen[$id] = true;
        }

        $this->ids = $ids;
    }
}
