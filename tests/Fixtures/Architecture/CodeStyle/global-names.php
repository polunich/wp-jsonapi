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

// a file of the global namespace, where the bare name already names a global class:
// a class written with a leading backslash, in code and in a PHPDoc type, is reported on its line,
// and the bare name is not.
// `phpstan.neon.dist` ignores what the rule reports here

/** @var \ArrayObject<int, string> $names */
$names = new \ArrayObject(['a']);

return new ArrayObject($names->getArrayCopy());
