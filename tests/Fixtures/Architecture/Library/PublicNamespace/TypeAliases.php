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

use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\InternalAliases;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\InternalValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\ListedValue;

/**
 * a public class whose type aliases resolve to a class that is not public API
 * (`publicApi.signature`): `Held`, which it declares, and `ImportedInternal`, which it imports.
 * `Listed` and `ImportedListed` resolve to a listed class and leak nothing,
 * and `InternalAliases`, the class the aliases come from, is not read by itself
 *
 * @phpstan-type Held InternalValue|null
 * @phpstan-type Listed list<ListedValue>
 *
 * @phpstan-import-type Internal from InternalAliases as ImportedInternal
 * @phpstan-import-type Listed from InternalAliases as ImportedListed
 */
final class TypeAliases {}
