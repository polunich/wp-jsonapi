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
use Polunich\WpJsonApi\Codec\ListCodec;
use Polunich\WpJsonApi\Codec\ObjectCodec;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Codec\Violation;
use stdClass;

/**
 * @internal
 */
#[CoversClass(ListCodec::class)]
final class ListCodecTest extends TestCase
{
    public function testEveryItemIsDecodedWithTheCodecOfTheItems(): void
    {
        $decoded = (new ListCodec(new IntegerCodec()))->decode(self::wire('[1,2,3]'), Pointer::root());

        self::assertSame([1, 2, 3], $decoded);
    }

    public function testAnEmptyArrayIsDecodedAsAnEmptyList(): void
    {
        self::assertSame([], (new ListCodec(new IntegerCodec()))->decode(self::wire('[]'), Pointer::root()));
    }

    #[DataProvider('valuesThatAreNoJsonArray')]
    public function testAValueThatIsNoJsonArrayIsRefused(mixed $input): void
    {
        $violation = self::violations(new ListCodec(new IntegerCodec()), $input)[0];

        self::assertSame('invalid_type', $violation->code);
        self::assertSame(['expected' => 'array'], $violation->parameters);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function valuesThatAreNoJsonArray(): iterable
    {
        yield 'null' => [null];
        yield 'a string' => ['[]'];
        yield 'an empty object, the wire form of {}' => [new stdClass()];
        yield 'an object' => [(object) ['0' => 1]];
        yield 'an associative array' => [['a' => 1]];
    }

    public function testAnEmptyObjectIsNotTheEmptyToManyRelationship(): void
    {
        $linkage = new ListCodec(
            new ObjectCodec(['type' => new StringCodec(), 'id' => new StringCodec()], ['type', 'id']),
        );

        self::assertSame([], $linkage->decode(self::wire('[]'), Pointer::root()));

        $violation = self::violations($linkage, self::wire('{}'))[0];

        self::assertSame('invalid_type', $violation->code);
        self::assertSame(['expected' => 'array'], $violation->parameters);
    }

    public function testAnArrayWithTooFewItemsIsRefused(): void
    {
        $violation = self::violations(new ListCodec(new IntegerCodec(), minItems: 2), self::wire('[1]'))[0];

        self::assertSame('too_few_items', $violation->code);
        self::assertSame(['minItems' => 2], $violation->parameters);
    }

    public function testAnArrayWithTooManyItemsIsRefused(): void
    {
        $violation = self::violations(new ListCodec(new IntegerCodec(), maxItems: 1), self::wire('[1,2]'))[0];

        self::assertSame('too_many_items', $violation->code);
        self::assertSame(['maxItems' => 1], $violation->parameters);
    }

    public function testTheBoundsAreInclusive(): void
    {
        $codec = new ListCodec(new IntegerCodec(), minItems: 1, maxItems: 1);

        self::assertSame([1], $codec->decode(self::wire('[1]'), Pointer::root()));
    }

    public function testEveryInvalidItemRaisesItsOwnViolationAtItsIndex(): void
    {
        $violations = self::violations(new ListCodec(new IntegerCodec()), self::wire('[1,"x",null]'));

        self::assertSame(['invalid_type', 'invalid_type'], array_column($violations, 'code'));
        self::assertSame(
            ['/1', '/2'],
            array_map(static function (Violation $violation): string {
                return $violation->pointer->toString();
            }, $violations),
        );
    }

    public function testACountViolationAndAnItemViolationAreReportedTogether(): void
    {
        $violations = self::violations(new ListCodec(new IntegerCodec(), maxItems: 1), self::wire('[1,"x"]'));

        self::assertSame(['too_many_items', 'invalid_type'], array_column($violations, 'code'));
    }

    #[DataProvider('refusedConstructions')]
    public function testAnImpossibleDeclarationIsRefused(?int $minItems, ?int $maxItems, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new ListCodec(new IntegerCodec(), $minItems, $maxItems);
    }

    /**
     * @return iterable<string, array{?int, ?int, string}>
     */
    public static function refusedConstructions(): iterable
    {
        yield 'a negative minimum' => [-1, null, 'the minimum number of items must not be negative'];
        yield 'a negative maximum' => [null, -1, 'the maximum number of items must not be negative'];
        yield 'an empty range' => [2, 1, 'the minimum number of items must not be greater than the maximum'];
    }

    public function testTheSchemaCarriesEveryKeywordTheCodecEnforces(): void
    {
        $codec = new ListCodec(new IntegerCodec(minimum: 0, maximum: 9), minItems: 1, maxItems: 10);

        self::assertSame(
            '{"type":"array","items":{"type":"integer","minimum":0,"maximum":9},"minItems":1,"maxItems":10}',
            json_encode($codec->schema()->toObject()),
        );
    }

    public function testEncodeReturnsAList(): void
    {
        self::assertSame([1, 2], (new ListCodec(new IntegerCodec()))->encode([1, 2]));
    }

    public function testEncodeRefusesAValueThatIsNoList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the value must be a list');

        (new ListCodec(new IntegerCodec()))->encode(['a' => 1]);
    }

    public function testEncodeRefusesAnItemTheCodecOfTheItemsRefuses(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the value must be an integer');

        (new ListCodec(new IntegerCodec()))->encode(['x']);
    }

    /**
     * @param list<int>        $value
     * @param non-empty-string $code
     */
    #[DataProvider('listsOutsideTheBounds')]
    public function testEncodeRefusesANumberOfItemsDecodeRefuses(array $value, string $code): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the value is not one decode() returns, which refuses it: ' . $code . ' at ""');

        (new ListCodec(new IntegerCodec(), 1, 2))->encode($value);
    }

    /**
     * @return iterable<string, array{list<int>, non-empty-string}>
     */
    public static function listsOutsideTheBounds(): iterable
    {
        yield 'too few items' => [[], 'too_few_items'];
        yield 'too many items' => [[1, 2, 3], 'too_many_items'];
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
