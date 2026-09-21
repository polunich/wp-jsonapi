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
 * a public class whose `@use` tag writes the name of its trait in another letter case,
 * with a type argument that is not public API.
 * PHPStan applies no type argument of the tag to the trait and reports the tag itself,
 * so the check reads the trait by its name alone and reports nothing.
 * `phpstan.neon.dist` ignores the errors PHPStan reports here
 */
final class UseTagInAnotherCase
{
    /**
     * @use holdsValue<InternalValue>
     */
    use HoldsValue;
}
