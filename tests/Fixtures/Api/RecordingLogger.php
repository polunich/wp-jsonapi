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

namespace Polunich\WpJsonApi\Tests\Fixtures\Api;

use Override;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Throwable;

/**
 * @internal
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    public function __construct(private readonly bool $fails = false) {}

    /**
     * @param array<array-key, mixed> $context
     */
    #[Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];

        if ($this->fails) {
            throw new RuntimeException('the logger broke');
        }
    }

    /**
     * @return list<Throwable>
     */
    public function throwables(): array
    {
        $throwables = [];

        foreach ($this->records as $record) {
            $exception = $record['context']['exception'] ?? null;

            if ($exception instanceof Throwable) {
                $throwables[] = $exception;
            }
        }

        return $throwables;
    }
}
