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

namespace Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Core;

use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Modules\Forbidden\ForbiddenValue;

/**
 * a trait of `Core` no class uses that names a `Forbidden` class,
 * but the can-only rule excludes it from its subjects, so the rule reports nothing for it.
 *
 * `phpstan.neon.dist` ignores the report that no class uses the trait
 */
trait Excused
{
    public function limit(): int
    {
        return ForbiddenValue::LIMIT;
    }
}
