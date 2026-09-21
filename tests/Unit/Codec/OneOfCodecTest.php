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
use Polunich\WpJsonApi\Codec\ConstCodec;
use Polunich\WpJsonApi\Codec\DecodeException;
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Codec\ObjectCodec;
use Polunich\WpJsonApi\Codec\OneOfCodec;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Codec\Violation;
use TypeError;

/**
 * @internal
 */
#[CoversClass(OneOfCodec::class)]
final class OneOfCodecTest extends TestCase
{
    public function testTheVariantNamedByTheDiscriminatingMemberDecodesTheObject(): void
    {
        $decoded = self::codec()->decode(self::wire('{"op":"add","value":1}'), Pointer::root());

        self::assertSame(['op' => 'add', 'value' => 1], $decoded);
    }

    public function testTheVariantSeesEveryMemberOfTheObject(): void
    {
        $decoded = self::codec()->decode(self::wire('{"op":"remove","name":"a"}'), Pointer::root());

        self::assertSame(['op' => 'remove', 'name' => 'a'], $decoded);
    }

    #[DataProvider('valuesThatAreNoJsonObject')]
    public function testAValueThatIsNoJsonObjectIsRefused(mixed $input): void
    {
        $violation = self::violation($input);

        self::assertSame('invalid_type', $violation->code);
        self::assertSame(['expected' => 'object'], $violation->parameters);
        self::assertSame('', $violation->pointer->toString());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function valuesThatAreNoJsonObject(): iterable
    {
        yield 'null' => [null];
        yield 'a string' => ['{}'];
        yield 'an empty list, the wire form of []' => [[]];
        yield 'an associative array' => [['op' => 'add']];
    }

    public function testAnObjectWithoutTheDiscriminatingMemberIsRefused(): void
    {
        $violation = self::violation(self::wire('{"value":1}'));

        self::assertSame('missing_member', $violation->code);
        self::assertSame(['member' => 'op'], $violation->parameters);
        self::assertSame('', $violation->pointer->toString());
    }

    public function testADiscriminatingMemberThatIsNoStringIsRefusedAtThatMember(): void
    {
        $violation = self::violation(self::wire('{"op":1}'));

        self::assertSame('invalid_type', $violation->code);
        self::assertSame(['expected' => 'string'], $violation->parameters);
        self::assertSame('/op', $violation->pointer->toString());
    }

    public function testADiscriminatingMemberOfNoVariantIsRefusedWithTheAllowedValues(): void
    {
        $violation = self::violation(self::wire('{"op":"replace"}'));

        self::assertSame('value_not_allowed', $violation->code);
        self::assertSame(['allowed' => 'add, remove'], $violation->parameters);
        self::assertSame('/op', $violation->pointer->toString());
    }

    public function testANullDiscriminatingMemberIsPresentButOfTheWrongType(): void
    {
        $violation = self::violation(self::wire('{"op":null}'));

        self::assertSame('invalid_type', $violation->code);
        self::assertSame('/op', $violation->pointer->toString());
    }

    public function testAVariantIsToldApartByAValueOfDigits(): void
    {
        $codec = self::digitsCodec();

        self::assertSame(['type' => '1'], $codec->decode(self::wire('{"type":"1"}'), Pointer::root()));
        self::assertSame(['type' => '2'], $codec->decode(self::wire('{"type":"2"}'), Pointer::root()));
        self::assertEquals((object) ['type' => '1'], $codec->encode(['type' => '1']));
    }

    public function testTheAllowedValuesOfDigitsAreNamedAsTheDocumentWritesThem(): void
    {
        $violation = self::violation(self::wire('{"type":"3"}'), self::digitsCodec());

        self::assertSame('value_not_allowed', $violation->code);
        self::assertSame(['allowed' => '1, 2'], $violation->parameters);
    }

    public function testACodecWithoutAVariantIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the codec must have at least one variant');

        new OneOfCodec('op', [], static function (array $value): string {
            return 'add';
        });
    }

    public function testTheSchemaIsTheOneOfOfTheVariants(): void
    {
        self::assertSame(
            '{"oneOf":['
            . '{"type":"object","properties":{"op":{"type":"string","const":"add"},'
            . '"value":{"type":"integer","minimum":0,"maximum":10}},'
            . '"required":["op"],"additionalProperties":false},'
            . '{"type":"object","properties":{"op":{"type":"string","const":"remove"},'
            . '"name":{"type":"string"}},'
            . '"required":["op"],"additionalProperties":false}'
            . ']}',
            json_encode(self::codec()->schema()->toObject()),
        );
    }

    public function testEncodeUsesTheVariantTheDiscriminatorNames(): void
    {
        $encoded = self::codec()->encode(['op' => 'add', 'value' => 1]);

        self::assertEquals((object) ['op' => 'add', 'value' => 1], $encoded);
    }

    public function testEncodeRefusesAValueOfNoDeclaredVariant(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the value belongs to no declared variant: replace');

        self::codec()->encode(['op' => 'replace']);
    }

    public function testEncodeTurnsATypeErrorOfTheDiscriminatorIntoAnInvalidArgument(): void
    {
        try {
            self::encode(self::codec(), 'not an operation');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('the value is not one this codec decodes', $exception->getMessage());
            self::assertInstanceOf(TypeError::class, $exception->getPrevious());

            return;
        }

        self::fail('the codec accepted the value');
    }

    /**
     * @return OneOfCodec<array<int|string, mixed>>
     */
    private static function codec(): OneOfCodec
    {
        return new OneOfCodec(
            'op',
            [
                'add' => new ObjectCodec(
                    ['op' => new ConstCodec('add'), 'value' => new IntegerCodec(minimum: 0, maximum: 10)],
                    ['op'],
                ),
                'remove' => new ObjectCodec(
                    ['op' => new ConstCodec('remove'), 'name' => new StringCodec()],
                    ['op'],
                ),
            ],
            static function (array $value): string {
                return is_string($value['op'] ?? null) ? $value['op'] : '';
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
     * @return OneOfCodec<array<int|string, mixed>>
     */
    private static function digitsCodec(): OneOfCodec
    {
        return new OneOfCodec(
            'type',
            [
                '1' => new ObjectCodec(['type' => new ConstCodec('1')], ['type']),
                '2' => new ObjectCodec(['type' => new ConstCodec('2')], ['type']),
            ],
            static function (array $value): string {
                return is_string($value['type'] ?? null) ? $value['type'] : '';
            },
        );
    }

    private static function wire(string $json): mixed
    {
        return json_decode($json, false, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param OneOfCodec<array<int|string, mixed>>|null $codec
     */
    private static function violation(mixed $input, ?OneOfCodec $codec = null): Violation
    {
        try {
            ($codec ?? self::codec())->decode($input, Pointer::root());
        } catch (DecodeException $exception) {
            return $exception->violations()[0];
        }

        self::fail('the codec accepted the value');
    }

    public function testAValueOfNoDeclaredVariantIsNamedEscaped(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the value belongs to no declared variant: a\\nb');

        self::encode(self::codec(), ['op' => "a\nb"]);
    }
}
