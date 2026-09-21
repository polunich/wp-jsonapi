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

use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\InternalState;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\InternalValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\ListedValue;

/**
 * a trait of the public namespace no class uses, whose constant, property and method name types
 * that are not public API: its own declaration is checked all the same (`publicApi.signature`).
 * `listed()` names a listed class and the private `kept()` is no public API, so neither leaks.
 * `phpstan.neon.dist` ignores the report that no class uses the trait
 */
trait UnusedLeakingMembers
{
    public const DEFAULT = InternalState::On;

    public ?InternalValue $value = null;

    public function value(): ?InternalValue
    {
        return $this->kept();
    }

    public function listed(): ?ListedValue
    {
        return null;
    }

    private function kept(): ?InternalValue
    {
        return $this->value;
    }
}
