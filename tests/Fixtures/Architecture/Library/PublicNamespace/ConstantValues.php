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
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\InternalState;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\InternalValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Library\OtherNamespace\ListedValue;
use Polunich\WpJsonApi\Tests\Fixtures\Architecture\Outside\StateOutsideTheRoot;
use UnitEnum;

/**
 * a public class whose constants cover how a constant is read (`publicApi.signature`):
 * `LIMIT` reads a constant of an internal class, though its value type, `int`, names none;
 * `NONE` names an internal type only in its native type, `VALUES` only in its PHPDoc type;
 * `LISTED` names a listed class and leaks nothing.
 *
 * `TYPED_STATE` and `DOCUMENTED_STATE` hold an internal enum case only through an own constant
 * and declare a type, natively and in PHPDoc, that names no class of the library: no leak.
 * `UNTYPED_STATE` declares no type, so its value type is its only type, and it leaks the enum.
 * `NARROWED_STATE` declares an internal interface natively
 * and narrows it in PHPDoc to an enum outside the root.
 * both declared types are read, so the native type leaks the interface,
 * where the type PHPStan combines from the two would be the PHPDoc type alone
 */
final class ConstantValues
{
    public const int LIMIT = InternalValue::LIMIT;

    public const ?InternalState NONE = null;

    /**
     * @var list<InternalValue>
     */
    public const VALUES = [];

    public const string LISTED = ListedValue::class;

    public const object TYPED_STATE = self::HIDDEN_STATE;

    /**
     * @var UnitEnum
     */
    public const DOCUMENTED_STATE = self::HIDDEN_STATE;

    public const UNTYPED_STATE = self::HIDDEN_STATE;

    /**
     * @var StateOutsideTheRoot
     */
    public const InternalContract NARROWED_STATE = StateOutsideTheRoot::On;

    private const HIDDEN_STATE = InternalState::Off;
}
