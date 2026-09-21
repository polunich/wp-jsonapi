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

namespace Polunich\WpJsonApi\Tests\Unit\Metadata;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\Codec;
use Polunich\WpJsonApi\Codec\ConstCodec;
use Polunich\WpJsonApi\Codec\DateTimeCodec;
use Polunich\WpJsonApi\Codec\EnumCodec;
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Codec\ListCodec;
use Polunich\WpJsonApi\Codec\MapCodec;
use Polunich\WpJsonApi\Codec\NullableCodec;
use Polunich\WpJsonApi\Codec\ObjectCodec;
use Polunich\WpJsonApi\Codec\Schema\JsonType;
use Polunich\WpJsonApi\Codec\Schema\Schema;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Metadata\AttributeMetadata;
use Polunich\WpJsonApi\Metadata\WriteRule;
use Polunich\WpJsonApi\Tests\Fixtures\Codec\Priority;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\DescribedCodec;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use ReflectionClass;
use ReflectionParameter;

/**
 * @internal
 */
#[CoversClass(AttributeMetadata::class)]
final class AttributeMetadataTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(AttributeMetadata::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    public function testTheMembersOfTheAttributeAndValueOfKeepTheirNamesAndTypes(): void
    {
        $class = new ReflectionClass(AttributeMetadata::class);

        self::assertSame(
            [
                ['name', 'string'],
                ['codec', Codec::class],
                ['accessor', '?Closure'],
                ['writeRule', WriteRule::class],
                ['sortable', 'bool'],
                ['operators', 'array'],
                ['description', '?string'],
                ['deprecated', 'bool'],
            ],
            array_map(
                static function (ReflectionParameter $parameter): array {
                    return [$parameter->getName(), (string) $parameter->getType()];
                },
                $class->getConstructor()?->getParameters() ?? [],
            ),
        );
        self::assertFalse($class->getProperty('accessor')->isPublic());
        self::assertTrue($class->getProperty('nullable')->isPublic());
        self::assertFalse($class->getProperty('nullable')->isPromoted());
        self::assertSame(
            [['record', 'object']],
            array_map(
                static function (ReflectionParameter $parameter): array {
                    return [$parameter->getName(), (string) $parameter->getType()];
                },
                $class->getMethod('valueOf')->getParameters(),
            ),
        );
        self::assertSame('mixed', (string) $class->getMethod('valueOf')->getReturnType());
    }

    public function testCarriesWhatTheDeclarationStates(): void
    {
        $codec = new StringCodec(maxLength: 200);
        $rule = new WriteRule(true, true, true);
        $attribute = new AttributeMetadata(
            'name',
            $codec,
            static function (Pet $pet): string {
                return $pet->name;
            },
            $rule,
            true,
            ['@eq', '@containsi'],
            'the name of the pet',
            true,
        );

        self::assertSame('name', $attribute->name);
        self::assertSame($codec, $attribute->codec);
        self::assertSame($rule, $attribute->writeRule);
        self::assertTrue($attribute->sortable);
        self::assertSame(['@eq', '@containsi'], $attribute->operators);
        self::assertSame('the name of the pet', $attribute->description);
        self::assertTrue($attribute->deprecated);
        self::assertTrue($attribute->hasAccessor());
        self::assertSame('Rex', $attribute->valueOf(new Pet('1', 'Rex')));
    }

    #[DataProvider('names')]
    public function testAcceptsAMemberNameAsItsName(string $name): void
    {
        self::assertSame($name, self::attribute($name)->name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function names(): iterable
    {
        yield 'a word' => ['name'];
        yield 'camel case' => ['startsAt'];
        yield 'digits, an int key in a map' => ['0'];
        yield 'an inner hyphen and underscore' => ['first-name_2'];
        yield 'a name that contains id' => ['ids'];
        yield 'a name that contains type' => ['Type'];
    }

    #[DataProvider('impossibleNames')]
    public function testRefusesANameNoAttributeCarries(string $name, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::attribute($name);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function impossibleNames(): iterable
    {
        yield 'the empty name' => ['', 'attribute "" is no member name'];
        yield 'a reserved character' => ['first.name', 'attribute "first.name" is no member name'];
        yield 'a hyphen at the end' => ['name-', 'attribute "name-" is no member name'];
        yield 'a control character, escaped' => ["name\n", 'attribute "name\n" is no member name'];
        yield 'an @-member' => ['@context', 'attribute "@context" is an @-member'];
        yield 'type' => [
            'type',
            'attribute "type" shares the namespace of type and id, and no attribute or relationship is named type'
            . ' or id',
        ];
        yield 'id' => [
            'id',
            'attribute "id" shares the namespace of type and id, and no attribute or relationship is named type or id',
        ];
    }

    public function testAnEmptyDescriptionIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('attribute "name" declares an empty description, and a declared text is never'
            . ' empty');

        new AttributeMetadata('name', new StringCodec(), null, new WriteRule(true, true, false), false, [], '', false);
    }

    public function testAnAttributeWithoutAccessorIsWrittenAndNeverRead(): void
    {
        $attribute = new AttributeMetadata(
            'password',
            new StringCodec(),
            null,
            new WriteRule(true, true, false),
            false,
            [],
            null,
            false,
        );

        self::assertFalse($attribute->hasAccessor());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('attribute "password" has no accessor, so no client reads it');

        $attribute->valueOf(new Pet('1', 'Rex'));
    }

    /**
     * the check reads the flags of the rule,
     * so one built equal to `WriteRule::readOnly()` is refused too
     */
    #[DataProvider('readOnlyRules')]
    public function testRefusesAnAttributeWithoutAccessorThatNoClientWrites(WriteRule $rule): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'attribute "secret" has no accessor and a read-only write rule, so it is never read and never written, and'
            . ' the contract would describe a member no request or response carries',
        );

        new AttributeMetadata('secret', new StringCodec(), null, $rule, false, [], null, false);
    }

    /**
     * @return iterable<string, array{WriteRule}>
     */
    public static function readOnlyRules(): iterable
    {
        yield 'WriteRule::readOnly()' => [WriteRule::readOnly()];
        yield 'an equal rule of its own' => [new WriteRule(false, false, false)];
    }

    #[DataProvider('writableRules')]
    public function testAcceptsAnAttributeThatIsReadOrWritten(bool $withAccessor, WriteRule $rule): void
    {
        $attribute = new AttributeMetadata(
            'secret',
            new StringCodec(),
            $withAccessor ? static function (Pet $pet): string {
                return $pet->name;
            } : null,
            $rule,
            false,
            [],
            null,
            false,
        );

        self::assertSame([$withAccessor, $rule], [$attribute->hasAccessor(), $attribute->writeRule]);
    }

    /**
     * @return iterable<string, array{bool, WriteRule}>
     */
    public static function writableRules(): iterable
    {
        yield 'no accessor, creatable' => [false, new WriteRule(true, false, false)];
        yield 'no accessor, updatable' => [false, new WriteRule(false, true, false)];
        yield 'no accessor, required on create' => [false, new WriteRule(true, false, true)];
        yield 'no accessor, creatable and updatable' => [false, new WriteRule(true, true, false)];
        yield 'an accessor, read-only' => [true, WriteRule::readOnly()];
    }

    public function testReturnsWhatTheAccessorReturns(): void
    {
        $attribute = new AttributeMetadata(
            'owner',
            new NullableCodec(new StringCodec()),
            static function (Pet $pet): ?string {
                return $pet->ownerId;
            },
            WriteRule::readOnly(),
            false,
            [],
            null,
            false,
        );

        self::assertNull($attribute->valueOf(new Pet('1', 'Rex')));
        self::assertSame('9', $attribute->valueOf(new Pet('1', 'Rex', '9')));
    }

    public function testRefusesASortableAttributeWithoutAccessor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('attribute "name" is sortable and has no accessor');

        new AttributeMetadata(
            'name',
            new StringCodec(),
            null,
            new WriteRule(true, false, false),
            true,
            [],
            null,
            false,
        );
    }

    /**
     * @param Codec<mixed> $codec
     */
    #[DataProvider('nonScalarCodecs')]
    public function testRefusesASortableAttributeWhoseCodecAdmitsNoScalar(Codec $codec): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('attribute "tags" is sortable and its codec admits no scalar type');

        self::attribute('tags', $codec, true);
    }

    /**
     * @return iterable<string, array{Codec<mixed>}>
     */
    public static function nonScalarCodecs(): iterable
    {
        yield 'a list' => [new ListCodec(new StringCodec())];
        yield 'an object' => [new ObjectCodec(['name' => new StringCodec()])];
        yield 'a map' => [new MapCodec(new IntegerCodec())];
    }

    /**
     * a nullable codec is sortable too:
     * only a cursor and a declared default sort refuse a nullable path
     *
     * @param Codec<mixed> $codec
     */
    #[DataProvider('scalarCodecs')]
    public function testAcceptsASortableAttributeWhoseCodecAdmitsAScalar(Codec $codec): void
    {
        self::assertTrue(self::attribute('rank', $codec, true)->sortable);
    }

    /**
     * @return iterable<string, array{Codec<mixed>}>
     */
    public static function scalarCodecs(): iterable
    {
        yield 'a string' => [new StringCodec()];
        yield 'a date-time' => [new DateTimeCodec()];
        yield 'an enum' => [new EnumCodec(Priority::class)];
        yield 'a nullable integer' => [new NullableCodec(new IntegerCodec())];
        yield 'a schema without a type' => [new DescribedCodec(new Schema())];
    }

    /**
     * @param list<string> $operators
     */
    #[DataProvider('repeatedOperators')]
    public function testRefusesAnOperatorDeclaredTwice(array $operators, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::attribute('name', operators: $operators);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function repeatedOperators(): iterable
    {
        yield 'a built-in operator' => [['@eq', '@ne', '@eq'], 'attribute "name" declares operator "@eq" twice'];
        yield 'a control character, escaped' => [
            ["@eq\n", "@eq\n"],
            'attribute "name" declares operator "@eq\n" twice',
        ];
    }

    /**
     * @param Codec<mixed> $codec
     */
    #[DataProvider('nullability')]
    public function testDerivesWhetherTheAttributeMayBeNull(Codec $codec, bool $nullable): void
    {
        self::assertSame($nullable, self::attribute('value', $codec)->nullable);
    }

    /**
     * @return iterable<string, array{Codec<mixed>, bool}>
     */
    public static function nullability(): iterable
    {
        yield 'a string' => [new StringCodec(), false];
        yield 'a nullable string' => [new NullableCodec(new StringCodec()), true];
        yield 'an integer' => [new IntegerCodec(), false];
        yield 'an object' => [new ObjectCodec([]), false];
        yield 'a nullable object' => [new NullableCodec(new ObjectCodec([])), true];
        yield 'the constant null' => [new ConstCodec(null), true];
        yield 'a string constant' => [new ConstCodec('draft'), false];
        yield 'a nullable string constant, an anyOf' => [new NullableCodec(new ConstCodec('draft')), true];
        yield 'a schema that names no type' => [new DescribedCodec(new Schema()), true];
        yield 'an anyOf of a string and an integer' => [
            new DescribedCodec(new Schema(anyOf: [
                new Schema(type: JsonType::String),
                new Schema(type: JsonType::Integer),
            ])),
            false,
        ];
        yield 'a oneOf of a string and an integer' => [
            new DescribedCodec(new Schema(oneOf: [
                new Schema(type: JsonType::String),
                new Schema(type: JsonType::Integer),
            ])),
            false,
        ];
        yield 'a oneOf with a null branch' => [
            new DescribedCodec(new Schema(oneOf: [
                new Schema(type: JsonType::String),
                new Schema(type: JsonType::Null),
            ])),
            true,
        ];
        yield 'an enum without null' => [new DescribedCodec(new Schema(enum: ['a', 'b'])), false];
        yield 'an enum with null' => [new DescribedCodec(new Schema(enum: ['a', null])), true];
    }

    /**
     * @param ?Codec<mixed> $codec
     * @param list<string>  $operators
     */
    private static function attribute(
        string $name,
        ?Codec $codec = null,
        bool $sortable = false,
        array $operators = [],
    ): AttributeMetadata {
        return new AttributeMetadata(
            $name,
            $codec ?? new StringCodec(),
            static function (Pet $pet): string {
                return $pet->name;
            },
            WriteRule::readOnly(),
            $sortable,
            $operators,
            null,
            false,
        );
    }
}
