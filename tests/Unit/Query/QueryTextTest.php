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

namespace Polunich\WpJsonApi\Tests\Unit\Query;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\BooleanCodec;
use Polunich\WpJsonApi\Codec\ConstCodec;
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Codec\ListCodec;
use Polunich\WpJsonApi\Codec\MapCodec;
use Polunich\WpJsonApi\Codec\NullableCodec;
use Polunich\WpJsonApi\Codec\ObjectCodec;
use Polunich\WpJsonApi\Codec\OneOfCodec;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Codec\Schema\JsonType;
use Polunich\WpJsonApi\Codec\Schema\Schema;
use Polunich\WpJsonApi\Query\QueryText;
use ReflectionClass;
use stdClass;

/**
 * @internal
 */
#[CoversClass(QueryText::class)]
final class QueryTextTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(QueryText::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    /**
     * @param list<JsonType> $types
     */
    #[DataProvider('texts')]
    public function testTextBecomesTheFirstScalarTypeTheSchemaAdmitsAndTheTextSpells(
        array $types,
        string $text,
        mixed $wire,
    ): void {
        self::assertSame($wire, QueryText::toWire($text, new Schema(type: $types)));
    }

    /**
     * @return iterable<string, array{list<JsonType>, string, mixed}>
     */
    public static function texts(): iterable
    {
        yield 'an integer' => [[JsonType::Integer], '18', 18];
        yield 'a number written with an exponent' => [[JsonType::Number], '2e2', 200.0];
        yield 'true' => [[JsonType::Boolean], 'true', true];
        yield 'null where null is admitted' => [[JsonType::String, JsonType::Null], 'null', null];
        yield 'null where a string alone is admitted' => [[JsonType::String], 'null', 'null'];
        yield 'digits where a string alone is admitted' => [[JsonType::String], '18', '18'];
        yield 'a boolean in capitals stays text' => [[JsonType::Boolean], 'TRUE', 'TRUE'];
        yield 'a number that is not finite stays text' => [[JsonType::Number], '1e999', '1e999'];
        yield 'digits with a trailing line feed stay text' => [[JsonType::Integer], "18\n", "18\n"];
    }

    public function testAnArrayBecomesAListOfConvertedItemsWhereTheSchemaNamesItems(): void
    {
        $schema = (new ListCodec(new IntegerCodec()))->schema();

        self::assertSame([0 => 1, 1 => 2], QueryText::toWire(['1', '2'], $schema));
        self::assertSame([1 => 1, 3 => 2], QueryText::toWire([1 => '1', 3 => '2'], $schema));
    }

    public function testAnArrayBecomesAnObjectOfConvertedMembersWhereTheSchemaNamesProperties(): void
    {
        $codec = new ObjectCodec(['min' => new IntegerCodec(), 'max' => new IntegerCodec()], ['min']);

        $wire = QueryText::toWire(['min' => '10', 'max' => '50'], $codec->schema());

        self::assertEquals((object) ['min' => 10, 'max' => 50], $wire);
        self::assertSame(['min' => 10, 'max' => 50], $codec->decode($wire, Pointer::root()));
    }

    public function testAMemberNoPropertyNamesStaysAsItIs(): void
    {
        $schema = new Schema(type: JsonType::Object, properties: ['min' => new Schema(type: JsonType::Integer)]);

        self::assertEquals(
            (object) ['min' => 10, 'other' => '5'],
            QueryText::toWire(['min' => '10', 'other' => '5'], $schema),
        );
    }

    public function testEveryMemberIsReadByTheSchemaOfAdditionalProperties(): void
    {
        $schema = (new MapCodec(new IntegerCodec()))->schema();

        self::assertEquals((object) ['a' => 1, '7' => 2], QueryText::toWire(['a' => '1', 7 => '2'], $schema));
    }

    public function testABranchDescribesTheShapeWhereTheSchemaItselfDoesNot(): void
    {
        $list = new Schema(anyOf: [new Schema(type: JsonType::Null), (new ListCodec(new IntegerCodec()))->schema()]);
        $variants = new Schema(oneOf: [
            new Schema(type: JsonType::String),
            (new MapCodec(new IntegerCodec()))->schema(),
        ]);

        self::assertSame([1, 2], QueryText::toWire(['1', '2'], $list));
        self::assertEquals((object) ['a' => 1], QueryText::toWire(['a' => '1'], $variants));
    }

    /**
     * `NullableCodec` wraps the `oneOf` of a union in `anyOf` with the null type
     */
    public function testTheBranchesOfABranchAreReadToo(): void
    {
        $codec = new NullableCodec(self::pets());

        $wire = QueryText::toWire(['kind' => 'dog', 'goodBoy' => 'true'], $codec->schema());

        self::assertEquals((object) ['kind' => 'dog', 'goodBoy' => true], $wire);
        self::assertSame(['kind' => 'dog', 'goodBoy' => true], $codec->decode($wire, Pointer::root()));
    }

    /**
     * the discriminator the text spells picks the variant, as it picks the one that decodes
     *
     * @param array<string, string> $query
     * @param array<string, mixed>  $decoded
     */
    #[DataProvider('variants')]
    public function testTheVariantWhoseConstantsTheTextSpellsReadsTheObject(array $query, array $decoded): void
    {
        $codec = self::pets();

        self::assertSame($decoded, $codec->decode(QueryText::toWire($query, $codec->schema()), Pointer::root()));
    }

    /**
     * @return iterable<string, array{array<string, string>, array<string, mixed>}>
     */
    public static function variants(): iterable
    {
        yield 'the first variant' => [['kind' => 'cat', 'lives' => '9'], ['kind' => 'cat', 'lives' => 9]];
        yield 'a later variant' => [['kind' => 'dog', 'goodBoy' => 'false'], ['kind' => 'dog', 'goodBoy' => false]];
    }

    /**
     * a discriminator the text does not spell leaves the first variant, and the codec refuses it
     */
    public function testWithoutAVariantTheTextSpellsTheFirstReadsTheObject(): void
    {
        $wire = QueryText::toWire(['kind' => 'fish', 'lives' => '9'], self::pets()->schema());

        self::assertEquals((object) ['kind' => 'fish', 'lives' => 9], $wire);
    }

    /**
     * an alternative that names items and one that describes an object:
     * brackets without names are a list
     */
    public function testAListOrAnObjectIsReadByHowTheClientWroteIt(): void
    {
        $schema = new Schema(anyOf: [
            (new ListCodec(new IntegerCodec()))->schema(),
            (new MapCodec(new IntegerCodec()))->schema(),
        ]);

        self::assertSame([1, 2], QueryText::toWire(['1', '2'], $schema));
        self::assertEquals((object) ['a' => 1], QueryText::toWire(['a' => '1'], $schema));
    }

    #[DataProvider('shapes')]
    public function testTheShapeIsTheFormTheConversionReads(Schema $schema, string $shape): void
    {
        self::assertSame($shape, QueryText::shape($schema));
    }

    /**
     * @return iterable<string, array{Schema, string}>
     */
    public static function shapes(): iterable
    {
        $integers = (new ListCodec(new IntegerCodec()))->schema();

        yield 'text' => [(new IntegerCodec())->schema(), QueryText::SCALAR];
        yield 'a union of text' => [
            new Schema(anyOf: [new Schema(type: JsonType::String), new Schema(type: JsonType::Null)]),
            QueryText::SCALAR,
        ];
        yield 'an object of text' => [(new ObjectCodec(['min' => new IntegerCodec()]))->schema(), QueryText::OBJECT];
        yield 'a map of text' => [(new MapCodec(new IntegerCodec()))->schema(), QueryText::OBJECT];
        yield 'a union of objects of text' => [self::pets()->schema(), QueryText::OBJECT];
        yield 'a nullable union of objects of text' => [(new NullableCodec(self::pets()))->schema(), QueryText::OBJECT];
        yield 'a list' => [$integers, QueryText::NESTED];
        yield 'a nullable list' => [$integers->orNull(), QueryText::NESTED];
        yield 'an object with a list' => [
            (new ObjectCodec(['ids' => new ListCodec(new IntegerCodec())]))->schema(),
            QueryText::NESTED,
        ];
        yield 'a map of objects' => [
            (new MapCodec(new ObjectCodec(['min' => new IntegerCodec()])))->schema(),
            QueryText::NESTED,
        ];
    }

    public function testAnArrayNoSchemaShapeDescribesReachesTheCodecAsItIs(): void
    {
        self::assertSame(['1'], QueryText::toWire(['1'], new Schema(type: JsonType::Integer)));
    }

    public function testAValueThatIsNoTextAndNoArrayStaysAsItIs(): void
    {
        $object = new stdClass();

        self::assertSame($object, QueryText::toWire($object, new Schema(type: JsonType::Object)));
    }

    /**
     * the discriminator `kind` picks the variant; each names a member the other lacks
     *
     * @return OneOfCodec<array<array-key, mixed>>
     */
    private static function pets(): OneOfCodec
    {
        return new OneOfCodec(
            'kind',
            [
                'cat' => new ObjectCodec(['kind' => new ConstCodec('cat'), 'lives' => new IntegerCodec()], ['kind']),
                'dog' => new ObjectCodec(['kind' => new ConstCodec('dog'), 'goodBoy' => new BooleanCodec()], ['kind']),
            ],
            static function (array $pet): string {
                return is_string($pet['kind'] ?? null) ? $pet['kind'] : '';
            },
        );
    }
}
