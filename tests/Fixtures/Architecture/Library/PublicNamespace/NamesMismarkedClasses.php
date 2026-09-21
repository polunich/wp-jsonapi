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

namespace Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\PublicNamespace;

use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\ListedButMarkedInternal;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\UnlistedAndUnmarked;

/**
 * a public class that names two classes whose tags disagree with the list.
 * whether a named class is public API comes from the list,
 * so the listed class with the tag leaks nothing,
 * and the unlisted class without it leaks (`publicApi.signature`)
 */
final class NamesMismarkedClasses
{
    public function convert(ListedButMarkedInternal $listed): UnlistedAndUnmarked
    {
        return new UnlistedAndUnmarked();
    }
}
