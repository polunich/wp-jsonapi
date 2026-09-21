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

namespace Polunich\WpJsonApi\Tests\Fixtures\WordPress;

use Closure;

/**
 * @internal
 */
final class ErrorLog
{
    private function __construct() {}

    /**
     * what `error_log()` wrote while the closure ran.
     * PHPUnit captures `error_log` around every test and prints what it caught,
     * so the destination is swapped inside the test and handed back
     */
    public static function capturedWhile(Closure $write): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'wp-jsonapi-log');
        $previous = ini_set('error_log', $file);

        try {
            $write();
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        $logged = (string) file_get_contents($file);
        unlink($file);

        return $logged;
    }
}
