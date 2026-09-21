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

namespace Polunich\WpJsonApi\Tests\Unit\Codec\Schema;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\Schema\Format;
use Polunich\WpJsonApi\Codec\Schema\JsonType;
use Polunich\WpJsonApi\Codec\Schema\Schema;
use stdClass;

/**
 * @internal
 */
#[CoversClass(Schema::class)]
final class SchemaTest extends TestCase
{
    public function testTheKeywordsAreWrittenInOneFixedOrder(): void
    {
        $schema = new Schema(
            writeOnly: true,
            readOnly: false,
            deprecated: true,
            default: 'a',
            examples: ['a'],
            description: 'a member',
            maxLength: 10,
            minLength: 1,
            format: Format::Date,
            enum: ['a'],
            type: JsonType::String,
            pattern: '^a$',
        );

        self::assertSame(
            [
                'type',
                'enum',
                'format',
                'minLength',
                'maxLength',
                'pattern',
                'description',
                'examples',
                'default',
                'deprecated',
                'readOnly',
                'writeOnly',
            ],
            array_keys(get_object_vars($schema->toObject())),
        );
    }

    public function testAnAbsentKeywordIsNotWritten(): void
    {
        self::assertSame([], get_object_vars((new Schema())->toObject()));
    }

    public function testASchemaWithoutKeywordsIsWrittenAsAnEmptyJsonObject(): void
    {
        self::assertSame('{}', json_encode((new Schema())->toObject()));
    }

    #[DataProvider('schemasThatNestAnEmptySchema')]
    public function testANestedSchemaWithoutKeywordsIsWrittenAsAnEmptyJsonObject(
        Schema $schema,
        string $expected,
    ): void {
        self::assertSame($expected, json_encode($schema->toObject()));
    }

    /**
     * @return iterable<string, array{Schema, string}>
     */
    public static function schemasThatNestAnEmptySchema(): iterable
    {
        yield 'items' => [
            new Schema(type: JsonType::Array, items: new Schema()),
            '{"type":"array","items":{}}',
        ];
        yield 'additionalProperties' => [
            new Schema(type: JsonType::Object, additionalProperties: new Schema()),
            '{"type":"object","additionalProperties":{}}',
        ];
        yield 'properties' => [
            new Schema(type: JsonType::Object, properties: ['a' => new Schema()]),
            '{"type":"object","properties":{"a":{}}}',
        ];
        yield 'oneOf' => [
            new Schema(oneOf: [new Schema(), new Schema(type: JsonType::Null)]),
            '{"oneOf":[{},{"type":"null"}]}',
        ];
        yield 'allOf' => [new Schema(allOf: [new Schema()]), '{"allOf":[{}]}'];
        yield 'anyOf' => [new Schema(anyOf: [new Schema()]), '{"anyOf":[{}]}'];
        yield 'if, then and else' => [
            new Schema(if: new Schema(), then: new Schema(), else: new Schema()),
            '{"if":{},"then":{},"else":{}}',
        ];
    }

    #[DataProvider('emptyKeywords')]
    public function testAnEmptyListKeywordIsNotWritten(Schema $schema): void
    {
        self::assertSame(['type' => 'object'], get_object_vars($schema->toObject()));
    }

    /**
     * @return iterable<string, array{Schema}>
     */
    public static function emptyKeywords(): iterable
    {
        yield 'enum' => [new Schema(type: JsonType::Object, enum: [])];
        yield 'properties' => [new Schema(type: JsonType::Object, properties: [])];
        yield 'required' => [new Schema(type: JsonType::Object, required: [])];
        yield 'oneOf' => [new Schema(type: JsonType::Object, oneOf: [])];
        yield 'allOf' => [new Schema(type: JsonType::Object, allOf: [])];
        yield 'anyOf' => [new Schema(type: JsonType::Object, anyOf: [])];
        yield 'examples' => [new Schema(type: JsonType::Object, examples: [])];
    }

    /**
     * every name is digits on purpose: one name that is no number keeps the array out of list form,
     * and `json_encode()` would write an object anyway
     */
    public function testAPropertyNameOfDigitsIsWrittenAsAMemberName(): void
    {
        $schema = new Schema(
            type: JsonType::Object,
            properties: ['0' => new Schema(type: JsonType::String), '1' => new Schema(type: JsonType::Integer)],
            required: ['0'],
        );

        self::assertSame(
            '{"type":"object","properties":{"0":{"type":"string"},"1":{"type":"integer"}},"required":["0"]}',
            json_encode($schema->toObject()),
        );
    }

    public function testThePropertiesKeywordIsWrittenAsAJsonObject(): void
    {
        $keywords = get_object_vars((new Schema(properties: ['a' => new Schema(type: JsonType::String)]))->toObject());

        self::assertInstanceOf(stdClass::class, $keywords['properties'] ?? null);
    }

    /**
     * `false` is falsy but present, and must not be dropped like null
     */
    public function testAdditionalPropertiesIsWrittenAsFalseAndAsASchema(): void
    {
        self::assertSame(
            '{"additionalProperties":false}',
            json_encode((new Schema(additionalProperties: false))->toObject()),
        );
        self::assertSame(
            '{"additionalProperties":{"type":"string"}}',
            json_encode((new Schema(additionalProperties: new Schema(type: JsonType::String)))->toObject()),
        );
    }

    public function testASubschemaIsWrittenAsAJsonObject(): void
    {
        $schema = new Schema(
            type: JsonType::Array,
            items: new Schema(type: JsonType::String),
            oneOf: [new Schema(type: JsonType::String), new Schema(type: JsonType::Integer)],
            if: new Schema(type: JsonType::String),
            then: new Schema(type: JsonType::Integer),
            else: new Schema(type: JsonType::Number),
        );

        self::assertSame(
            '{"type":"array","items":{"type":"string"},'
            . '"oneOf":[{"type":"string"},{"type":"integer"}],'
            . '"if":{"type":"string"},"then":{"type":"integer"},"else":{"type":"number"}}',
            json_encode($schema->toObject()),
        );
    }

    public function testATypeListIsWrittenAsAListOfNames(): void
    {
        $schema = new Schema(type: [JsonType::String, JsonType::Null]);

        self::assertSame(['type' => ['string', 'null']], get_object_vars($schema->toObject()));
    }

    public function testATypeListMustNameAtLeastOneType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a type list must name at least one type');

        new Schema(type: []);
    }

    public function testOrNullAddsTheNullTypeAndKeepsEveryOtherKeyword(): void
    {
        $schema = (new Schema(type: JsonType::String, maxLength: 10, description: 'a member'))->orNull();

        self::assertSame(
            ['type' => ['string', 'null'], 'maxLength' => 10, 'description' => 'a member'],
            get_object_vars($schema->toObject()),
        );
    }

    public function testOrNullAddsNullToTheEnumAsWell(): void
    {
        $schema = (new Schema(type: JsonType::String, enum: ['a', 'b']))->orNull();

        self::assertSame(
            ['type' => ['string', 'null'], 'enum' => ['a', 'b', null]],
            get_object_vars($schema->toObject()),
        );
    }

    public function testOrNullLeavesASchemaThatAlreadyAllowsNullAsItIs(): void
    {
        $schema = new Schema(type: [JsonType::String, JsonType::Null]);

        self::assertSame($schema, $schema->orNull());
    }

    #[DataProvider('schemasThatNeedAnyOf')]
    public function testOrNullFallsBackToAnyOf(Schema $schema, string $expected): void
    {
        self::assertSame($expected, json_encode($schema->orNull()->toObject()));
    }

    /**
     * @return iterable<string, array{Schema, string}>
     */
    public static function schemasThatNeedAnyOf(): iterable
    {
        yield 'a schema without a type' => [
            new Schema(description: 'anything'),
            '{"anyOf":[{"description":"anything"},{"type":"null"}]}',
        ];
        yield 'a constant' => [
            new Schema(type: JsonType::String, const: 'pets'),
            '{"anyOf":[{"type":"string","const":"pets"},{"type":"null"}]}',
        ];
    }

    public function testWithAnnotationsSetsOnlyTheAnnotationsItIsGiven(): void
    {
        $schema = (new Schema(type: JsonType::String, description: 'the old one'))
            ->withAnnotations(examples: ['a'], deprecated: true);

        self::assertSame(
            ['type' => 'string', 'description' => 'the old one', 'examples' => ['a'], 'deprecated' => true],
            get_object_vars($schema->toObject()),
        );
    }

    public function testWithAnnotationsKeepsEveryValidationKeyword(): void
    {
        $schema = (new Schema(type: JsonType::String, minLength: 1, pattern: '^a$'))
            ->withAnnotations(description: 'a member');

        self::assertSame(
            ['type' => 'string', 'minLength' => 1, 'pattern' => '^a$', 'description' => 'a member'],
            get_object_vars($schema->toObject()),
        );
    }

    public function testTheSameSchemaIsWrittenTheSameWayTwice(): void
    {
        $build = static function (): Schema {
            return new Schema(
                type: JsonType::Object,
                properties: ['b' => new Schema(type: JsonType::String), 'a' => new Schema(type: JsonType::Integer)],
                required: ['b'],
                additionalProperties: false,
            );
        };

        self::assertSame(json_encode($build()->toObject()), json_encode($build()->toObject()));
        self::assertSame(
            '{"type":"object","properties":{"b":{"type":"string"},"a":{"type":"integer"}},'
            . '"required":["b"],"additionalProperties":false}',
            json_encode($build()->toObject()),
        );
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('scalarTypesOfASchema')]
    public function testScalarTypesReturnsTheScalarTypesTheSchemaAdmits(Schema $schema, array $expected): void
    {
        self::assertSame(
            $expected,
            array_map(static function (JsonType $type): string {
                return $type->value;
            }, $schema->scalarTypes()),
        );
    }

    /**
     * @return iterable<string, array{Schema, list<string>}>
     */
    public static function scalarTypesOfASchema(): iterable
    {
        yield 'a type' => [new Schema(type: JsonType::String), ['string']];
        yield 'a type list, in the fixed order' => [
            new Schema(type: [JsonType::String, JsonType::Integer, JsonType::Null]),
            ['null', 'integer', 'string'],
        ];
        yield 'a type list with an object in it' => [
            new Schema(type: [JsonType::Object, JsonType::String]),
            ['string'],
        ];
        yield 'an object' => [new Schema(type: JsonType::Object), []];
        yield 'an array' => [new Schema(type: JsonType::Array), []];
        yield 'a const without a type' => [new Schema(const: 12), ['integer']];
        yield 'a const with a type' => [new Schema(type: JsonType::String, const: 'a'), ['string']];
        yield 'an enum without a type' => [
            new Schema(enum: ['a', 1, true, 1.5, null]),
            ['null', 'boolean', 'integer', 'number', 'string'],
        ];
        yield 'an enum of one type, named twice' => [new Schema(enum: ['a', 'b']), ['string']];
        yield 'an enum that holds an object' => [new Schema(enum: [new stdClass(), 'a']), ['string']];
        yield 'oneOf' => [
            new Schema(oneOf: [new Schema(type: JsonType::Integer), new Schema(type: JsonType::Boolean)]),
            ['boolean', 'integer'],
        ];
        yield 'oneOf of objects, the shape OneOfCodec publishes' => [
            new Schema(oneOf: [new Schema(type: JsonType::Object), new Schema(type: JsonType::Object)]),
            [],
        ];
        yield 'anyOf nested in anyOf' => [
            new Schema(anyOf: [
                new Schema(anyOf: [new Schema(type: JsonType::Number)]),
                new Schema(type: JsonType::Null),
            ]),
            ['null', 'number'],
        ];
        yield 'a schema that names nothing' => [
            new Schema(),
            ['null', 'boolean', 'integer', 'number', 'string'],
        ];
        yield 'an annotation alone' => [
            new Schema(description: 'anything'),
            ['null', 'boolean', 'integer', 'number', 'string'],
        ];
        yield 'a keyword scalarTypes() does not read' => [
            new Schema(allOf: [new Schema(type: JsonType::String)]),
            ['null', 'boolean', 'integer', 'number', 'string'],
        ];
    }

    /**
     * pinned with the schemas `orNull()` actually builds
     *
     * @param list<string> $expected
     */
    #[DataProvider('scalarTypesOfASchemaWithoutAType')]
    public function testScalarTypesResolvesASchemaThatNamesNoTypeOfItsOwn(Schema $schema, array $expected): void
    {
        self::assertSame(
            $expected,
            array_map(static function (JsonType $type): string {
                return $type->value;
            }, $schema->scalarTypes()),
        );
    }

    /**
     * @return iterable<string, array{Schema, list<string>}>
     */
    public static function scalarTypesOfASchemaWithoutAType(): iterable
    {
        yield 'the anyOf orNull() builds for a constant' => [
            (new Schema(type: JsonType::String, const: 'pets'))->orNull(),
            ['null', 'string'],
        ];
        yield 'the anyOf orNull() builds for a schema without a type' => [
            (new Schema())->orNull(),
            ['null', 'boolean', 'integer', 'number', 'string'],
        ];
        yield 'a type list with null' => [
            (new Schema(type: JsonType::Integer))->orNull(),
            ['null', 'integer'],
        ];
    }

    public function testForRequestLeavesOutTheReadOnlyMembersAndForResponseTheWriteOnlyOnes(): void
    {
        $schema = self::directed(new Schema(type: JsonType::String));

        self::assertSame(
            '{"type":"object","properties":{"secret":{"type":"string","writeOnly":true},"day":{"type":"string"}},'
            . '"required":["secret","day"],"additionalProperties":false,"description":"a schedule"}',
            json_encode($schema->forRequest()->toObject()),
        );
        self::assertSame(
            '{"type":"object","properties":{"version":{"type":"integer","readOnly":true},"day":{"type":"string"}},'
            . '"required":["version","day"],"additionalProperties":false,"description":"a schedule"}',
            json_encode($schema->forResponse()->toObject()),
        );
    }

    /**
     * the members of one direction are left out wherever an object may stand
     */
    #[DataProvider('placesOfAnObject')]
    public function testTheViewsReachEverySubschema(Schema $schema, string $request, string $response): void
    {
        self::assertSame($request, json_encode($schema->forRequest()->toObject()));
        self::assertSame($response, json_encode($schema->forResponse()->toObject()));
    }

    /**
     * @return iterable<string, array{Schema, string, string}>
     */
    public static function placesOfAnObject(): iterable
    {
        $object = self::directed(new Schema(type: JsonType::String));
        $request = '{"type":"object","properties":{"secret":{"type":"string","writeOnly":true},'
            . '"day":{"type":"string"}},"required":["secret","day"],"additionalProperties":false,'
            . '"description":"a schedule"}';
        $response = '{"type":"object","properties":{"version":{"type":"integer","readOnly":true},'
            . '"day":{"type":"string"}},"required":["version","day"],"additionalProperties":false,'
            . '"description":"a schedule"}';

        yield 'a property' => [
            new Schema(type: JsonType::Object, properties: ['schedule' => $object]),
            '{"type":"object","properties":{"schedule":' . $request . '}}',
            '{"type":"object","properties":{"schedule":' . $response . '}}',
        ];
        yield 'the items' => [
            new Schema(type: JsonType::Array, items: $object),
            '{"type":"array","items":' . $request . '}',
            '{"type":"array","items":' . $response . '}',
        ];
        yield 'additional properties' => [
            new Schema(type: JsonType::Object, additionalProperties: $object),
            '{"type":"object","additionalProperties":' . $request . '}',
            '{"type":"object","additionalProperties":' . $response . '}',
        ];
        yield 'a branch of oneOf' => [
            new Schema(oneOf: [$object]),
            '{"oneOf":[' . $request . ']}',
            '{"oneOf":[' . $response . ']}',
        ];
        yield 'a branch of allOf' => [
            new Schema(allOf: [$object]),
            '{"allOf":[' . $request . ']}',
            '{"allOf":[' . $response . ']}',
        ];
        yield 'a branch of anyOf' => [
            new Schema(anyOf: [$object, new Schema(type: JsonType::Null)]),
            '{"anyOf":[' . $request . ',{"type":"null"}]}',
            '{"anyOf":[' . $response . ',{"type":"null"}]}',
        ];
        yield 'if, then and else' => [
            new Schema(if: $object, then: $object, else: $object),
            '{"if":' . $request . ',"then":' . $request . ',"else":' . $request . '}',
            '{"if":' . $response . ',"then":' . $response . ',"else":' . $response . '}',
        ];
        yield 'a member of a member' => [
            self::directed($object),
            '{"type":"object","properties":{"secret":{"type":"string","writeOnly":true},'
            . '"day":' . $request . '},"required":["secret","day"],"additionalProperties":false,'
            . '"description":"a schedule"}',
            '{"type":"object","properties":{"version":{"type":"integer","readOnly":true},'
            . '"day":' . $response . '},"required":["version","day"],"additionalProperties":false,'
            . '"description":"a schedule"}',
        ];
    }

    public function testASchemaWithoutMembersOfOneDirectionIsTheSameInBothViews(): void
    {
        $schema = new Schema(
            type: JsonType::Object,
            properties: ['day' => new Schema(type: JsonType::String, minLength: 1)],
            required: ['day'],
            additionalProperties: false,
            description: 'a schedule',
            examples: [['day' => 'mon']],
        );

        self::assertSame(json_encode($schema->toObject()), json_encode($schema->forRequest()->toObject()));
        self::assertSame(json_encode($schema->toObject()), json_encode($schema->forResponse()->toObject()));
    }

    /**
     * an object with a read-only `version`, a write-only `secret` and `day` of either direction,
     * all three required
     */
    private static function directed(Schema $day): Schema
    {
        return new Schema(
            type: JsonType::Object,
            properties: [
                'version' => new Schema(type: JsonType::Integer, readOnly: true),
                'secret' => new Schema(type: JsonType::String, writeOnly: true),
                'day' => $day,
            ],
            required: ['version', 'secret', 'day'],
            additionalProperties: false,
            description: 'a schedule',
        );
    }
}
