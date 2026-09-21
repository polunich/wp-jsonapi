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

/**
 * a public class whose trait use widens private methods of `PrivateMethods`.
 * the trait does not publish them, so they are read on the class (`publicApi.signature`):
 * `hidden()` and `camelCase()` keep their names, `exposed()` gets a new one,
 * and `qualified()` is aliased with the trait name.
 * an alias without a trait name reads the one trait that has the method,
 * and `LeakingMembers`, the other trait, has none of these methods.
 * `listed()` names a listed class and leaks nothing.
 * `alsoShown()` aliases `shown()`, which `PrivateMethods` publishes,
 * and `leakingValue()` aliases the public `value()` of `LeakingMembers`,
 * while the private `value()` of `PrivateMethods` takes its place in the class:
 * both aliases are read on the trait that publishes the method, not here,
 * and so are the constant and the property the class takes from `LeakingMembers`
 */
final class WidensPrivateMethods
{
    use LeakingMembers;
    use PrivateMethods {
        hidden as public;
        hiddenToo as protected exposed;
        listed as public;
        shown as protected alsoShown;
        PrivateMethods::qualified as public;
        camelCase as protected;
        PrivateMethods::value insteadof LeakingMembers;
        LeakingMembers::value as protected leakingValue;
    }
}
