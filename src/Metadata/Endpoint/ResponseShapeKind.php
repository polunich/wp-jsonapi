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
 * the shape of a response an endpoint declares, which fixes its status
 *
 * @internal
 */
enum ResponseShapeKind
{
    /** any resource of a type */
    case Resource;

    /** the record bound to a parameter, as the handler leaves it */
    case Record;

    /** the resource the request created */
    case Created;

    /** a collection of resources of a type, with no pages */
    case Collection;

    /** a page of the collection of a type, as `filter`, `sort` and `page` select it */
    case Page;

    /** the related resource or resources of a relationship of a bound record */
    case Related;

    /** the linkage of a relationship of a bound record */
    case Linkage;

    /** a document of top-level meta alone */
    case Meta;

    /** the monitor of accepted work */
    case Accepted;

    /** no document */
    case NoContent;

    public function status(): int
    {
        return match ($this) {
            self::Resource, self::Record, self::Collection, self::Page, self::Related, self::Linkage, self::Meta => 200,
            self::Created => 201,
            self::Accepted => 202,
            self::NoContent => 204,
        };
    }

    /**
     * whether the response carries resources or linkage, which `include` and `fields` shape
     */
    public function carriesResources(): bool
    {
        return match ($this) {
            self::Resource,
            self::Record,
            self::Created,
            self::Collection,
            self::Page,
            self::Related,
            self::Linkage => true,
            self::Meta, self::Accepted, self::NoContent => false,
        };
    }

    /**
     * the words that name the kind in a message and a description
     */
    public function term(): string
    {
        return match ($this) {
            self::Resource => 'resource',
            self::Record => 'record',
            self::Created => 'created',
            self::Collection => 'collection',
            self::Page => 'page',
            self::Related => 'related',
            self::Linkage => 'linkage',
            self::Meta => 'meta',
            self::Accepted => 'accepted',
            self::NoContent => 'no-content',
        };
    }

    /**
     * a response of the kind, with its indefinite article, as a message names it
     */
    public function phrase(): string
    {
        return ($this === self::Accepted ? 'an ' : 'a ') . $this->term() . ' response';
    }
}
