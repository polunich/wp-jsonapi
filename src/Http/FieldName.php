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

namespace Polunich\WpJsonApi\Http;

/**
 * the header field names the library reads and writes, spelled as the RFCs that define them.
 * a field name is case-insensitive (RFC 9110 5.1), so the spelling only fixes what is written
 *
 * @internal
 */
final readonly class FieldName
{
    public const ACCEPT = 'Accept';

    public const ACCEPT_LANGUAGE = 'Accept-Language';

    public const ALLOW = 'Allow';

    public const CONTENT_DISPOSITION = 'Content-Disposition';

    public const CONTENT_LANGUAGE = 'Content-Language';

    public const CONTENT_LENGTH = 'Content-Length';

    public const CONTENT_LOCATION = 'Content-Location';

    public const CONTENT_TYPE = 'Content-Type';

    public const ETAG = 'ETag';

    public const IF_MATCH = 'If-Match';

    public const IF_NONE_MATCH = 'If-None-Match';

    public const LOCATION = 'Location';

    public const TRANSFER_ENCODING = 'Transfer-Encoding';

    public const VARY = 'Vary';

    public const WWW_AUTHENTICATE = 'WWW-Authenticate';
}
