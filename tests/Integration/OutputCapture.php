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

namespace Polunich\WpJsonApi\Tests\Integration;

use LogicException;

/**
 * what reaches the client of a content the library writes itself.
 *
 * before its first piece the library ends every removable output buffer, PHPUnit's included,
 * so no buffer a test opens sees the content.
 * this buffer lies below them all, where a web server would take the output:
 * it is installed once, without `PHP_OUTPUT_HANDLER_REMOVABLE`.
 * while a request is served it keeps what reaches it, at any other time it passes everything on.
 * the chunk size of 1 hands it each write at once
 *
 * @internal
 */
final class OutputCapture
{
    private static ?string $captured = null;

    private static bool $installed = false;

    private function __construct() {}

    /**
     * @throws LogicException when it is installed twice
     */
    public static function install(): void
    {
        if (self::$installed) {
            throw new LogicException('the output capture is installed once, below every other buffer');
        }

        ob_start(static function (string $chunk): string {
            if (self::$captured === null) {
                return $chunk;
            }

            self::$captured .= $chunk;

            return '';
        }, 1, PHP_OUTPUT_HANDLER_CLEANABLE | PHP_OUTPUT_HANDLER_FLUSHABLE);
        self::$installed = true;
    }

    public static function start(): void
    {
        self::$captured = '';
    }

    public static function stop(): string
    {
        $captured = self::$captured ?? '';
        self::$captured = null;

        return $captured;
    }
}
