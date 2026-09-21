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
use Polunich\WpJsonApi\Http\IfMatch;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\VersionTag;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * `IfMatch` shows no state, so a reading is pinned by what it evaluates to:
 * a value that reads as null yields null through `?->`, which `assertSame()` tells from false
 *
 * @internal
 */
#[CoversClass(IfMatch::class)]
final class IfMatchTest extends TestCase
{
    /** the atomic extension URI as `VersionTag` writes it */
    private const ATOMIC_PCT = 'https%3A%2F%2Fjsonapi.org%2Fext%2Fatomic';

    /** the cursor pagination profile URI as `VersionTag` writes it */
    private const CURSOR_PCT = 'https%3A%2F%2Fjsonapi.org%2Fprofiles%2Fethanresnick%2Fcursor-pagination';

    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(IfMatch::class))->isReadOnly());
    }

    public function testTheClassIsInternal(): void
    {
        self::assertMatchesRegularExpression(
            '/@internal\b/',
            (string) (new ReflectionClass(IfMatch::class))->getDocComment(),
        );
    }

    /**
     * the private constructor lets a value exist only as a parsed field value
     */
    public function testThePublicApiIsTheReaderTheEvaluationAndTheVersions(): void
    {
        $class = new ReflectionClass(IfMatch::class);
        $methods = array_map(
            static function (ReflectionMethod $method): string {
                return $method->getName();
            },
            $class->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        sort($methods);

        self::assertSame(['matches', 'parse', 'versions'], $methods);
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
        $reflection = new ReflectionMethod(IfMatch::class, $method);

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
            'the reader' => ['parse', true, '?' . IfMatch::class, [['fieldValue', 'string']]],
            'the evaluation' => ['matches', false, 'bool', [['current', '?' . EntityTag::class]]],
            'the versions' => [
                'versions',
                false,
                '?array',
                [['contentLanguage', '?string'], ['contentType', MediaType::class]],
            ],
        ];
    }

    #[DataProvider('currentRepresentations')]
    public function testTheWildcardHoldsForEveryCurrentRepresentation(?EntityTag $current): void
    {
        self::assertTrue(IfMatch::parse('*')?->matches($current));
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
        self::assertTrue(IfMatch::parse($fieldValue)?->matches(null));
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
        self::assertNull(IfMatch::parse($fieldValue));
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
     * the examples of RFC 9110 13.1.1 come first
     */
    #[DataProvider('lists')]
    public function testAListHoldsWhenAListedTagMatches(string $fieldValue, EntityTag $current, bool $expected): void
    {
        self::assertSame($expected, IfMatch::parse($fieldValue)?->matches($current));
    }

    /**
     * @return array<string, array{string, EntityTag, bool}>
     */
    public static function lists(): array
    {
        $example = '"xyzzy", "r2d2xxxx", "c3piozzzz"';

        return [
            'the example of a single tag' => ['"xyzzy"', new EntityTag('xyzzy'), true],
            'the first tag of the example list' => [$example, new EntityTag('xyzzy'), true],
            'the second tag of the example list' => [$example, new EntityTag('r2d2xxxx'), true],
            'the last tag of the example list' => [$example, new EntityTag('c3piozzzz'), true],
            'a tag the example list does not name' => [$example, new EntityTag('r2d2'), false],
            'a comma inside a tag' => ['"a,b", "c"', new EntityTag('a,b'), true],
            'a comma inside a tag is no separator' => ['"a,b", "c"', new EntityTag('a'), false],
            'a backslash before the closing quote' => ['"a\\"', new EntityTag('a\\'), true],
            'obs-text inside a tag' => ["\"\x80\xFF\"", new EntityTag("\x80\xFF"), true],
            'an empty tag' => ['""', new EntityTag(''), true],
            'a quoted asterisk is a tag' => ['"*"', new EntityTag('*'), true],
            'a quoted asterisk is no wildcard' => ['"*"', new EntityTag('a'), false],
            'empty list elements' => [',, "a" ,, "b" ,', new EntityTag('b'), true],
            'no whitespace around the comma' => ['"a","b"', new EntityTag('b'), true],
            'horizontal tabs around the comma' => ["\"a\"\t,\t\"b\"", new EntityTag('b'), true],
            'surrounding whitespace' => ["\t \"a\" ", new EntityTag('a'), true],
            'the same tag twice' => ['"a", "a"', new EntityTag('a'), true],
        ];
    }

    #[DataProvider('strongComparisons')]
    public function testAListComparesWithTheStrongComparison(
        string $fieldValue,
        EntityTag $current,
        bool $expected,
    ): void {
        self::assertSame($expected, IfMatch::parse($fieldValue)?->matches($current));
    }

    /**
     * @return array<string, array{string, EntityTag, bool}>
     */
    public static function strongComparisons(): array
    {
        return [
            'a listed weak tag against the same strong tag' => ['W/"1"', new EntityTag('1'), false],
            'a listed strong tag against the same weak tag' => ['"1"', new EntityTag('1', true), false],
            'a listed weak tag against the same weak tag' => ['W/"1"', new EntityTag('1', true), false],
            'a listed weak tag and the same strong tag' => ['W/"1", "1"', new EntityTag('1'), true],
            'the case of the opaque tag differs' => ['"a"', new EntityTag('A'), false],
        ];
    }

    #[DataProvider('listsOfTags')]
    public function testAListNeverHoldsForARepresentationWithoutATag(string $fieldValue): void
    {
        self::assertFalse(IfMatch::parse($fieldValue)?->matches(null));
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
    public function testAListOfNoTagNeverHolds(string $fieldValue): void
    {
        $precondition = IfMatch::parse($fieldValue);

        self::assertNotNull($precondition);
        self::assertFalse($precondition->matches(new EntityTag('xyzzy')));
        self::assertFalse($precondition->matches(new EntityTag('', true)));
        self::assertFalse($precondition->matches(new EntityTag('')));
        self::assertFalse($precondition->matches(null));
        self::assertSame([], $precondition->versions(null, MediaType::jsonApi()));
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
        self::assertNull(IfMatch::parse($fieldValue));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function listsWithTheWildcard(): array
    {
        return [
            'the wildcard before a tag' => ['*, "a"'],
            'the wildcard behind a tag' => ['"a", *'],
            'the wildcard twice' => ['*, *'],
            'the wildcard before an empty element' => ['*,'],
            'the wildcard behind an empty element' => [', *'],
            'the wildcard glued to a tag' => ['*"a"'],
        ];
    }

    #[DataProvider('malformedValues')]
    public function testAMalformedValueReadsAsNull(string $fieldValue): void
    {
        self::assertNull(IfMatch::parse($fieldValue));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedValues(): array
    {
        return [
            'an unquoted tag' => ['xyzzy'],
            'an unterminated tag' => ['"a'],
            'a lone quote' => ['"'],
            'the weak prefix alone' => ['W/'],
            'the weak prefix before an unquoted tag' => ['W/x'],
            'the weak prefix in lower case' => ['w/"a"'],
            'whitespace between the weak prefix and the tag' => ['W/ "a"'],
            'the weak prefix before the wildcard' => ['W/*'],
            'a space inside a tag' => ['"a b"'],
            'a horizontal tab inside a tag' => ["\"a\tb\""],
            'a control character inside a tag' => ["\"a\x01b\""],
            'NUL inside a tag' => ["\"a\0b\""],
            'DEL inside a tag' => ["\"a\x7Fb\""],
            'two tags without a comma' => ['"a" "b"'],
            'two tags glued together' => ['"a""b"'],
            'a tag followed by a token' => ['"a"b'],
            'a semicolon between two tags' => ['"a"; "b"'],
            'a malformed tag behind a valid one' => ['"a", b'],
            'a malformed tag before a valid one' => ['b, "a"'],
        ];
    }

    #[DataProvider('wildcardsWithWhitespace')]
    public function testTheWildcardStandsForNoListOfVersions(string $fieldValue): void
    {
        $precondition = IfMatch::parse($fieldValue);
        $bare = IfMatch::parse('*');

        // asserted first: a null reading would return null through `?->` as well
        self::assertNotNull($precondition);
        self::assertNotNull($bare);
        self::assertNull($precondition->versions('uk', MediaType::jsonApi()));
        self::assertNull($bare->versions(null, self::negotiated()));
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('listsOfVersions')]
    public function testAListStandsForTheVersionsItsStrongTagsDecodeTo(string $fieldValue, array $expected): void
    {
        self::assertSame($expected, IfMatch::parse($fieldValue)?->versions('uk', self::negotiated()));
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function listsOfVersions(): array
    {
        $v7 = self::tag('v7');
        $v8 = self::tag('v8');
        $spaced = self::tag('2026-09-16 10:00:00');
        $empty = self::tag('');
        $otherLanguage = VersionTag::encode('v9', 'en', self::negotiated())->toHeaderValue();
        $noProfile = VersionTag::encode('v9', 'uk', MediaType::jsonApi())->toHeaderValue();

        return [
            'one tag' => [$v7, ['v7']],
            'two tags in the order they were sent' => [$v8 . ', ' . $v7, ['v8', 'v7']],
            'the same tag twice' => [$v7 . ', ' . $v8 . ', ' . $v7, ['v7', 'v8', 'v7']],
            'a version that needs escapes' => [$spaced, ['2026-09-16 10:00:00']],
            'the empty version' => [$empty, ['']],
            'a weak tag' => ['W/' . $v7, []],
            'a weak and a strong tag' => ['W/' . $v7 . ', ' . $v8, ['v8']],
            'a tag of another language' => [$otherLanguage, []],
            'a tag without the profile of the representation' => [$noProfile, []],
            'a tag no version rule wrote' => ['"xyzzy"', []],
            // v7 with its 7 escaped, a spelling `VersionTag` never writes
            'a tag that is no canonical spelling' => [
                '"v%37\'luk\'e' . self::ATOMIC_PCT . '\'p' . self::CURSOR_PCT . '"',
                [],
            ],
            'the canonical spelling of the same parts' => [
                '"v7\'luk\'e' . self::ATOMIC_PCT . '\'p' . self::CURSOR_PCT . '"',
                ['v7'],
            ],
        ];
    }

    #[DataProvider('listsAgainstStoredVersions')]
    public function testTheVersionsAdmitWhatTheEvaluationAdmits(string $fieldValue, string $stored): void
    {
        $precondition = IfMatch::parse($fieldValue);

        self::assertNotNull($precondition);

        $versions = $precondition->versions('uk', self::negotiated());
        $current = VersionTag::encode($stored, 'uk', self::negotiated());

        self::assertNotNull($versions);
        self::assertSame(in_array($stored, $versions, true), $precondition->matches($current));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function listsAgainstStoredVersions(): array
    {
        $list = self::tag('v7') . ', W/' . self::tag('v8') . ', "xyzzy"';

        return [
            'a listed strong tag' => [$list, 'v7'],
            'a listed weak tag' => [$list, 'v8'],
            'a version the list does not name' => [$list, 'v9'],
            'the opaque tag of another tag' => [$list, 'xyzzy'],
            'the empty version' => [self::tag(''), ''],
        ];
    }

    /**
     * applies an extension and a profile, so every tag of these tests covers both
     */
    private static function negotiated(): MediaType
    {
        return MediaType::jsonApi(
            ['https://jsonapi.org/ext/atomic'],
            ['https://jsonapi.org/profiles/ethanresnick/cursor-pagination'],
        );
    }

    private static function tag(string $version): string
    {
        return VersionTag::encode($version, 'uk', self::negotiated())->toHeaderValue();
    }
}
