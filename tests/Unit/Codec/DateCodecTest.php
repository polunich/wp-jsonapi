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

namespace Polunich\WpJsonApi\Tests\Unit\Codec;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\DateCodec;
use Polunich\WpJsonApi\Codec\DecodeException;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Codec\Violation;

/**
 * @internal
 */
#[CoversClass(DateCodec::class)]
final class DateCodecTest extends TestCase
{
    public function testADateIsDecodedAsMidnightUtc(): void
    {
        $decoded = (new DateCodec())->decode('2026-09-16', Pointer::root());

        self::assertSame('2026-09-16T00:00:00+00:00', $decoded->format(DateTimeInterface::RFC3339));
        self::assertSame('UTC', $decoded->getTimezone()->getName());
    }

    public function testALeapDayIsADate(): void
    {
        $decoded = (new DateCodec())->decode('2024-02-29', Pointer::root());

        self::assertSame('2024-02-29', $decoded->format('Y-m-d'));
    }

    #[DataProvider('stringsThatAreNoDate')]
    public function testAStringThatIsNoFullDateIsRefused(string $input): void
    {
        $violation = self::violation($input);

        self::assertSame('invalid_format', $violation->code);
        self::assertSame(['format' => 'date'], $violation->parameters);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stringsThatAreNoDate(): iterable
    {
        yield 'a trailing line feed' => ["2026-09-16\n"];
        yield 'a leading line feed' => ["\n2026-09-16"];
        yield 'an empty string' => [''];
        yield 'a month without a leading zero' => ['2026-9-16'];
        yield 'a day without a leading zero' => ['2026-09-6'];
        yield 'a year of two digits' => ['26-09-16'];
        yield 'slashes instead of dashes' => ['2026/09/16'];
        yield 'a date-time' => ['2026-09-16T00:00:00Z'];
        yield 'a day the month does not have' => ['2026-02-30'];
        yield 'a leap day of a common year' => ['2025-02-29'];
        yield 'the month zero' => ['2026-00-16'];
        yield 'the month thirteen' => ['2026-13-16'];
        yield 'the day zero' => ['2026-09-00'];
    }

    #[DataProvider('valuesThatAreNoString')]
    public function testAValueThatIsNoStringIsRefused(mixed $input): void
    {
        $violation = self::violation($input);

        self::assertSame('invalid_type', $violation->code);
        self::assertSame(['expected' => 'string'], $violation->parameters);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function valuesThatAreNoString(): iterable
    {
        yield 'null' => [null];
        yield 'an integer' => [20260916];
        yield 'a list' => [['2026-09-16']];
        yield 'an object' => [(object) ['date' => '2026-09-16']];
    }

    public function testTheSchemaNamesTheStringTypeAndTheDateFormat(): void
    {
        self::assertSame(
            ['type' => 'string', 'format' => 'date'],
            get_object_vars((new DateCodec())->schema()->toObject()),
        );
    }

    public function testEncodeWritesTheCalendarDay(): void
    {
        $value = new DateTimeImmutable('2026-09-16 13:45:00', new DateTimeZone('UTC'));

        self::assertSame('2026-09-16', (new DateCodec())->encode($value));
    }

    public function testEncodeAcceptsEveryDateTimeInterface(): void
    {
        $value = new DateTime('2026-09-16 00:00:00', new DateTimeZone('UTC'));

        self::assertSame('2026-09-16', (new DateCodec())->encode($value));
    }

    public function testEncodeRefusesAValueThatIsNoPointInTime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the value must implement ' . DateTimeInterface::class);

        (new DateCodec())->encode('2026-09-16');
    }

    private static function violation(mixed $input): Violation
    {
        try {
            (new DateCodec())->decode($input, Pointer::root());
        } catch (DecodeException $exception) {
            return $exception->violations()[0];
        }

        self::fail('the codec accepted the value');
    }
}
