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

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\DecodeException;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Codec\UuidCodec;
use Polunich\WpJsonApi\Codec\Violation;

/**
 * @internal
 */
#[CoversClass(UuidCodec::class)]
final class UuidCodecTest extends TestCase
{
    private const UUID = 'f81d4fae-7dec-11d0-a765-00a0c91e6bf6';

    public function testADecodedUuidIsLowercased(): void
    {
        self::assertSame(self::UUID, (new UuidCodec())->decode(strtoupper(self::UUID), Pointer::root()));
    }

    #[DataProvider('stringsThatAreNoUuid')]
    public function testAStringThatIsNoUuidIsRefused(string $input): void
    {
        $violation = self::violation($input);

        self::assertSame('invalid_format', $violation->code);
        self::assertSame(['format' => 'uuid'], $violation->parameters);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stringsThatAreNoUuid(): iterable
    {
        yield 'a trailing line feed' => [self::UUID . "\n"];
        yield 'a leading line feed' => ["\n" . self::UUID];
        yield 'a trailing space' => [self::UUID . ' '];
        yield 'a trailing null byte' => [self::UUID . "\0"];
        yield 'an empty string' => [''];
        yield 'no dashes' => [str_replace('-', '', self::UUID)];
        yield 'a group that is too short' => ['f81d4fae-7dec-11d0-a765-00a0c91e6bf'];
        yield 'a group that is too long' => ['f81d4fae-7dec-11d0-a765-00a0c91e6bf67'];
        yield 'a character that is no hex digit' => ['g81d4fae-7dec-11d0-a765-00a0c91e6bf6'];
        yield 'the braced form' => ['{' . self::UUID . '}'];
        yield 'the URN form' => ['urn:uuid:' . self::UUID];
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
        yield 'an integer' => [1];
        yield 'a list' => [[self::UUID]];
        yield 'an object' => [(object) ['id' => self::UUID]];
    }

    #[DataProvider('uuidsOfNoDefinedVersion')]
    public function testTheVersionAndTheVariantAreNotRead(string $input): void
    {
        self::assertSame($input, (new UuidCodec())->decode($input, Pointer::root()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function uuidsOfNoDefinedVersion(): iterable
    {
        yield 'the nil UUID' => ['00000000-0000-0000-0000-000000000000'];
        yield 'the max UUID' => ['ffffffff-ffff-ffff-ffff-ffffffffffff'];
        yield 'a version nibble RFC 9562 does not define' => ['f81d4fae-7dec-f1d0-a765-00a0c91e6bf6'];
    }

    public function testTheSchemaNamesTheStringTypeAndTheUuidFormat(): void
    {
        self::assertSame(
            ['type' => 'string', 'format' => 'uuid'],
            get_object_vars((new UuidCodec())->schema()->toObject()),
        );
    }

    public function testTheSegmentPatternAdmitsAUuidInEitherCase(): void
    {
        self::assertSame(1, preg_match(self::segment(), self::UUID));
        self::assertSame(1, preg_match(self::segment(), strtoupper(self::UUID)));
    }

    #[DataProvider('stringsThatAreNoUuid')]
    public function testTheSegmentPatternRefusesWhatDecodeRefuses(string $input): void
    {
        self::assertSame(0, preg_match(self::segment(), $input));
    }

    public function testEncodeLowercasesTheUuid(): void
    {
        self::assertSame(self::UUID, (new UuidCodec())->encode(strtoupper(self::UUID)));
    }

    #[DataProvider('valuesEncodeRefuses')]
    public function testEncodeRefusesAValueThatIsNoUuid(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the value must be a UUID in the string form of RFC 9562');

        (new UuidCodec())->encode($value);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function valuesEncodeRefuses(): iterable
    {
        yield 'a trailing line feed' => [self::UUID . "\n"];
        yield 'a string that is no UUID' => ['not a UUID'];
        yield 'an integer' => [1];
        yield 'null' => [null];
    }

    /**
     * the segment pattern as a whole-string match that reads bytes, as a route pattern does
     */
    private static function segment(): string
    {
        return "\x07^(?:" . (new UuidCodec())->segmentPattern() . ")$\x07D";
    }

    private static function violation(mixed $input): Violation
    {
        try {
            (new UuidCodec())->decode($input, Pointer::root());
        } catch (DecodeException $exception) {
            return $exception->violations()[0];
        }

        self::fail('the codec accepted the value');
    }
}
