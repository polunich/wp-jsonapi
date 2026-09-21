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
use Polunich\WpJsonApi\Codec\Codec;
use Polunich\WpJsonApi\Codec\DecodeException;
use Polunich\WpJsonApi\Codec\ListCodec;
use Polunich\WpJsonApi\Codec\MappedCodec;
use Polunich\WpJsonApi\Codec\ObjectCodec;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Codec\RejectedValueException;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Codec\Violation;
use Polunich\WpJsonApi\Tests\Fixtures\Codec\Slug;
use stdClass;
use TypeError;

/**
 * @internal
 */
#[CoversClass(MappedCodec::class)]
final class MappedCodecTest extends TestCase
{
    public function testTheDecodedValueIsHandedToToValue(): void
    {
        self::assertSame('a-slug', self::codec()->decode('a-slug', Pointer::root())->text);
    }

    public function testAViolationOfTheInnerCodecIsNotTouched(): void
    {
        try {
            self::codec()->decode(1, Pointer::root()->descend('slug'));
        } catch (DecodeException $exception) {
            $violation = $exception->violations()[0];

            self::assertSame('invalid_type', $violation->code);
            self::assertSame(['expected' => 'string'], $violation->parameters);
            self::assertSame('/slug', $violation->pointer->toString());
            self::assertNull($exception->getPrevious());

            return;
        }

        self::fail('the codec accepted the value');
    }

    public function testARejectedValueBecomesAViolationAtThePointer(): void
    {
        try {
            self::codec()->decode('taken', Pointer::root()->descend('slug'));
        } catch (DecodeException $exception) {
            $violation = $exception->violations()[0];

            self::assertSame('slug_taken', $violation->code);
            self::assertSame(['slug' => 'taken'], $violation->parameters);
            self::assertSame('/slug', $violation->pointer->toString());
            self::assertInstanceOf(RejectedValueException::class, $exception->getPrevious());

            return;
        }

        self::fail('the codec accepted the value');
    }

    public function testEncodeTurnsTheValueBackWithTheInnerCodec(): void
    {
        self::assertSame('a-slug', self::codec()->encode(new Slug('a-slug')));
    }

    public function testEncodeTurnsATypeErrorOfFromValueIntoAnInvalidArgument(): void
    {
        try {
            self::encode(self::codec(), 'not a value object');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('the value is not one this codec decodes', $exception->getMessage());
            self::assertInstanceOf(TypeError::class, $exception->getPrevious());

            return;
        }

        self::fail('the codec accepted the value');
    }

    public function testTheSchemaIsTheSchemaOfTheInnerCodec(): void
    {
        $inner = new StringCodec(maxLength: 200);

        $codec = new MappedCodec(
            $inner,
            static function (string $text): Slug {
                return new Slug($text);
            },
            static function (Slug $slug): string {
                return $slug->text;
            },
        );

        self::assertSame(json_encode($inner->schema()->toObject()), json_encode($codec->schema()->toObject()));
    }

    /**
     * @return MappedCodec<string, Slug>
     */
    private static function codec(): MappedCodec
    {
        return new MappedCodec(
            new StringCodec(),
            static function (string $text): Slug {
                return $text === 'taken'
                    ? throw new RejectedValueException('slug_taken', ['slug' => $text])
                    : new Slug($text);
            },
            static function (Slug $slug): string {
                return $slug->text;
            },
        );
    }

    /**
     * widens to `Codec<mixed>`, whose `encode()` takes any value past static analysis
     *
     * @param Codec<mixed> $codec
     */
    private static function encode(Codec $codec, mixed $value): mixed
    {
        return $codec->encode($value);
    }

    /**
     * a rule between members names the member to correct, as the error source of that member
     *
     * @param list<string> $path
     */
    #[DataProvider('pathsIntoTheValue')]
    public function testARejectionWithAPathPointsAtTheMemberTheInputHolds(
        array $path,
        string $input,
        string $pointer,
    ): void {
        $violation = self::rejection(self::schedule($path), $input);

        self::assertSame('end_before_start', $violation->code);
        self::assertSame(['start' => 'b'], $violation->parameters);
        self::assertSame($pointer, $violation->pointer->toString());
    }

    /**
     * @return iterable<string, array{list<string>, string, string}>
     */
    public static function pathsIntoTheValue(): iterable
    {
        $input = '{"start":"b","end":"a","days":["mon","tue"],"0":"x"}';

        yield 'no path' => [[], $input, '/schedule'];
        yield 'a member' => [['end'], $input, '/schedule/end'];
        yield 'an item of a list' => [['days', '1'], $input, '/schedule/days/1'];
        yield 'a member name of digits' => [['0'], $input, '/schedule/0'];
        yield 'a member the input lacks' => [['end'], '{"start":"b"}', '/schedule'];
        yield 'an index past the end of the list' => [['days', '2'], $input, '/schedule/days'];
        yield 'an index with a leading zero' => [['days', '01'], $input, '/schedule/days'];
        yield 'a token below a string' => [['end', 'x'], $input, '/schedule/end'];
    }

    /**
     * rejects every value whose `end` is not after its `start`, at `$path`
     *
     * @param list<string> $path
     *
     * @return MappedCodec<array<int|string, mixed>, stdClass>
     */
    private static function schedule(array $path): MappedCodec
    {
        return new MappedCodec(
            new ObjectCodec(
                [
                    'start' => new StringCodec(),
                    'end' => new StringCodec(),
                    'days' => new ListCodec(new StringCodec()),
                    '0' => new StringCodec(),
                ],
            ),
            static function (array $members) use ($path): stdClass {
                $start = $members['start'] ?? null;
                $start = is_string($start) ? $start : '';
                $end = $members['end'] ?? null;

                if (!is_string($end) || $end <= $start) {
                    throw new RejectedValueException('end_before_start', ['start' => $start], path: $path);
                }

                return (object) $members;
            },
            static function (stdClass $schedule): array {
                return get_object_vars($schedule);
            },
        );
    }

    /**
     * @param Codec<mixed> $codec
     */
    private static function rejection(Codec $codec, string $json): Violation
    {
        try {
            $codec->decode(json_decode($json, false, flags: JSON_THROW_ON_ERROR), Pointer::root()->descend('schedule'));
        } catch (DecodeException $exception) {
            return $exception->violations()[0];
        }

        self::fail('the codec accepted the value');
    }
}
