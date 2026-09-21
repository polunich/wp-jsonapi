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

use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\InternalHelpers;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\InternalValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\ListedValue;

/**
 * a trait of the public namespace no class uses, whose own trait uses leak (`publicApi.ancestor`):
 * a trait that is not public API, and a public generic one with an internal argument in `@use`.
 * `PublicHelpers`, and `KeepsValue` with a listed class as its type argument, leak nothing.
 * `phpstan.neon.dist` ignores the report that no class uses the trait
 */
trait NestedTraitUses
{
    use InternalHelpers;
    use PublicHelpers;

    /**
     * @use HoldsValue<InternalValue>
     */
    use HoldsValue;

    /**
     * @use KeepsValue<ListedValue>
     */
    use KeepsValue;
}
