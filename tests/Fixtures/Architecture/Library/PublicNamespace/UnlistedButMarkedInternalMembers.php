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

use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\InternalValue;

/**
 * a trait of the public namespace, used by no class,
 * that carries the tag though the fixture list does not name it internal:
 * a trait is read from the list like a class (`publicApi.marking`).
 * the list makes it public API all the same, so its method leaks (`publicApi.signature`).
 * `phpstan.neon.dist` ignores the report that no class uses the trait
 *
 * @internal
 */
trait UnlistedButMarkedInternalMembers
{
    public function value(): ?InternalValue
    {
        return null;
    }
}
