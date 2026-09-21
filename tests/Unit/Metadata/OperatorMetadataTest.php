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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\BooleanCodec;
use Polunich\WpJsonApi\Codec\Codec;
use Polunich\WpJsonApi\Codec\DecodeException;
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Codec\ListCodec;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Metadata\OperatorMetadata;
use Polunich\WpJsonApi\Query\Filter\Operators;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * @internal
 */
#[CoversClass(OperatorMetadata::class)]
final class OperatorMetadataTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(OperatorMetadata::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertStringContainsString('@internal', (string) $class->getDocComment());
    }

    public function testBuiltInCustomIsCustomAndValueCodecKeepTheirSignatures(): void
    {
        $class = new ReflectionClass(OperatorMetadata::class);
        $signature = static function (string $method) use ($class): array {
            return array_map(
                static function (ReflectionParameter $parameter): array {
                    return [$parameter->getName(), (string) $parameter->getType()];
                },
                $class->getMethod($method)->getParameters(),
            );
        };
        $methods = array_map(
            static function (ReflectionMethod $method): string {
                return $method->getName();
            },
            $class->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        sort($methods);

        self::assertTrue($class->getConstructor()?->isPrivate());
        self::assertSame(['builtIn', 'custom', 'isCustom', 'valueCodec'], $methods);
        self::assertSame([['name', 'string']], $signature('builtIn'));
        self::assertSame([['name', 'string'], ['codec', Codec::class]], $signature('custom'));
        self::assertSame([['field', Codec::class]], $signature('valueCodec'));
        self::assertSame(Codec::class, (string) $class->getMethod('valueCodec')->getReturnType());
        self::assertTrue($class->getProperty('name')->isPublic());
        self::assertFalse($class->getProperty('codec')->isPublic());
    }

    #[DataProvider('builtInNames')]
    public function testEveryBuiltInOperatorIsBuiltByName(string $name): void
    {
        $operator = OperatorMetadata::builtIn($name);

        self::assertSame($name, $operator->name);
        self::assertFalse($operator->isCustom());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function builtInNames(): iterable
    {
        foreach (Operators::names() as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('notBuiltIn')]
    public function testRefusesANameNoBuiltInOperatorHas(string $name, string $written): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('no built-in operator is named "%s"', $written));

        OperatorMetadata::builtIn($name);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function notBuiltIn(): iterable
    {
        yield 'a custom name' => ['@near', '@near'];
        yield 'a built-in name in another case' => ['@EQ', '@EQ'];
        yield 'a logical operator, which is a node of the tree' => ['@and', '@and'];
        yield 'a name without @' => ['eq', 'eq'];
        yield 'a control character, escaped' => ["@eq\n", '@eq\n'];
    }

    public function testACustomOperatorCarriesItsNameAndIsCustom(): void
    {
        $operator = OperatorMetadata::custom('@near', new StringCodec());

        self::assertSame('@near', $operator->name);
        self::assertTrue($operator->isCustom());
    }

    #[DataProvider('impossibleCustomNames')]
    public function testRefusesAnImpossibleCustomName(string $name, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        OperatorMetadata::custom($name, new StringCodec());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function impossibleCustomNames(): iterable
    {
        yield 'a name without @' => ['near', 'custom operator "near" is no "@" followed by a member name'];
        yield '@ alone' => ['@', 'custom operator "@" is no "@" followed by a member name'];
        yield 'a reserved character' => ['@near.by', 'custom operator "@near.by" is no "@" followed by a member name'];
        yield 'a control character, escaped' => [
            "@near\t",
            'custom operator "@near\t" is no "@" followed by a member name',
        ];
        yield 'a built-in name' => ['@eq', 'custom operator "@eq" collides with the built-in operator of that name'];
        yield 'the last built-in name' => [
            '@endsWithi',
            'custom operator "@endsWithi" collides with the built-in operator',
        ];
    }

    #[DataProvider('scalarNames')]
    public function testAScalarOperatorReadsItsValueWithTheCodecOfTheField(string $name): void
    {
        $field = new IntegerCodec(minimum: 0);

        self::assertSame($field, OperatorMetadata::builtIn($name)->valueCodec($field));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function scalarNames(): iterable
    {
        foreach (['@eq', '@ne', '@lt', '@lte', '@gt', '@gte'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('textNames')]
    public function testATextOperatorReadsItsValueAsAnyString(string $name): void
    {
        $codec = OperatorMetadata::builtIn($name)->valueCodec(new StringCodec(pattern: '^[A-Z]{3}$'));

        self::assertInstanceOf(StringCodec::class, $codec);
        self::assertSame('ky', $codec->decode('ky', Pointer::root()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function textNames(): iterable
    {
        foreach ([
            '@eqi',
            '@nei',
            '@contains',
            '@notContains',
            '@containsi',
            '@notContainsi',
            '@startsWith',
            '@startsWithi',
            '@endsWith',
            '@endsWithi',
        ] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('listNames')]
    public function testAListOperatorReadsAListOfValuesOfTheField(string $name): void
    {
        $codec = OperatorMetadata::builtIn($name)->valueCodec(new IntegerCodec());

        self::assertInstanceOf(ListCodec::class, $codec);
        self::assertSame([], $codec->decode([], Pointer::root()));
        self::assertSame([1, 2, 3], $codec->decode([1, 2, 3], Pointer::root()));
        self::assertEquals((new ListCodec(new IntegerCodec()))->schema(), $codec->schema());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function listNames(): iterable
    {
        yield '@in' => ['@in'];
        yield '@notIn' => ['@notIn'];
    }

    #[DataProvider('booleanNames')]
    public function testABooleanOperatorReadsABoolean(string $name): void
    {
        self::assertInstanceOf(BooleanCodec::class, OperatorMetadata::builtIn($name)->valueCodec(new IntegerCodec()));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function booleanNames(): iterable
    {
        yield '@null' => ['@null'];
        yield '@notNull' => ['@notNull'];
    }

    public function testThePairOperatorReadsExactlyTwoValuesOfTheField(): void
    {
        $codec = OperatorMetadata::builtIn('@between')->valueCodec(new IntegerCodec());

        self::assertSame([1, 2], $codec->decode([1, 2], Pointer::root()));
        self::assertSame([2, 1], $codec->decode([2, 1], Pointer::root()));
        self::assertEquals((new ListCodec(new IntegerCodec(), 2, 2))->schema(), $codec->schema());

        foreach ([[1], [1, 2, 3], ['a', 'b']] as $value) {
            try {
                $codec->decode($value, Pointer::root());
                self::fail(sprintf('the pair codec decoded %s', json_encode($value)));
            } catch (DecodeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testACustomOperatorReadsItsValueWithItsOwnCodec(): void
    {
        $codec = new ListCodec(new IntegerCodec(), 3, 3);

        self::assertSame($codec, OperatorMetadata::custom('@near', $codec)->valueCodec(new StringCodec()));
    }
}
