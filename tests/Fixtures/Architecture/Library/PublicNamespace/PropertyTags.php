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

use OutOfBoundsException;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\InternalValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\ListedValue;

/**
 * a public class whose property tags of all three kinds name a class that is not public API
 * (`publicApi.signature`); the tag of `$listed` names a listed class and leaks nothing
 *
 * @property InternalValue $value
 * @property-read InternalValue $readable
 * @property-write InternalValue $writable
 * @property ListedValue $listed
 */
final class PropertyTags
{
    /**
     * @var array<string, object>
     */
    private array $values = [];

    public function __get(string $name): object
    {
        return $this->values[$name] ?? throw new OutOfBoundsException('no such property');
    }

    public function __set(string $name, object $value): void
    {
        $this->values[$name] = $value;
    }
}
