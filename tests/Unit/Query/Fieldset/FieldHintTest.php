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
use Polunich\WpJsonApi\Query\Fieldset\FieldHint;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(FieldHint::class)]
final class FieldHintTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(FieldHint::class))->isReadOnly());
    }

    public function testTheClientThatNamedNoFieldsetLeavesEveryFieldToBeLoaded(): void
    {
        $hint = new FieldHint(null);

        self::assertTrue($hint->hasEveryField());
        self::assertNull($hint->fields());
        self::assertTrue($hint->has('name'));
        self::assertTrue($hint->has('anything else'));
    }

    public function testCarriesTheFieldsTheClientNamed(): void
    {
        $hint = new FieldHint(['name', 'description']);

        self::assertFalse($hint->hasEveryField());
        self::assertSame(['name', 'description'], $hint->fields());
        self::assertTrue($hint->has('name'));
        self::assertFalse($hint->has('owner'));
    }

    public function testAlwaysCarriesTheFieldsTheLibraryNeeds(): void
    {
        $hint = new FieldHint(['name'], ['listedAt', 'owner']);

        self::assertSame(['name', 'listedAt', 'owner'], $hint->fields());
        self::assertTrue($hint->has('listedAt'));
        self::assertTrue($hint->has('owner'));
    }

    public function testAFieldTheClientNamedAndTheLibraryNeedsStandsOnce(): void
    {
        $hint = new FieldHint(['name', 'listedAt'], ['listedAt']);

        self::assertSame(['name', 'listedAt'], $hint->fields());
    }

    /**
     * @param list<string> $requested
     * @param list<string> $required
     * @param list<string> $expected
     */
    #[DataProvider('repeatedFields')]
    public function testAFieldNamedTwiceStandsOnce(array $requested, array $required, array $expected): void
    {
        self::assertSame($expected, (new FieldHint($requested, $required))->fields());
    }

    /**
     * @return iterable<string, array{list<string>, list<string>, list<string>}>
     */
    public static function repeatedFields(): iterable
    {
        yield 'twice by the client' => [['name', 'name', 'description'], [], ['name', 'description']];
        yield 'twice among the fields the library needs' => [[], ['listedAt', 'listedAt'], ['listedAt']];
        yield 'twice on each side' => [
            ['name', 'description', 'name'],
            ['listedAt', 'name', 'listedAt'],
            ['name', 'description', 'listedAt'],
        ];
    }

    public function testTheClientThatAskedForNoFieldStillLeavesTheFieldsTheLibraryNeeds(): void
    {
        $hint = new FieldHint([], ['listedAt']);

        self::assertSame(['listedAt'], $hint->fields());
        self::assertFalse($hint->hasEveryField());
    }

    public function testEveryFieldSubsumesTheFieldsTheLibraryNeeds(): void
    {
        $hint = new FieldHint(null, ['listedAt']);

        self::assertTrue($hint->hasEveryField());
        self::assertTrue($hint->has('listedAt'));
    }

    #[DataProvider('illegalFields')]
    public function testRefusesAFieldTheClientNamedThatIsNoMemberName(string $field): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is no member name');

        new FieldHint([$field]);
    }

    #[DataProvider('illegalFields')]
    public function testRefusesAFieldTheLibraryNeedsThatIsNoMemberName(string $field): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is no member name');

        new FieldHint(null, [$field]);
    }

    public function testEscapesAControlCharacterOfARefusedFieldInTheMessage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('field "na\nme" is no member name, so no attribute and no relationship carries'
            . ' it');

        new FieldHint(["na\nme"]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function illegalFields(): iterable
    {
        yield 'empty' => [''];
        yield 'the separator of a list' => ['name,description'];
        yield 'a path' => ['owner.name'];
        yield 'a hyphen last' => ['name-'];
    }
}
