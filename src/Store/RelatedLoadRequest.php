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
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Query\Fieldset\FieldHint;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Text\Printable;

/**
 * the related records a `RelatedLoader` is asked for:
 * one relationship of many parents of one type, batched into one call.
 * `$queryParameters` holds the query parameters of the route
 * whose primary data or target this reads, decoded;
 * it is empty where the library reads included resources or the records a cursor is read off,
 * which the route's parameters do not address
 */
final readonly class RelatedLoadRequest
{
    private const TO_ONE_LIMIT = 1;

    /**
     * each once, in the order the loader returns them
     *
     * @var non-empty-list<string>
     */
    public array $parentIds;

    /**
     * @param string        $type            the type of the parents, not the related type
     * @param list<string>  $parentIds
     * @param int           $limit           the most records returned per parent:
     *                                       1 for a to-one relationship;
     *                                       the linkage size plus one for a to-many one,
     *                                       the extra record telling that a next page exists
     * @param FieldHint     $fields          the fields of the related type
     * @param Values<mixed> $queryParameters
     *
     * @throws InvalidArgumentException when the type is empty, there is no parent,
     *                                  a parent id is empty or named twice,
     *                                  the relationship is empty, the sort does not hold id,
     *                                  or the limit is below 1
     */
    public function __construct(
        public string $type,
        array $parentIds,
        public string $relationship,
        public Sort $sort,
        public int $limit,
        public FieldHint $fields,
        public LanguagePreferences $languages,
        public Values $queryParameters,
    ) {
        if ($type === '') {
            throw new InvalidArgumentException('related records are loaded for parents of a resource type, and a'
                . ' type is never empty');
        }

        if ($parentIds === []) {
            throw new InvalidArgumentException(sprintf(
                'related records are loaded for one or more parents of type "%s", and this request names none',
                Printable::of($type),
            ));
        }

        // PHP casts only a canonical decimal string to an int key,
        // so two different ids never share a key of this set
        $seen = [];

        foreach ($parentIds as $parentId) {
            if ($parentId === '') {
                throw new InvalidArgumentException(sprintf(
                    'a request to load related records names a parent of type "%s" with an empty id, which'
                    . ' identifies no resource',
                    Printable::of($type),
                ));
            }

            if (array_key_exists($parentId, $seen)) {
                throw new InvalidArgumentException(sprintf(
                    'a request to load related records names parent "%s" of type "%s" twice, and the records of'
                    . ' each parent are returned once',
                    Printable::of($parentId),
                    Printable::of($type),
                ));
            }

            $seen[$parentId] = true;
        }

        if ($relationship === '') {
            throw new InvalidArgumentException(sprintf(
                'related records of parents of type "%s" are loaded through a relationship, whose name is never'
                . ' empty',
                Printable::of($type),
            ));
        }

        if (!$sort->hasField(FieldPath::id())) {
            throw new InvalidArgumentException(sprintf(
                'sort "%s" of relationship "%s" does not hold id, and the library appends id to every sort so'
                . ' that the order is total',
                Printable::of($sort->toString()),
                Printable::of($relationship),
            ));
        }

        if ($limit < 1) {
            throw new InvalidArgumentException(sprintf(
                'related records of relationship "%s" are loaded %d at most for each parent, and a load reads at'
                . ' least one',
                Printable::of($relationship),
                $limit,
            ));
        }

        $this->parentIds = $parentIds;
    }

    /**
     * the read of a to-one relationship: at most one record per parent, sorted by id alone
     *
     * @param list<string>  $parentIds
     * @param Values<mixed> $queryParameters
     *
     * @throws InvalidArgumentException when the type is empty, there is no parent,
     *                                  a parent id is empty or named twice,
     *                                  or the relationship is empty
     */
    public static function toOne(
        string $type,
        array $parentIds,
        string $relationship,
        FieldHint $fields,
        LanguagePreferences $languages,
        Values $queryParameters,
    ): self {
        return new self(
            $type,
            $parentIds,
            $relationship,
            Sort::byId(),
            self::TO_ONE_LIMIT,
            $fields,
            $languages,
            $queryParameters,
        );
    }

    public function readsOne(): bool
    {
        return $this->limit === self::TO_ONE_LIMIT;
    }
}
