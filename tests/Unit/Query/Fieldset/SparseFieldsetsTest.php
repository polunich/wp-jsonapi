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

namespace Polunich\WpJsonApi\Tests\Unit\Query\Fieldset;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(SparseFieldsets::class)]
final class SparseFieldsetsTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(SparseFieldsets::class))->isReadOnly());
    }

    /**
     * the spec's example, `fields[articles]=title,body&fields[people]=name`
     */
    public function testCarriesTheFieldsTheClientNamedPerType(): void
    {
        $fieldsets = new SparseFieldsets([
            'articles' => ['title', 'body'],
            'people' => ['name'],
        ]);

        self::assertSame(['title', 'body'], $fieldsets->fields('articles'));
        self::assertSame(['name'], $fieldsets->fields('people'));
        self::assertSame(['articles', 'people'], $fieldsets->types());
        self::assertFalse($fieldsets->isEmpty());
    }

    public function testTellsATypeItWasNotAskedAboutFromATypeAskedWithNoField(): void
    {
        $fieldsets = new SparseFieldsets(['pets' => []]);

        self::assertTrue($fieldsets->has('pets'));
        self::assertSame([], $fieldsets->fields('pets'));

        self::assertFalse($fieldsets->has('customers'));
        self::assertNull($fieldsets->fields('customers'));
    }

    public function testTheRequestWithoutAFieldsParameterNamesNoType(): void
    {
        $fieldsets = new SparseFieldsets([]);

        self::assertTrue($fieldsets->isEmpty());
        self::assertSame([], $fieldsets->types());
    }

    public function testATypeOfDigitsIsReadBackAsTheStringTheClientWrote(): void
    {
        $fieldsets = new SparseFieldsets(['0' => ['name']]);

        self::assertSame(['0'], $fieldsets->types());
        self::assertTrue($fieldsets->has('0'));
        self::assertSame(['name'], $fieldsets->fields('0'));
    }

    /**
     * the repeat stands at different places: dropping it must leave a list wherever it stood
     *
     * @param list<string> $named
     * @param list<string> $expected
     */
    #[DataProvider('repeatedFields')]
    public function testAFieldNamedTwiceIsOneField(array $named, array $expected): void
    {
        $fieldsets = new SparseFieldsets(['pets' => $named]);

        self::assertSame($expected, $fieldsets->fields('pets'));
    }

    /**
     * @return iterable<string, array{list<string>, list<string>}>
     */
    public static function repeatedFields(): iterable
    {
        yield 'the repeat last' => [['name', 'description', 'name'], ['name', 'description']];
        yield 'the repeat next to the first' => [['name', 'name', 'description'], ['name', 'description']];
        yield 'two repeats' => [
            ['description', 'name', 'description', 'name', 'owner'],
            ['description', 'name', 'owner'],
        ];
    }

    #[DataProvider('illegalTypes')]
    public function testRefusesATypeThatIsNoMemberName(string $type): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is no member name');

        new SparseFieldsets([$type => ['name']]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function illegalTypes(): iterable
    {
        yield 'empty' => [''];
        yield 'a reserved character' => ['pe.ts'];
        yield 'a bracket' => ['pets['];
        yield 'a hyphen first' => ['-pets'];
    }

    #[DataProvider('illegalFields')]
    public function testRefusesAFieldThatIsNoMemberName(string $field): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is no member name');

        new SparseFieldsets(['pets' => [$field]]);
    }

    /**
     * @param array<array-key, list<string>> $fieldsets
     */
    #[DataProvider('refusalsNamingOutsideValues')]
    public function testEscapesAControlCharacterOfARefusedNameInTheMessage(array $fieldsets, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new SparseFieldsets($fieldsets);
    }

    /**
     * @return iterable<string, array{array<array-key, list<string>>, string}>
     */
    public static function refusalsNamingOutsideValues(): iterable
    {
        yield 'a type' => [["pe\nts" => ['name']], 'type "pe\nts" is no member name, so no resource type carries it'];
        yield 'a field' => [
            ['pets' => ["na\tme"]],
            'field "na\tme" of type "pets" is no member name, so no attribute and no relationship carries it',
        ];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function illegalFields(): iterable
    {
        yield 'empty' => [''];
        yield 'the separator of a list' => ['name,description'];
        yield 'a path' => ['owner.name'];
        yield 'a space last' => ['name '];
    }
}
