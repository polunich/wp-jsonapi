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
use Polunich\WpJsonApi\Http\Content;
use Polunich\WpJsonApi\Http\EntityTag;
use Polunich\WpJsonApi\Http\MediaType;

/**
 * a 200 in a media type other than JSON:API,
 * with the content already opened and the media type as the endpoint declares it
 *
 * @internal
 */
final readonly class ContentResult implements Result
{
    public function __construct(
        public Content $content,
        public MediaType $mediaType,
        public ?string $attachmentName,
    ) {}

    #[Override]
    public function status(): int
    {
        return 200;
    }

    #[Override]
    public function entityTag(): ?EntityTag
    {
        return null;
    }
}
