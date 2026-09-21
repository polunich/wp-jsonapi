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

namespace Polunich\WpJsonApi\WordPress;

use Closure;

/**
 * the output buffering of PHP between a content the library writes and the client.
 *
 * `flush()` passes a written piece on to the server,
 * whose own buffering it may not be able to override (php.net, flush)
 *
 * @internal
 */
final readonly class OutputBuffers
{
    private const DEFAULT_HANDLER = 'default output handler';

    /** @var Closure(): list<array<array-key, mixed>> */
    private Closure $status;

    /** @var Closure(): bool */
    private Closure $endFlush;

    /** @var Closure(): void */
    private Closure $flush;

    /** @var Closure(): bool */
    private Closure $compressing;

    /**
     * each null reads PHP itself
     *
     * @param ?Closure(): list<array<array-key, mixed>> $status      `ob_get_status(true)`
     * @param ?Closure(): bool                          $endFlush    `ob_end_flush()`
     * @param ?Closure(): void                          $flush       `flush()`
     * @param ?Closure(): bool                          $compressing `zlib.output_compression`
     */
    public function __construct(
        ?Closure $status = null,
        ?Closure $endFlush = null,
        ?Closure $flush = null,
        ?Closure $compressing = null,
    ) {
        $this->status = $status ?? static function (): array {
            $buffers = [];

            foreach (ob_get_status(true) as $buffer) {
                if (is_array($buffer)) {
                    $buffers[] = $buffer;
                }
            }

            return $buffers;
        };
        $this->endFlush = $endFlush ?? static function (): bool {
            return ob_end_flush();
        };
        $this->flush = $flush ?? static function (): void {
            flush();
        };
        $this->compressing = $compressing ?? static function (): bool {
            return filter_var(ini_get('zlib.output_compression'), FILTER_VALIDATE_BOOLEAN)
                || (int) ini_get('zlib.output_compression') > 0;
        };
    }

    /**
     * a `Content-Length` holds only where nothing between the library and the client
     * changes or adds bytes: `zlib.output_compression` is off,
     * and every buffer is the default handler, removable and empty, so ending it adds nothing
     */
    public function keepLength(): bool
    {
        if (($this->compressing)()) {
            return false;
        }

        foreach (($this->status)() as $buffer) {
            if (
                ($buffer['name'] ?? null) !== self::DEFAULT_HANDLER
                || !self::removable($buffer)
                || ($buffer['buffer_used'] ?? 1) !== 0
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * run before a content is written:
     * an active buffer would hold the whole content in memory (php.net, readfile).
     * `ob_end_flush()` passes what a buffer holds on through its handler,
     * and cannot end one without `PHP_OUTPUT_HANDLER_REMOVABLE`:
     * that buffer and the ones below it stay, and the memory then depends on them
     */
    public function endRemovable(): void
    {
        $buffers = ($this->status)();

        for ($level = count($buffers) - 1; $level >= 0; $level--) {
            if (!self::removable($buffers[$level]) || !($this->endFlush)()) {
                return;
            }
        }
    }

    public function flush(): void
    {
        ($this->flush)();
    }

    /**
     * @param array<array-key, mixed> $buffer
     */
    private static function removable(array $buffer): bool
    {
        $flags = $buffer['flags'] ?? 0;

        return is_int($flags) && ($flags & PHP_OUTPUT_HANDLER_REMOVABLE) !== 0;
    }
}
