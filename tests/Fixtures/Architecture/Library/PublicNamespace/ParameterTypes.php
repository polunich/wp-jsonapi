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
 * a public class whose parameters name a class that is not public API (`publicApi.signature`):
 * natively in a public and in a protected method, and in a PHPDoc tag of a public method.
 * the private method names it too, and is no public API
 */
abstract class ParameterTypes
{
    public function accept(InternalValue $value): void
    {
        $this->keep([$value]);
    }

    protected function acceptLater(InternalValue $value): void
    {
        $this->keep([$value]);
    }

    /**
     * @param list<InternalValue> $values
     */
    public function acceptAll(array $values): void
    {
        $this->keep($values);
    }

    /**
     * @param list<InternalValue> $values
     */
    private function keep(array $values): void {}
}
