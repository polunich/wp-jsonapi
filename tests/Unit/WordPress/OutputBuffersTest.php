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

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\WordPress\OutputBuffers;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(OutputBuffers::class)]
final class OutputBuffersTest extends TestCase
{
    private const DEFAULT = 'default output handler';

    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(OutputBuffers::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    /**
     * @param list<array<string, mixed>> $buffers
     */
    #[DataProvider('lengths')]
    public function testALengthIsKeptWhereNothingBetweenChangesTheBytes(
        array $buffers,
        bool $compressing,
        bool $expected,
    ): void {
        $outputBuffers = new OutputBuffers(
            static function () use ($buffers): array {
                return $buffers;
            },
            null,
            null,
            static function () use ($compressing): bool {
                return $compressing;
            },
        );

        self::assertSame($expected, $outputBuffers->keepLength());
    }

    /**
     * @return iterable<string, array{list<array<string, mixed>>, bool, bool}>
     */
    public static function lengths(): iterable
    {
        $empty = ['name' => self::DEFAULT, 'flags' => PHP_OUTPUT_HANDLER_STDFLAGS, 'buffer_used' => 0];

        yield 'no buffer' => [[], false, true];
        yield 'empty default buffers' => [[$empty, $empty], false, true];
        yield 'compression' => [[], true, false];
        yield 'a buffer holding output' => [[['buffer_used' => 3] + $empty], false, false];
        yield 'a buffer of another handler' => [[['name' => 'ob_gzhandler'] + $empty], false, false];
        yield 'a buffer that cannot be removed' => [[['flags' => PHP_OUTPUT_HANDLER_CLEANABLE] + $empty], false, false];
    }

    public function testTheRemovableBuffersEndFromTheTopDownToTheFirstThatIsNot(): void
    {
        $open = [
            ['name' => self::DEFAULT, 'flags' => PHP_OUTPUT_HANDLER_STDFLAGS],
            ['name' => 'fixed', 'flags' => PHP_OUTPUT_HANDLER_CLEANABLE],
            ['name' => 'plugin', 'flags' => PHP_OUTPUT_HANDLER_STDFLAGS],
            ['name' => self::DEFAULT, 'flags' => PHP_OUTPUT_HANDLER_STDFLAGS],
        ];
        $ended = [];
        $buffers = new OutputBuffers(
            static function () use (&$open): array {
                return $open;
            },
            static function () use (&$open, &$ended): bool {
                $ended[] = array_pop($open)['name'] ?? '';

                return true;
            },
        );

        $buffers->endRemovable();

        self::assertSame([self::DEFAULT, 'plugin'], $ended);
    }

    public function testEndingStopsWhereABufferRefusesToEnd(): void
    {
        $calls = 0;
        $buffers = new OutputBuffers(
            static function (): array {
                return [['flags' => PHP_OUTPUT_HANDLER_STDFLAGS], ['flags' => PHP_OUTPUT_HANDLER_STDFLAGS]];
            },
            static function () use (&$calls): bool {
                $calls++;

                return false;
            },
        );

        $buffers->endRemovable();

        self::assertSame(1, $calls);
    }

    public function testFlushPassesOn(): void
    {
        $flushed = 0;
        $buffers = new OutputBuffers(null, null, static function () use (&$flushed): void {
            $flushed++;
        });

        $buffers->flush();

        self::assertSame(1, $flushed);
    }

    /**
     * a buffer holding output keeps no length
     */
    public function testTheDefaultsReadTheRunningPhp(): void
    {
        ob_start();

        try {
            echo 'Rex';

            self::assertFalse((new OutputBuffers())->keepLength());
        } finally {
            ob_end_clean();
        }
    }

    /**
     * "This option also accepts integer values instead of boolean "On"/"Off",
     * using this you can set the output buffer size" (php.net, zlib runtime configuration),
     * so a size turns compression on as well.
     * a process of its own: the setting changes only before any output is sent
     */
    #[DataProvider('compressionSettings')]
    #[RunInSeparateProcess]
    public function testTheDefaultReadsCompressionFromTheRunningPhp(string $setting, bool $kept): void
    {
        self::assertNotFalse(ini_set('zlib.output_compression', $setting));

        $buffers = new OutputBuffers(static function (): array {
            return [];
        });

        self::assertSame($kept, $buffers->keepLength());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function compressionSettings(): iterable
    {
        yield 'off' => ['Off', true];
        yield 'zero' => ['0', true];
        yield 'on' => ['On', false];
        yield 'one' => ['1', false];
        yield 'a buffer size' => ['4096', false];
    }
}
