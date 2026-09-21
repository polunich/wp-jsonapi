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

use Override;
use Polunich\WpJsonApi\Text\Printable;
use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use Stringable;
use Throwable;

/**
 * writes to PHP's `error_log()`: WordPress has no logging API, core writes there itself,
 * and `WP_DEBUG_LOG` only points it at a file,
 * so each line lands where the site already reads PHP's messages.
 *
 * placeholders are interpolated from the context values that have a string form;
 * any other value keeps its placeholder: PSR-3 forbids a context value to raise an error.
 * a throwable under the `exception` key follows on the next lines as PHP prints it,
 * trace and previous throwables included
 *
 * @internal
 */
final class ErrorLogLogger extends AbstractLogger
{
    private const LEVELS = [
        LogLevel::EMERGENCY,
        LogLevel::ALERT,
        LogLevel::CRITICAL,
        LogLevel::ERROR,
        LogLevel::WARNING,
        LogLevel::NOTICE,
        LogLevel::INFO,
        LogLevel::DEBUG,
    ];

    /**
     * @param string $channel the name of the API, which tells the APIs of one site apart
     */
    public function __construct(private readonly string $channel) {}

    /**
     * @param array<array-key, mixed> $context
     *
     * @throws InvalidArgumentException when the level is none of PSR-3's eight, as PSR-3 requires
     */
    #[Override]
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (!is_string($level) || !in_array($level, self::LEVELS, true)) {
            throw new InvalidArgumentException(sprintf(
                'the log level %s is none of the eight levels of PSR-3',
                is_string($level) ? '"' . Printable::of($level) . '"' : get_debug_type($level),
            ));
        }

        $line = sprintf(
            'wp-jsonapi [%s] %s: %s',
            $this->channel,
            $level,
            self::interpolate((string) $message, $context),
        );
        $exception = $context['exception'] ?? null;

        if ($exception instanceof Throwable) {
            $line .= "\n" . $exception;
        }

        error_log($line);
    }

    /**
     * @param array<array-key, mixed> $context
     */
    private static function interpolate(string $message, array $context): string
    {
        $replacements = [];

        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null || $value instanceof Stringable) {
                $replacements['{' . $key . '}'] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }
        }

        return strtr($message, $replacements);
    }
}
