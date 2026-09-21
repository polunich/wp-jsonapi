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
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\DateTimeCodec;
use Polunich\WpJsonApi\Codec\DecodeException;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Codec\Violation;

/**
 * @internal
 */
#[CoversClass(DateTimeCodec::class)]
final class DateTimeCodecTest extends TestCase
{
    #[DataProvider('dateTimesAndTheirMoment')]
    public function testADateTimeKeepsTheOffsetItWasWrittenWith(string $input, string $expected): void
    {
        $decoded = (new DateTimeCodec())->decode($input, Pointer::root());

        self::assertSame($expected, $decoded->format('Y-m-d\TH:i:s.uP'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dateTimesAndTheirMoment(): iterable
    {
        yield 'Z' => ['2026-09-16T10:00:00Z', '2026-09-16T10:00:00.000000+00:00'];
        yield 'a lowercase t and z, which RFC 3339 allows' => [
            '2026-09-16t10:00:00z',
            '2026-09-16T10:00:00.000000+00:00',
        ];
        yield 'a positive offset' => ['2026-09-16T10:00:00+02:30', '2026-09-16T10:00:00.000000+02:30'];
        yield 'a negative offset' => ['2026-09-16T10:00:00-05:00', '2026-09-16T10:00:00.000000-05:00'];
        yield 'a fraction of a second' => ['2026-09-16T10:00:00.5Z', '2026-09-16T10:00:00.500000+00:00'];
    }

    public function testAFractionLongerThanMicrosecondsIsCut(): void
    {
        $decoded = (new DateTimeCodec())->decode('2026-09-16T10:00:00.1234567Z', Pointer::root());

        self::assertSame('123456', $decoded->format('u'));
    }

    public function testALeapSecondBecomesTheSecondThatFollowsIt(): void
    {
        $decoded = (new DateTimeCodec())->decode('2026-09-16T23:59:60Z', Pointer::root());

        self::assertSame('2026-09-17T00:00:00+00:00', $decoded->format(DateTimeInterface::RFC3339));
    }

    #[DataProvider('stringsThatAreNoDateTime')]
    public function testAStringThatIsNoDateTimeIsRefused(string $input): void
    {
        $violation = self::violation($input);

        self::assertSame('invalid_format', $violation->code);
        self::assertSame(['format' => 'date-time'], $violation->parameters);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stringsThatAreNoDateTime(): iterable
    {
        yield 'a trailing line feed' => ["2026-09-16T10:00:00Z\n"];
        yield 'a leading line feed' => ["\n2026-09-16T10:00:00Z"];
        yield 'an empty string' => [''];
        yield 'a date alone' => ['2026-09-16'];
        yield 'no offset' => ['2026-09-16T10:00:00'];
        yield 'a space instead of the T' => ['2026-09-16 10:00:00Z'];
        yield 'an offset without a colon' => ['2026-09-16T10:00:00+0200'];
        yield 'a day the month does not have' => ['2026-02-30T10:00:00Z'];
        yield 'the hour twenty-four' => ['2026-09-16T24:00:00Z'];
        yield 'the minute sixty' => ['2026-09-16T10:60:00Z'];
        yield 'the second sixty-one' => ['2026-09-16T10:00:61Z'];
        yield 'an offset hour of twenty-four' => ['2026-09-16T10:00:00+24:00'];
        yield 'an offset minute of sixty' => ['2026-09-16T10:00:00+02:60'];
        yield 'a fraction without a digit' => ['2026-09-16T10:00:00.Z'];
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
        yield 'a unix timestamp' => [1789574400];
        yield 'a list' => [['2026-09-16T10:00:00Z']];
        yield 'an object' => [(object) ['at' => '2026-09-16T10:00:00Z']];
    }

    public function testTheSchemaNamesTheStringTypeAndTheDateTimeFormat(): void
    {
        self::assertSame(
            ['type' => 'string', 'format' => 'date-time'],
            get_object_vars((new DateTimeCodec())->schema()->toObject()),
        );
    }

    public function testEncodeLeavesOutAFractionOfZero(): void
    {
        $value = new DateTimeImmutable('2026-09-16T10:00:00+02:00');

        self::assertSame('2026-09-16T10:00:00+02:00', (new DateTimeCodec())->encode($value));
    }

    public function testEncodeWritesAFractionThatIsNotZero(): void
    {
        $value = new DateTimeImmutable('2026-09-16T10:00:00.123456+02:00');

        self::assertSame('2026-09-16T10:00:00.123456+02:00', (new DateTimeCodec())->encode($value));
    }

    public function testEncodeWritesTheOffsetOfUtcAsAnOffset(): void
    {
        $value = new DateTimeImmutable('2026-09-16T10:00:00Z');

        self::assertSame('2026-09-16T10:00:00+00:00', (new DateTimeCodec())->encode($value));
    }

    public function testEncodeAcceptsEveryDateTimeInterface(): void
    {
        $value = new DateTime('2026-09-16T10:00:00+02:00');

        self::assertSame('2026-09-16T10:00:00+02:00', (new DateTimeCodec())->encode($value));
    }

    public function testEncodeRefusesAValueThatIsNoPointInTime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the value must implement ' . DateTimeInterface::class);

        (new DateTimeCodec())->encode('2026-09-16T10:00:00Z');
    }

    public function testWhatEncodeWritesDecodeReads(): void
    {
        $codec = new DateTimeCodec();
        $value = new DateTimeImmutable('2026-09-16T10:00:00.123456+02:00');

        $decoded = $codec->decode($codec->encode($value), Pointer::root());

        self::assertSame($value->format('Y-m-d\TH:i:s.uP'), $decoded->format('Y-m-d\TH:i:s.uP'));
    }

    private static function violation(mixed $input): Violation
    {
        try {
            (new DateTimeCodec())->decode($input, Pointer::root());
        } catch (DecodeException $exception) {
            return $exception->violations()[0];
        }

        self::fail('the codec accepted the value');
    }
}
