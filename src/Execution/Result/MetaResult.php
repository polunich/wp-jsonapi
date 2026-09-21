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
 * a document with top-level `meta` alone, no primary data
 *
 * @internal
 */
final readonly class MetaResult implements Result
{
    /**
     * @param array<array-key, mixed> $meta the members of the encoded object;
     *                                      a member name of digits sits under an int key
     */
    public function __construct(public array $meta) {}

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
