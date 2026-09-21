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

namespace Polunich\WpJsonApi\Document;

/**
 * the member names of a document the library reads and writes,
 * spelled as JSON:API 1.1 and its Atomic Operations extension spell them.
 * a member name is case-sensitive, so the spelling is the name
 *
 * @internal
 */
final readonly class Member
{
    public const JSONAPI = 'jsonapi';

    public const DATA = 'data';

    public const ERRORS = 'errors';

    public const META = 'meta';

    public const LINKS = 'links';

    public const INCLUDED = 'included';

    public const TYPE = 'type';

    public const ID = 'id';

    public const LID = 'lid';

    public const ATTRIBUTES = 'attributes';

    public const RELATIONSHIPS = 'relationships';

    public const SELF = 'self';

    public const RELATED = 'related';

    public const FIRST = 'first';

    public const PREV = 'prev';

    public const NEXT = 'next';

    public const LAST = 'last';

    public const HREF = 'href';

    public const REL = 'rel';

    public const DESCRIBEDBY = 'describedby';

    public const TITLE = 'title';

    public const HREFLANG = 'hreflang';

    public const VERSION = 'version';

    public const EXT = 'ext';

    public const PROFILE = 'profile';

    public const STATUS = 'status';

    public const CODE = 'code';

    public const DETAIL = 'detail';

    public const SOURCE = 'source';

    public const POINTER = 'pointer';

    public const PARAMETER = 'parameter';

    public const HEADER = 'header';

    public const ATOMIC_OPERATIONS = 'atomic:operations';

    public const ATOMIC_RESULTS = 'atomic:results';

    public const OP = 'op';

    public const REF = 'ref';

    public const RELATIONSHIP = 'relationship';
}
