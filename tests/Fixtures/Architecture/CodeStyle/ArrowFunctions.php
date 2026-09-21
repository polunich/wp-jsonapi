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

namespace Polunich\WpJsonApi\Tests\Fixtures\Architecture\CodeStyle;

use Closure;

/**
 * arrow functions written on purpose, a nested one included,
 * beside closures written in full, which the rule must not report.
 * `ArrowFunctionRuleTest` pins the line of each arrow function
 *
 * @internal
 */
final class ArrowFunctions
{
    /**
     * @return list<Closure>
     */
    public function closures(int $offset): array
    {
        return [
            static fn(int $value): int => $value + $offset,
            fn(): self => $this,
            static fn(int $value): Closure => static fn(int $other): int => $value + $other,
            static function (int $value) use ($offset): int {
                return $value + $offset;
            },
            function (): self {
                return $this;
            },
        ];
    }
}
