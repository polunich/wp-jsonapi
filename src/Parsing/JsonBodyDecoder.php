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

namespace Polunich\WpJsonApi\Parsing;

use JsonException;
use Polunich\WpJsonApi\Error\BadRequestException;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;

/**
 * decodes a request body into the wire form codecs read: a JSON object becomes a `stdClass`,
 * an array a list, so `{}` stays apart from the empty to-many linkage `[]`.
 *
 * the refusal carries no `source`: a body that is no JSON holds no value to point at
 *
 * @internal
 */
final readonly class JsonBodyDecoder
{
    /**
     * @throws BadRequestException when the body is no JSON text, an empty body included
     */
    public function decode(string $body): mixed
    {
        try {
            return json_decode($body, false, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new BadRequestException(
                [new ErrorDescription(ErrorCode::InvalidJson)],
                $exception,
            );
        }
    }
}
