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

namespace Polunich\WpJsonApi\Tests\Fixtures\Query;

use Polunich\WpJsonApi\Query\Inclusion\IncludePath;
use Polunich\WpJsonApi\Query\Inclusion\IncludePaths;

/**
 * the paths of an `include` parameter as a request writes it:
 * null for no parameter, the empty set for the empty value
 *
 * @internal
 */
final readonly class WrittenInclude
{
    public static function paths(?string $include): ?IncludePaths
    {
        if ($include === null) {
            return null;
        }

        if ($include === '') {
            return new IncludePaths([]);
        }

        return new IncludePaths(array_map(
            static function (string $path): IncludePath {
                return new IncludePath(explode('.', $path));
            },
            explode(',', $include),
        ));
    }
}
