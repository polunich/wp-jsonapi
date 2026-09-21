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
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Codec\MapCodec;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Codec\Violation;

/**
 * @internal
 */
#[CoversClass(MapCodec::class)]
final class MapCodecTest extends TestCase
{
    public function testEveryMemberIsDecodedWithTheCodecOfTheValues(): void
    {
        $codec = new MapCodec(new IntegerCodec());

        $decoded = $codec->decode(self::wire('{"a":1,"b":2}'), Pointer::root());

        self::assertSame(['a' => 1, 'b' => 2], $decoded);
    }

    public function testAnEmptyObjectIsDecodedAsAnEmptyArray(): void
    {
        self::assertSame([], (new MapCodec(new StringCodec()))->decode(self::wire('{}'), Pointer::root()));
    }

    #[DataProvider('valuesThatAreNoJsonObject')]
    public function testAValueThatIsNoJsonObjectIsRefused(mixed $input): void
    {
        $violation = self::violations(new MapCodec(new StringCodec()), $input)[0];

        self::assertSame('invalid_type', $violation->code);
        self::assertSame(['expected' => 'object'], $violation->parameters);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function valuesThatAreNoJsonObject(): iterable
    {
        yield 'null' => [null];
        yield 'a string' => ['{}'];
        yield 'an empty list, the wire form of []' => [[]];
        yield 'a list' => [['a']];
        yield 'an associative array' => [['a' => 'b']];
    }

    /**
     * the key is asserted through `array_keys()`: the literal `['0' => 'x']` is `[0 => 'x']`,
     * so comparing whole arrays would not show that the key is an int
     */
    public function testAMemberNameOfDigitsStaysAMemberName(): void
    {
        $codec = new MapCodec(new StringCodec());

        $decoded = $codec->decode(self::wire('{"0":"x"}'), Pointer::root());

        self::assertSame([0], array_keys($decoded));
        self::assertSame(['x'], array_values($decoded));
        self::assertSame('0', (string) array_key_first($decoded));
    }

    public function testAViolationOfAMemberWithANameOfDigitsPointsAtThatMember(): void
    {
        $violation = self::violations(new MapCodec(new IntegerCodec()), self::wire('{"0":"x"}'))[0];

        self::assertSame('invalid_type', $violation->code);
        self::assertSame('/0', $violation->pointer->toString());
    }

    public function testEveryInvalidMemberRaisesItsOwnViolation(): void
    {
        $violations = self::violations(new MapCodec(new IntegerCodec()), self::wire('{"a":"x","b":2,"c":null}'));

        self::assertSame(['invalid_type', 'invalid_type'], array_column($violations, 'code'));
        self::assertSame(
            ['/a', '/c'],
            array_map(static function (Violation $violation): string {
                return $violation->pointer->toString();
            }, $violations),
        );
    }

    public function testTheSchemaDescribesTheMembersWithAdditionalProperties(): void
    {
        self::assertSame(
            '{"type":"object","additionalProperties":{"type":"integer","minimum":0,"maximum":10}}',
            json_encode((new MapCodec(new IntegerCodec(minimum: 0, maximum: 10)))->schema()->toObject()),
        );
    }

    public function testEncodeReturnsAStdClass(): void
    {
        $encoded = (new MapCodec(new IntegerCodec()))->encode(['a' => 1]);

        self::assertEquals((object) ['a' => 1], $encoded);
    }

    public function testEncodeWritesAnEmptyMapAsAnEmptyObject(): void
    {
        self::assertSame('{}', json_encode((new MapCodec(new IntegerCodec()))->encode([])));
    }

    public function testEncodeRefusesAValueThatIsNoArrayOfMembers(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the value must be an array of members');

        (new MapCodec(new IntegerCodec()))->encode(1);
    }

    public function testEncodeRefusesAMemberTheCodecOfTheValuesRefuses(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the value must be an integer');

        (new MapCodec(new IntegerCodec()))->encode(['a' => 'x']);
    }

    private static function wire(string $json): mixed
    {
        return json_decode($json, false, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param Codec<mixed> $codec
     *
     * @return non-empty-list<Violation>
     */
    private static function violations(Codec $codec, mixed $input): array
    {
        try {
            $codec->decode($input, Pointer::root());
        } catch (DecodeException $exception) {
            return $exception->violations();
        }

        self::fail('the codec accepted the value');
    }
}
