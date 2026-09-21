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

namespace Polunich\WpJsonApi\Tests\Fixtures\Parsing;

use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\ErrorList;

/**
 * the problems an error list collected, each as its code, its query parameter and its parameters
 *
 * @internal
 */
final readonly class CollectedProblems
{
    /**
     * @return list<array{string, ?string, array<string, scalar>}>
     */
    public static function of(ErrorList $errors): array
    {
        return array_map(
            static function (ErrorDescription $description): array {
                return [
                    $description->code instanceof ErrorCode ? $description->code->value : $description->code,
                    $description->parameter,
                    $description->parameters,
                ];
            },
            $errors->exception()?->descriptions() ?? [],
        );
    }
}
