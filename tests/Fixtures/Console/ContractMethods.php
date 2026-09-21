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

namespace Polunich\WpJsonApi\Tests\Fixtures\Console;

use stdClass;
use UnexpectedValueException;

/**
 * the methods `extra.wp-jsonapi.api` names in the tests of the contract command:
 * two that build an API with a contract of their own, and one for each way a method can fail it
 *
 * @internal
 */
final class ContractMethods
{
    public const FIRST = "{\n    \"a\": 1\n}\n";

    public const SECOND = "{\n    \"a\": 1,\n    \"b\": 2\n}\n";

    public static function first(): ContractBuilder
    {
        return new ContractBuilder(new ContractApi(self::FIRST));
    }

    public static function second(): ContractBuilder
    {
        return new ContractBuilder(new ContractApi(self::SECOND));
    }

    public static function failing(): ContractBuilder
    {
        throw new UnexpectedValueException('no declaration');
    }

    public static function returnsNoBuilder(): string
    {
        return 'openapi.json';
    }

    public static function buildsNoApi(): ContractBuilder
    {
        return new ContractBuilder(new stdClass());
    }

    public static function writesNoString(): ContractBuilder
    {
        return new ContractBuilder(new ContractApi([]));
    }

    public static function takesAnArgument(string $name): ContractBuilder
    {
        return new ContractBuilder(new ContractApi($name));
    }

    public function onAnInstance(): ContractBuilder
    {
        return self::first();
    }

    protected static function hidden(): ContractBuilder
    {
        return self::first();
    }
}
