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

namespace Polunich\WpJsonApi\Tests\Fixtures;

use LogicException;

/**
 * @internal
 */
final class TemporaryDirectory
{
    private function __construct() {}

    /**
     * the symbolic links of the system temporary directory are resolved,
     * so a path a message names equals the path the test built
     */
    public static function create(): string
    {
        $base = realpath(sys_get_temp_dir());

        if ($base === false) {
            throw new LogicException('the temporary directory of the system does not exist');
        }

        $directory = $base . '/wp-jsonapi-' . bin2hex(random_bytes(8));

        if (!mkdir($directory, 0o700)) {
            throw new LogicException(sprintf('the directory %s cannot be created', $directory));
        }

        return $directory;
    }

    /**
     * a symbolic link inside is removed, never followed;
     * a directory that is itself a link is left alone
     */
    public static function remove(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $directory . '/' . $name;

            if (is_link($path) || !is_dir($path)) {
                @chmod($path, 0o600);
                unlink($path);

                continue;
            }

            @chmod($path, 0o700);
            self::remove($path);
        }

        rmdir($directory);
    }
}
