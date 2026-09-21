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

namespace Polunich\WpJsonApi\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Attributes\TestRule;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;
use Polunich\WpJsonApi\Error\JsonApiException;

/**
 * the mechanical naming rules; a rule that depends on meaning is left to review.
 * a pattern is matched against the fully qualified class name
 *
 * @internal
 */
final class NamingRules
{
    private const ROOT = 'Polunich\WpJsonApi';

    #[TestRule]
    public function exceptionsEndWithException(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::AllOf(Selector::inNamespace(self::ROOT), Selector::isThrowable()))
            ->shouldBeNamed('/Exception$/', true);
    }

    #[TestRule]
    public function interfacesHaveNoSuffix(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::AllOf(Selector::inNamespace(self::ROOT), Selector::isInterface()))
            ->shouldBeNamed('/^(?!.*Interface$)/', true);
    }

    #[TestRule]
    public function traitsHaveNoSuffix(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::AllOf(Selector::inNamespace(self::ROOT), Selector::isTrait()))
            ->shouldBeNamed('/^(?!.*Trait$)/', true);
    }

    #[TestRule]
    public function abstractClassesHaveNoPrefix(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::AllOf(Selector::inNamespace(self::ROOT), Selector::isAbstract()))
            ->shouldBeNamed('/^(?!.*\\\\Abstract[^\\\\]*$)/', true);
    }

    /**
     * PHP refuses every other reserved word as a class name
     * but accepts these three, in any letter case
     */
    #[TestRule]
    public function namesAvoidSoftReservedWords(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::ROOT))
            ->shouldBeNamed('/\\\\(?!(?:enum|numeric|resource)$)[^\\\\]+$/i', true);
    }

    /**
     * a class is final unless it is meant to be extended; extension goes through interfaces.
     * `JsonApiException` stays open: every exception class of a status extends it,
     * and it is thrown itself for a status without a class of its own.
     * the exclusion names that one class, so any other class that is not final is still reported
     */
    #[TestRule]
    public function classesAreFinal(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace(self::ROOT))
            ->excluding(
                Selector::isAbstract(),
                Selector::isInterface(),
                Selector::isEnum(),
                Selector::isTrait(),
                Selector::classname(JsonApiException::class),
            )
            ->shouldBeFinal();
    }
}
