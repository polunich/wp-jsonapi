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
use Polunich\WpJsonApi\Document\MemberName;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Query\Fieldset\FieldHint;
use Polunich\WpJsonApi\Query\Filter\FilterNode;
use Polunich\WpJsonApi\Query\ParentScope;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Query\Window;
use Polunich\WpJsonApi\Text\Printable;

/**
 * one reading of a collection, as the project's storage receives it.
 * the library never queries storage itself: the project walks `$filter` with a `Visitor`
 * of its own and builds the query its storage speaks.
 *
 * the related resources of a to-many relationship, and its linkage,
 * are read with it too, narrowed by `$parent`.
 * every request option is resolved before it is built:
 * pagination is `$window`, a cursor is conditions in `$filter`,
 * and `$fields` already names the fields the library reads itself.
 * `$queryParameters` holds the query parameters of the route
 * whose primary data or target this reads, decoded;
 * it is empty where the library reads included resources or the records a cursor is read off,
 * which the route's parameters do not address
 */
final readonly class CollectionQuery
{
    /**
     * @param Sort                $sort      always holds `id`, as `Sort::withId()` explains
     * @param LanguagePreferences $languages resolving them is up to the project
     * @param ?ParentScope        $parent    null when a whole collection is read
     * @param Values<mixed>       $queryParameters
     *
     * @throws InvalidArgumentException when the type is no member name,
     *                                  or the sort does not hold `id`
     */
    public function __construct(
        public string $type,
        public ?FilterNode $filter,
        public Sort $sort,
        public Window $window,
        public bool $totalRequested,
        public FieldHint $fields,
        public LanguagePreferences $languages,
        public ?ParentScope $parent,
        public Values $queryParameters,
    ) {
        if (!MemberName::isValid($type)) {
            throw new InvalidArgumentException(sprintf(
                'type "%s" is no member name, so no resource type carries it',
                Printable::of($type),
            ));
        }

        if (!$sort->hasField(FieldPath::id())) {
            throw new InvalidArgumentException(sprintf(
                'sort "%s" does not hold id, and the library appends id to every sort so that the order'
                . ' is total',
                $sort->toString(),
            ));
        }
    }
}
