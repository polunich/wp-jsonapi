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
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Text\Printable;
use RuntimeException;
use Throwable;

/**
 * a write references related resources that are missing or that the client may not see,
 * and gets a 404 that points at each of them in the request.
 *
 * name only relationships and identifiers the operation carries:
 * anything else leaves an error with nothing to point at and fails the request with 500
 */
final class RelatedResourceNotFoundException extends RuntimeException
{
    /**
     * relationship name => the identifiers of its linkage that are missing or hidden;
     * a name of digits alone arrives as an int key
     *
     * @var non-empty-array<array-key, non-empty-list<Identifier>>
     */
    public readonly array $missing;

    /**
     * @param array<array-key, list<Identifier>> $missing relationship => its missing identifiers
     *
     * @throws InvalidArgumentException when the map is empty, a relationship name is empty,
     *                                  or a relationship names no identifier
     */
    public function __construct(array $missing, ?Throwable $previous = null)
    {
        $checked = [];

        foreach ($missing as $relationship => $identifiers) {
            if ($relationship === '') {
                throw new InvalidArgumentException('related resources are missing from a relationship whose name is'
                    . ' never empty, and the name is empty');
            }

            if ($identifiers === []) {
                throw new InvalidArgumentException(sprintf(
                    'relationship "%s" is named with no missing identifier, and it names one or more',
                    Printable::of((string) $relationship),
                ));
            }

            $checked[$relationship] = $identifiers;
        }

        if ($checked === []) {
            throw new InvalidArgumentException('related resources are missing from one or more relationships, and'
                . ' none is named');
        }

        $this->missing = $checked;

        parent::__construct(self::describe($checked), 0, $previous);
    }

    /**
     * @param non-empty-array<array-key, non-empty-list<Identifier>> $missing
     */
    private static function describe(array $missing): string
    {
        $described = [];

        foreach ($missing as $relationship => $identifiers) {
            $described[] = sprintf(
                'relationship "%s": %s',
                Printable::of((string) $relationship),
                implode(', ', array_map(
                    static function (Identifier $identifier): string {
                        return sprintf(
                            '%s "%s"',
                            Printable::of($identifier->type),
                            Printable::of($identifier->id),
                        );
                    },
                    $identifiers,
                )),
            );
        }

        return 'the write references related resources that do not exist or are not visible: '
            . implode('; ', $described);
    }
}
