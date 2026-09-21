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

namespace Polunich\WpJsonApi\Metadata\Endpoint;

/**
 * the shape of a request body an endpoint reads in the JSON:API media type
 *
 * @internal
 */
enum BodyShapeKind
{
    /** a resource object of a type, read as a create of that type */
    case Resource;

    /** the resource object of a bound record, read as an update of it */
    case Record;

    /** one resource identifier of a type, or null */
    case ToOne;

    /** a list of resource identifiers of a type */
    case ToMany;

    /** a document of top-level meta alone */
    case Meta;

    /**
     * the words that name the kind in a message
     */
    public function term(): string
    {
        return match ($this) {
            self::Resource => 'resource',
            self::Record => 'record',
            self::ToOne => 'to-one',
            self::ToMany => 'to-many',
            self::Meta => 'meta',
        };
    }

    /**
     * a body of the kind, with its indefinite article, as a message names it
     */
    public function phrase(): string
    {
        return 'a ' . $this->term() . ' body';
    }
}
