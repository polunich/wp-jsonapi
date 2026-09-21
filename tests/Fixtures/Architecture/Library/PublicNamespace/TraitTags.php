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

use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\InternalContract;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\InternalValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\ListedValue;

/**
 * a trait of the public namespace no class uses, whose PHPDoc names types that are not public API
 * (`publicApi.signature`): in a template bound, in `@phpstan-require-implements`,
 * which PHPStan accepts on a trait only, and in a type alias.
 * the template `L`, the second require tag and the alias `Listed` name public types, no leak
 *
 * @template T of InternalValue
 * @template L of ListedValue
 *
 * @phpstan-require-implements InternalContract
 * @phpstan-require-implements InheritsInternalContract
 *
 * @phpstan-type Held InternalValue
 * @phpstan-type Listed ListedValue
 */
trait TraitTags {}
