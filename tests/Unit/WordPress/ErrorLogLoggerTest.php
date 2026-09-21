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

namespace Polunich\WpJsonApi\Tests\Unit\WordPress;

use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Tests\Fixtures\WordPress\ErrorLog;
use Polunich\WpJsonApi\WordPress\ErrorLogLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use ReflectionClass;
use RuntimeException;
use Stringable;

/**
 * @internal
 */
#[CoversClass(ErrorLogLogger::class)]
final class ErrorLogLoggerTest extends TestCase
{
    public function testTheClassIsAFinalInternalLogger(): void
    {
        $class = new ReflectionClass(ErrorLogLogger::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->implementsInterface(LoggerInterface::class));
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    #[DataProvider('levels')]
    public function testALineNamesTheChannelAndTheLevel(string $level): void
    {
        $logged = ErrorLog::capturedWhile(static function () use ($level): void {
            (new ErrorLogLogger('the pets api'))->{$level}('it broke');
        });

        self::assertStringContainsString('wp-jsonapi [the pets api] ' . $level . ': it broke', $logged);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function levels(): iterable
    {
        foreach ([
            LogLevel::EMERGENCY,
            LogLevel::ALERT,
            LogLevel::CRITICAL,
            LogLevel::ERROR,
            LogLevel::WARNING,
            LogLevel::NOTICE,
            LogLevel::INFO,
            LogLevel::DEBUG,
        ] as $level) {
            yield $level => [$level];
        }
    }

    public function testPlaceholdersAreInterpolatedFromTheContext(): void
    {
        $stringable = new class implements Stringable {
            #[Override]
            public function __toString(): string
            {
                return 'stringable';
            }
        };

        $logged = ErrorLog::capturedWhile(static function () use ($stringable): void {
            (new ErrorLogLogger('api'))->error(
                '{text} {number} {flag} {none} {object} {list} {missing}',
                ['text' => 'a', 'number' => 7, 'flag' => false, 'none' => null, 'object' => $stringable, 'list' => [1]],
            );
        });

        self::assertStringContainsString('error: a 7 false  stringable {list} {missing}', $logged);
    }

    public function testAThrowableUnderTheExceptionKeyFollowsWithItsChain(): void
    {
        $failure = new RuntimeException('outer', 0, new LogicException('inner'));

        $logged = ErrorLog::capturedWhile(static function () use ($failure): void {
            (new ErrorLogLogger('api'))->error('it broke', ['exception' => $failure]);
        });

        self::assertStringContainsString('error: it broke', $logged);
        self::assertStringContainsString('RuntimeException: outer', $logged);
        self::assertStringContainsString('LogicException: inner', $logged);
    }

    public function testALevelThatIsNoneOfTheEightIsRefused(): void
    {
        $refusal = null;

        $logged = ErrorLog::capturedWhile(static function () use (&$refusal): void {
            try {
                (new ErrorLogLogger('api'))->log('fatal', 'it broke');
            } catch (InvalidArgumentException $exception) {
                $refusal = $exception;
            }
        });

        self::assertInstanceOf(InvalidArgumentException::class, $refusal);
        self::assertStringContainsString('"fatal"', $refusal->getMessage());
        self::assertSame('', $logged);
    }
}
