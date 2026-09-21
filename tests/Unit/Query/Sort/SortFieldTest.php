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

namespace Polunich\WpJsonApi\Tests\Unit\Query\Sort;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Query\Sort\Direction;
use Polunich\WpJsonApi\Query\Sort\SortField;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * @internal
 */
#[CoversClass(SortField::class)]
final class SortFieldTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(SortField::class))->isReadOnly());
    }

    public function testIsReadAscendingUnlessTheClientAsksOtherwise(): void
    {
        $field = new SortField(new FieldPath(['name']));

        self::assertSame(Direction::Ascending, $field->direction);
    }

    #[DataProvider('fields')]
    public function testWritesItselfAsTheClientWroteIt(SortField $field, string $expected): void
    {
        self::assertSame($expected, $field->toString());
        self::assertSame($expected, (string) $field);
    }

    /**
     * @return iterable<string, array{SortField, string}>
     */
    public static function fields(): iterable
    {
        yield 'ascending' => [new SortField(new FieldPath(['name'])), 'name'];
        yield 'descending' => [
            new SortField(new FieldPath(['created']), Direction::Descending),
            '-created',
        ];
        yield 'through a to-one relationship' => [
            new SortField(new FieldPath(['owner', 'name'])),
            'owner.name',
        ];
        yield 'through a to-one relationship, descending' => [
            new SortField(new FieldPath(['owner', 'name']), Direction::Descending),
            '-owner.name',
        ];
    }

    public function testCarriesThePathOfTheFieldItOrdersBy(): void
    {
        $path = new FieldPath(['owner', 'name']);

        self::assertSame($path, (new SortField($path))->field);
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
        $reflection = new ReflectionMethod(SortField::class, $method);

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
            'the reader' => ['fromString', true, SortField::class, [['field', 'string']]],
        ];
    }

    /**
     * @param list<string> $segments
     */
    #[DataProvider('texts')]
    public function testReadsTheTextOfASortField(string $text, array $segments, Direction $direction): void
    {
        $field = SortField::fromString($text);

        self::assertSame($segments, $field->field->segments());
        self::assertSame($direction, $field->direction);
    }

    /**
     * @return iterable<string, array{string, list<string>, Direction}>
     */
    public static function texts(): iterable
    {
        yield 'ascending' => ['name', ['name'], Direction::Ascending];
        yield 'descending' => ['-created', ['created'], Direction::Descending];
        yield 'the id' => ['id', ['id'], Direction::Ascending];
        yield 'through a to-one relationship' => ['owner.name', ['owner', 'name'], Direction::Ascending];
        yield 'through two relationships, descending' => [
            '-owner.city.name',
            ['owner', 'city', 'name'],
            Direction::Descending,
        ];
        yield 'a member name with a hyphen inside' => ['-listed-at', ['listed-at'], Direction::Descending];
        yield 'a member name of digits' => ['0', ['0'], Direction::Ascending];
        yield 'a member name with a space inside' => ['-first name', ['first name'], Direction::Descending];
    }

    /**
     * @param list<string> $segments
     */
    #[DataProvider('texts')]
    public function testWritesBackTheTextItRead(string $text, array $segments, Direction $direction): void
    {
        self::assertSame($text, SortField::fromString($text)->toString());
    }

    #[DataProvider('fields')]
    public function testReadsBackWhatItWrote(SortField $field, string $text): void
    {
        self::assertEquals($field, SortField::fromString($text));
    }

    #[DataProvider('malformedTexts')]
    public function testRefusesATextThatIsNoSortField(string $text): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is no field path');

        SortField::fromString($text);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedTexts(): iterable
    {
        yield 'empty' => [''];
        yield 'the minus alone' => ['-'];
        yield 'two minus signs' => ['--name'];
        yield 'a plus sign for the ascending order' => ['+name'];
        yield 'a minus sign behind the field' => ['name-'];
        yield 'a leading dot' => ['.name'];
        yield 'a trailing dot' => ['owner.'];
        yield 'two dots' => ['owner..name'];
        yield 'a leading space' => [' name'];
        yield 'a space behind the minus' => ['- name'];
        yield 'a trailing space' => ['name '];
        yield 'a comma, which separates two sort fields' => ['name,created'];
        yield 'a colon of another syntax' => ['name:asc'];
        yield 'a line feed' => ["name\n"];
    }

    /**
     * with and without `-`, `FieldPath`'s own refusal is kept as the previous exception
     */
    #[DataProvider('pathsFieldPathRefuses')]
    public function testRefusesAPathThatFieldPathRefuses(string $path): void
    {
        $expected = null;

        try {
            FieldPath::fromString($path);
        } catch (InvalidArgumentException $exception) {
            $expected = $exception->getMessage();
        }

        self::assertNotNull($expected, FieldPath::class . '::fromString() read a path the data set names as refused');

        foreach (['', Direction::Descending->prefix()] as $prefix) {
            $previous = null;

            try {
                SortField::fromString($prefix . $path);
            } catch (InvalidArgumentException $refusal) {
                $previous = $refusal->getPrevious();
            }

            self::assertInstanceOf(InvalidArgumentException::class, $previous);
            self::assertSame($expected, $previous->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathsFieldPathRefuses(): iterable
    {
        yield 'empty' => [''];
        yield 'a separator alone' => ['.'];
        yield 'a separator last' => ['owner.'];
        yield 'two separators together' => ['owner..name'];
        yield 'a reserved character in a segment' => ['owner.name!'];
        yield 'a plus sign first' => ['+name'];
        yield 'a space last' => ['name '];
        yield 'a control character, which the refusal writes as an escape' => ["name\n"];
    }

    #[DataProvider('pathsFieldPathReads')]
    public function testReadsThePathAsFieldPathReadsIt(string $path): void
    {
        self::assertEquals(FieldPath::fromString($path), SortField::fromString($path)->field);
        self::assertEquals(
            FieldPath::fromString($path),
            SortField::fromString(Direction::Descending->prefix() . $path)->field,
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathsFieldPathReads(): iterable
    {
        yield 'a field' => ['name'];
        yield 'through a to-one relationship' => ['owner.name'];
        yield 'a member name with a hyphen inside' => ['listed-at'];
        yield 'a member name of digits' => ['0'];
    }

    public function testTheRefusalWritesAControlCharacterAsAnEscape(): void
    {
        try {
            SortField::fromString("-a\r\nb");
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'sort field "-a\\r\\nb" is no field path of member names separated by ".", with at most a'
                . ' "-" before it',
                $exception->getMessage(),
            );

            return;
        }

        self::fail('a text that is no sort field was read');
    }
}
