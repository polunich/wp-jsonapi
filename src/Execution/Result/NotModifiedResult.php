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

namespace Polunich\WpJsonApi\Execution\Result;

use Override;
use Polunich\WpJsonApi\Http\EntityTag;

/**
 * a 304 for a `GET` or `HEAD` whose `If-None-Match` condition is false,
 * sent without running the operation or the handler.
 *
 * the tag is the one the condition was evaluated against,
 * since a 304 repeats the `ETag` a 200 would carry (RFC 9110 15.4.5).
 * it is `null` for a representation without a tag, which `If-None-Match: *` still matches
 *
 * @internal
 */
final readonly class NotModifiedResult implements Result
{
    public function __construct(private ?EntityTag $entityTag) {}

    #[Override]
    public function status(): int
    {
        return 304;
    }

    #[Override]
    public function entityTag(): ?EntityTag
    {
        return $this->entityTag;
    }
}
