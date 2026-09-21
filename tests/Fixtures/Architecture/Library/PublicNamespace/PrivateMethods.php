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
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\ListedValue;

/**
 * a trait of the public namespace whose private methods name types that are not public API;
 * a class may widen them with an alias. its public `shown()` leaks (`publicApi.signature`).
 * `value()` shares its name with the public method of `LeakingMembers`,
 * so a class that uses both traits may take it from either
 */
trait PrivateMethods
{
    public function shown(): ?InternalValue
    {
        return $this->hidden() ?? $this->hiddenToo();
    }

    private function hidden(): ?InternalValue
    {
        return null;
    }

    private function hiddenToo(): ?InternalValue
    {
        return null;
    }

    private function listed(): ?ListedValue
    {
        return null;
    }

    private function qualified(): ?InternalValue
    {
        return null;
    }

    private function camelCase(): ?InternalValue
    {
        return null;
    }

    private function value(): ?InternalValue
    {
        return null;
    }
}
