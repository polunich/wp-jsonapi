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

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Query\FieldPath;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * @internal
 */
#[CoversClass(FieldPath::class)]
final class FieldPathTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(FieldPath::class))->isReadOnly());
    }

    public function testAPathOfOneSegmentNamesAFieldOfTheResourceItself(): void
    {
        $path = new FieldPath(['name']);

        self::assertSame(['name'], $path->segments());
        self::assertSame('name', $path->name());
        self::assertSame([], $path->relationships());
        self::assertSame(0, $path->depth());
    }

    public function testALongerPathRunsThroughTheRelationshipsBeforeTheField(): void
    {
        $path = new FieldPath(['owner', 'supplier', 'name']);

        self::assertSame('name', $path->name());
        self::assertSame(['owner', 'supplier'], $path->relationships());
        self::assertSame(2, $path->depth());
    }

    /**
     * @param list<string> $segments
     */
    #[DataProvider('paths')]
    public function testWritesItselfWithTheSeparatorOfTheSpecification(array $segments, string $expected): void
    {
        self::assertSame($expected, (new FieldPath($segments))->toString());
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function paths(): iterable
    {
        yield 'a field' => [['name'], 'name'];
        yield 'a field of a related resource' => [['owner', 'name'], 'owner.name'];
        yield 'two relationships deep' => [['owner', 'supplier', 'name'], 'owner.supplier.name'];
        yield 'a member name of digits' => [['0'], '0'];
    }

    public function testStringCastGivesTheSameStringAsToString(): void
    {
        $path = new FieldPath(['owner', 'name']);

        self::assertSame($path->toString(), (string) $path);
    }

    public function testRefusesAPathThatNamesNothing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('names at least one field');

        new FieldPath([]);
    }

    #[DataProvider('illegalSegments')]
    public function testRefusesASegmentThatIsNoMemberName(string $segment): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is no member name');

        new FieldPath(['owner', $segment]);
    }

    public function testTheConstructorWritesAControlCharacterOfARefusedSegmentAsAnEscape(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('segment "na\nme" of a field path is no member name, so no field and no'
            . ' relationship carries it');

        new FieldPath(['owner', "na\nme"]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function illegalSegments(): iterable
    {
        yield 'empty' => [''];
        yield 'the separator of a path' => ['a.b'];
        yield 'the separator of a list' => ['a,b'];
        yield 'a bracket of a parameter family' => ['a[b'];
        yield 'a plus' => ['a+b'];
        yield 'a hyphen first' => ['-name'];
        yield 'a hyphen last' => ['name-'];
        yield 'a space last' => ['name '];
    }

    #[DataProvider('texts')]
    public function testTellsWhetherATextIsAFieldPath(string $text, bool $expected): void
    {
        self::assertSame($expected, FieldPath::isValid($text));
    }

    /**
     * `isValid()` agrees with the constructor: a text it accepts splits into segments
     * the constructor takes, and `toString()` writes it back unchanged
     */
    #[DataProvider('texts')]
    public function testATextItCallsAFieldPathIsTheTextThePathWrites(string $text, bool $expected): void
    {
        try {
            $written = (new FieldPath(explode('.', $text)))->toString();
        } catch (InvalidArgumentException) {
            $written = null;
        }

        self::assertSame($expected ? $text : null, $written);
    }

    /**
     * parameter names are public API: a caller may pass them as named arguments
     *
     * @param list<array{string, string}> $parameters name and type of each parameter, in order
     */
    #[DataProvider('signatures')]
    public function testTheReadersDeclareTheirPublicSignatures(
        string $method,
        bool $static,
        string $returnType,
        array $parameters,
    ): void {
        $reflection = new ReflectionMethod(FieldPath::class, $method);

        self::assertTrue($reflection->isPublic());
        self::assertSame($static, $reflection->isStatic());
        self::assertSame($returnType, (string) $reflection->getReturnType());
        self::assertSame($parameters, array_map(
            static function (ReflectionParameter $parameter): array {
                return [$parameter->getName(), (string) $parameter->getType()];
            },
            $reflection->getParameters(),
        ));
    }

    /**
     * @return array<string, array{string, bool, string, list<array{string, string}>}>
     */
    public static function signatures(): array
    {
        return [
            'the reader' => ['fromString', true, FieldPath::class, [['path', 'string']]],
            'the path of the id' => ['id', true, FieldPath::class, []],
            'whether the path is the id' => ['isId', false, 'bool', []],
            'whether the path names an id' => ['endsAtId', false, 'bool', []],
        ];
    }

    #[DataProvider('idPaths')]
    public function testTellsWhetherThePathIsTheIdOfTheResourceBeingRead(string $path, bool $expected): void
    {
        self::assertSame($expected, FieldPath::fromString($path)->isId());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function idPaths(): iterable
    {
        yield 'id' => ['id', true];
        yield 'the id of a related resource' => ['owner.id', false];
        yield 'an attribute' => ['name', false];
        yield 'another case' => ['ID', false];
    }

    #[DataProvider('pathsThatNameAnId')]
    public function testTellsWhetherThePathNamesAnIdOfAnyResource(string $path, bool $expected): void
    {
        self::assertSame($expected, FieldPath::fromString($path)->endsAtId());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function pathsThatNameAnId(): iterable
    {
        yield 'id' => ['id', true];
        yield 'the id of a related resource' => ['owner.id', true];
        yield 'the id two relationships away' => ['owner.address.id', true];
        yield 'an attribute' => ['name', false];
        yield 'an attribute of a related resource' => ['owner.name', false];
        yield 'a relationship that runs through id' => ['id.name', false];
        yield 'another case' => ['owner.ID', false];
    }

    public function testThePathOfTheIdIsTheOnePathOfTheSegmentId(): void
    {
        self::assertSame(['id'], FieldPath::id()->segments());
        self::assertTrue(FieldPath::id()->isId());
        self::assertEquals(FieldPath::fromString('id'), FieldPath::id());
    }

    /**
     * @param list<string> $segments
     */
    #[DataProvider('readableTexts')]
    public function testReadsTheSegmentsOfTheText(string $text, array $segments): void
    {
        self::assertSame($segments, FieldPath::fromString($text)->segments());
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function readableTexts(): iterable
    {
        yield 'a field' => ['name', ['name']];
        yield 'a field of a related resource' => ['owner.name', ['owner', 'name']];
        yield 'two relationships deep' => ['owner.supplier.name', ['owner', 'supplier', 'name']];
        yield 'a member name of digits' => ['0', ['0']];
        yield 'a member name with a hyphen inside' => ['owner.first-name', ['owner', 'first-name']];
        yield 'a member name with a space inside' => ['first name', ['first name']];
    }

    #[DataProvider('texts')]
    public function testReadsATextExactlyWhenItIsAFieldPathAndWritesItBack(string $text, bool $expected): void
    {
        try {
            $written = FieldPath::fromString($text)->toString();
        } catch (InvalidArgumentException) {
            $written = null;
        }

        self::assertSame($expected ? $text : null, $written);
    }

    /**
     * @param list<string> $segments
     */
    #[DataProvider('paths')]
    public function testReadsBackWhatItWrote(array $segments, string $text): void
    {
        $path = new FieldPath($segments);

        self::assertSame($text, $path->toString());
        self::assertEquals($path, FieldPath::fromString($text));
    }

    #[DataProvider('refusedTexts')]
    public function testRefusesATextThatIsNoFieldPath(string $text, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        FieldPath::fromString($text);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedTexts(): iterable
    {
        yield 'empty' => ['', 'field path "" is no list of member names separated by "."'];
        yield 'a separator last' => ['owner.', 'field path "owner." is no list of member names separated by "."'];
        yield 'two separators together' => [
            'owner..name',
            'field path "owner..name" is no list of member names separated by "."',
        ];
        yield 'a hyphen first in a segment' => [
            'owner.-name',
            'field path "owner.-name" is no list of member names separated by "."',
        ];
        yield 'the separator of a list' => [
            'name,description',
            'field path "name,description" is no list of member names separated by "."',
        ];
    }

    public function testTheRefusalWritesAControlCharacterAsAnEscape(): void
    {
        try {
            FieldPath::fromString("owner.a\r\nb\x7F");
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'field path "owner.a\\r\\nb\\177" is no list of member names separated by "."',
                $exception->getMessage(),
            );

            return;
        }

        self::fail('a text that is no field path was read');
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function texts(): iterable
    {
        yield 'a field' => ['name', true];
        yield 'a field of a related resource' => ['owner.name', true];
        yield 'two relationships deep' => ['owner.supplier.name', true];
        yield 'a member name of digits' => ['0', true];
        yield 'a member name with a space inside' => ['first name', true];
        yield 'empty' => ['', false];
        yield 'the separator alone' => ['.', false];
        yield 'a separator first' => ['.name', false];
        yield 'a separator last' => ['owner.', false];
        yield 'two separators together' => ['owner..name', false];
        yield 'a reserved character in a segment' => ['owner.name!', false];
        yield 'the separator of a list' => ['name,description', false];
        yield 'a space last' => ['owner.name ', false];
    }
}
