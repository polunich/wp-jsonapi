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

namespace Polunich\WpJsonApi\Error;

use Override;
use Throwable;

/**
 * every throwable becomes a 500 with the generic code `internal_error`
 * and no internal message, keeping the throwable as the previous exception for the log.
 *
 * with debug output enabled, `meta.exceptions` lists the throwable and every previous one.
 * a trace frame keeps no `args` and no `object`: they hold any value of the program,
 * and a resource among them makes the trace fail to encode as JSON.
 * texts are copied as they are, bytes of no UTF-8 sequence included;
 * they are made well-formed when the error object is built
 *
 * @implements ExceptionMapper<Throwable>
 *
 * @internal
 */
final readonly class DefaultExceptionMapper implements ExceptionMapper
{
    public function __construct(private bool $debugOutputEnabled = false) {}

    /**
     * @return class-string<Throwable>
     */
    #[Override]
    public function exceptionClass(): string
    {
        return Throwable::class;
    }

    #[Override]
    public function map(Throwable $exception): InternalServerErrorException
    {
        $meta = $this->debugOutputEnabled ? ['exceptions' => self::chain($exception)] : [];

        return new InternalServerErrorException(
            [new ErrorDescription(ErrorCode::InternalError, meta: $meta)],
            $exception,
        );
    }

    /**
     * @return non-empty-list<array{
     *     class: string,
     *     message: string,
     *     file: string,
     *     line: int,
     *     trace: list<array<string, int|string>>,
     * }>
     */
    private static function chain(Throwable $exception): array
    {
        $chain = [];

        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $chain[] = [
                'class' => $current::class,
                'message' => $current->getMessage(),
                'file' => $current->getFile(),
                'line' => $current->getLine(),
                'trace' => self::trace($current),
            ];
        }

        return $chain;
    }

    /**
     * @return list<array<string, int|string>>
     */
    private static function trace(Throwable $exception): array
    {
        $trace = [];

        foreach ($exception->getTrace() as $frame) {
            $trace[] = array_intersect_key(
                $frame,
                ['file' => true, 'line' => true, 'class' => true, 'type' => true, 'function' => true],
            );
        }

        return $trace;
    }
}
