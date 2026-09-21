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

namespace Polunich\WpJsonApi\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Http\EntityTag;
use Polunich\WpJsonApi\Http\IfNoneMatch;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * `IfNoneMatch` shows no state, so a reading is pinned by what it returns:
 * a value that reads as null yields null through `?->`, which `assertSame()` tells from false
 *
 * @internal
 */
#[CoversClass(IfNoneMatch::class)]
final class IfNoneMatchTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(IfNoneMatch::class))->isReadOnly());
    }

    public function testTheClassIsInternal(): void
    {
        self::assertMatchesRegularExpression(
            '/@internal\b/',
            (string) (new ReflectionClass(IfNoneMatch::class))->getDocComment(),
        );
    }

    /**
     * the private constructor lets a value exist only as a parsed field value
     */
    public function testThePublicApiIsTheReaderAndTheMatch(): void
    {
        $class = new ReflectionClass(IfNoneMatch::class);
        $methods = array_map(
            static function (ReflectionMethod $method): string {
                return $method->getName();
            },
            $class->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        sort($methods);

        self::assertSame(['matches', 'parse'], $methods);
        self::assertTrue($class->getConstructor()?->isPrivate());
    }

    /**
     * @param list<array{string, string}> $parameters name and type of each parameter, in order
     */
    #[DataProvider('signatures')]
    public function testTheMethodsDeclareTheirPublicSignatures(
        string $method,
        bool $static,
        string $returnType,
        array $parameters,
    ): void {
        $reflection = new ReflectionMethod(IfNoneMatch::class, $method);

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
            'the reader' => ['parse', true, '?' . IfNoneMatch::class, [['fieldValue', 'string']]],
            'the match' => ['matches', false, 'bool', [['current', '?' . EntityTag::class]]],
        ];
    }

    #[DataProvider('currentRepresentations')]
    public function testTheWildcardMatchesEveryCurrentRepresentation(?EntityTag $current): void
    {
        self::assertTrue(IfNoneMatch::parse('*')?->matches($current));
    }

    /**
     * @return array<string, array{EntityTag|null}>
     */
    public static function currentRepresentations(): array
    {
        return [
            'a strong tag' => [new EntityTag('xyzzy')],
            'a weak tag' => [new EntityTag('xyzzy', true)],
            'an empty tag' => [new EntityTag('')],
            'a representation without a tag' => [null],
        ];
    }

    /**
     * `matches(null)` is true for the wildcard only, which proves the reading
     */
    #[DataProvider('wildcardsWithWhitespace')]
    public function testTheWildcardIsReadWithoutItsSurroundingWhitespace(string $fieldValue): void
    {
        self::assertTrue(IfNoneMatch::parse($fieldValue)?->matches(null));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function wildcardsWithWhitespace(): array
    {
        return [
            'leading space' => [' *'],
            'trailing space' => ['* '],
            'horizontal tabs and spaces' => ["\t *  \t"],
        ];
    }

    /**
     * only OWS, which is SP and HTAB, is trimmed around the wildcard
     */
    #[DataProvider('wildcardsBesideAControlCharacter')]
    public function testTheWildcardBesideAControlCharacterReadsAsNull(string $fieldValue): void
    {
        self::assertNull(IfNoneMatch::parse($fieldValue));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function wildcardsBesideAControlCharacter(): array
    {
        return [
            'a trailing line feed' => ["*\n"],
            'a leading carriage return' => ["\r*"],
            'a trailing NUL' => ["*\0"],
            'a trailing vertical tab' => ["*\x0B"],
        ];
    }

    /**
     * the examples of RFC 9110 13.1.2 come first
     */
    #[DataProvider('lists')]
    public function testAListMatchesWhenAListedTagMatches(string $fieldValue, EntityTag $current, bool $expected): void
    {
        self::assertSame($expected, IfNoneMatch::parse($fieldValue)?->matches($current));
    }

    /**
     * @return array<string, array{string, EntityTag, bool}>
     */
    public static function lists(): array
    {
        $example = '"xyzzy", "r2d2xxxx", "c3piozzzz"';
        $weakExample = 'W/"xyzzy", W/"r2d2xxxx", W/"c3piozzzz"';

        return [
            'the example of a single tag' => ['"xyzzy"', new EntityTag('xyzzy'), true],
            'the example of a single weak tag' => ['W/"xyzzy"', new EntityTag('xyzzy'), true],
            'the first tag of the example list' => [$example, new EntityTag('xyzzy'), true],
            'the last tag of the example list' => [$example, new EntityTag('c3piozzzz'), true],
            'the second tag of the weak example list' => [$weakExample, new EntityTag('r2d2xxxx'), true],
            'a tag the example list does not name' => [$example, new EntityTag('r2d2'), false],
            'a tag the weak example list does not name' => [$weakExample, new EntityTag('r2d2', true), false],
            'a quoted asterisk is no wildcard' => ['"*"', new EntityTag('a'), false],
            'empty list elements' => [',, "a" ,, "b" ,', new EntityTag('b'), true],
        ];
    }

    /**
     * the rows of Table 3 of RFC 9110 8.8.3.2 come first, the listed tag as ETag 1
     */
    #[DataProvider('weakComparisons')]
    public function testAListComparesWithTheWeakComparison(string $fieldValue, EntityTag $current, bool $expected): void
    {
        self::assertSame($expected, IfNoneMatch::parse($fieldValue)?->matches($current));
    }

    /**
     * @return array<string, array{string, EntityTag, bool}>
     */
    public static function weakComparisons(): array
    {
        return [
            'W/"1" against W/"1"' => ['W/"1"', new EntityTag('1', true), true],
            'W/"1" against W/"2"' => ['W/"1"', new EntityTag('2', true), false],
            'W/"1" against "1"' => ['W/"1"', new EntityTag('1'), true],
            '"1" against "1"' => ['"1"', new EntityTag('1'), true],
            '"1" against W/"1"' => ['"1"', new EntityTag('1', true), true],
            'the case of the opaque tag differs' => ['"a"', new EntityTag('A'), false],
            'the case differs between weak tags' => ['W/"a"', new EntityTag('A', true), false],
        ];
    }

    #[DataProvider('listsOfTags')]
    public function testAListNeverMatchesARepresentationWithoutATag(string $fieldValue): void
    {
        self::assertFalse(IfNoneMatch::parse($fieldValue)?->matches(null));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function listsOfTags(): array
    {
        return [
            'a strong tag' => ['"xyzzy"'],
            'a weak tag' => ['W/"xyzzy"'],
            'an empty tag' => ['""'],
            'a quoted asterisk' => ['"*"'],
        ];
    }

    #[DataProvider('emptyLists')]
    public function testAListOfNoTagMatchesNothing(string $fieldValue): void
    {
        $precondition = IfNoneMatch::parse($fieldValue);

        self::assertNotNull($precondition);
        self::assertFalse($precondition->matches(new EntityTag('xyzzy')));
        self::assertFalse($precondition->matches(new EntityTag('', true)));
        self::assertFalse($precondition->matches(new EntityTag('')));
        self::assertFalse($precondition->matches(null));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function emptyLists(): array
    {
        return [
            'an empty field value' => [''],
            'whitespace only' => [" \t "],
            'a single comma' => [','],
            'empty elements only' => [" , ,\t,"],
        ];
    }

    #[DataProvider('listsWithTheWildcard')]
    public function testAListThatHoldsTheWildcardReadsAsNull(string $fieldValue): void
    {
        self::assertNull(IfNoneMatch::parse($fieldValue));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function listsWithTheWildcard(): array
    {
        return [
            'the wildcard before a tag' => ['*, "a"'],
            'the wildcard behind a weak tag' => ['W/"a", *'],
            'the wildcard twice' => ['*, *'],
            'the wildcard before an empty element' => ['*,'],
            'the wildcard glued to a tag' => ['*"a"'],
        ];
    }

    #[DataProvider('malformedValues')]
    public function testAMalformedValueReadsAsNull(string $fieldValue): void
    {
        self::assertNull(IfNoneMatch::parse($fieldValue));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedValues(): array
    {
        return [
            'an unquoted tag' => ['xyzzy'],
            'an unterminated tag' => ['"a'],
            'the weak prefix in lower case' => ['w/"a"'],
            'the weak prefix before the wildcard' => ['W/*'],
            'a space inside a tag' => ['"a b"'],
            'two tags without a comma' => ['W/"a" W/"b"'],
            'a malformed tag behind a valid one' => ['W/"a", b'],
        ];
    }
}
