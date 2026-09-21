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

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Http\EntityTag;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * @internal
 */
#[CoversClass(EntityTag::class)]
final class EntityTagTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(EntityTag::class))->isReadOnly());
    }

    public function testTheClassIsInternal(): void
    {
        self::assertMatchesRegularExpression(
            '/@internal\b/',
            (string) (new ReflectionClass(EntityTag::class))->getDocComment(),
        );
    }

    /**
     * no reader of a single tag: the library reads entity tags only from lists
     */
    public function testThePublicApiIsTheListReaderTheComparisonsAndTheRenderer(): void
    {
        $methods = array_map(
            static function (ReflectionMethod $method): string {
                return $method->getName();
            },
            (new ReflectionClass(EntityTag::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        sort($methods);

        self::assertSame(['__construct', 'matchesStrongly', 'matchesWeakly', 'parseList', 'toHeaderValue'], $methods);
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
        $reflection = new ReflectionMethod(EntityTag::class, $method);

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
            'the weak comparison' => ['matchesWeakly', false, 'bool', [['other', EntityTag::class]]],
            'the list reader' => ['parseList', true, '?array', [['fieldValue', 'string']]],
        ];
    }

    #[DataProvider('forbiddenCharacters')]
    public function testTheConstructorRefusesACharacterNoEntityTagMayHold(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EntityTag($value);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function forbiddenCharacters(): array
    {
        return [
            'double quote' => ['a"b'],
            'space' => ['a b'],
            'horizontal tab' => ["a\tb"],
            'NUL' => ["a\0b"],
            'unit separator' => ["a\x1fb"],
            'DEL' => ["a\x7fb"],
        ];
    }

    #[DataProvider('refusedValues')]
    public function testTheRefusalWritesAControlCharacterAsAnEscape(string $value, string $expected): void
    {
        try {
            new EntityTag($value);
        } catch (InvalidArgumentException $exception) {
            self::assertSame($expected, $exception->getMessage());

            return;
        }

        self::fail('a value no entity tag may hold was accepted');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refusedValues(): array
    {
        return [
            'a CRLF' => [
                "a\r\nb",
                'an entity tag must hold no quote, space or control character, got "a\\r\\nb"',
            ],
            'a NUL' => [
                "a\0b",
                'an entity tag must hold no quote, space or control character, got "a\\000b"',
            ],
            'a DEL' => [
                "a\x7fb",
                'an entity tag must hold no quote, space or control character, got "a\\177b"',
            ],
            'a double quote, which is no control character' => [
                'a"b',
                'an entity tag must hold no quote, space or control character, got "a"b"',
            ],
        ];
    }

    public function testTheConstructorAcceptsEveryOpaqueCharacter(): void
    {
        $opaque = chr(0x21);

        for ($code = 0x23; $code <= 0x7E; ++$code) {
            $opaque .= chr($code);
        }

        $opaque .= chr(0x80) . chr(0xFF);

        self::assertSame($opaque, (new EntityTag($opaque))->value);
    }

    /**
     * `opaque-tag = DQUOTE *etagc DQUOTE`: a tag of zero etagc is valid
     */
    public function testAnEmptyTagIsRepresentable(): void
    {
        self::assertSame('""', (new EntityTag(''))->toHeaderValue());
    }

    public function testToHeaderValueQuotesAStrongTag(): void
    {
        self::assertSame('"xyzzy"', (new EntityTag('xyzzy'))->toHeaderValue());
    }

    public function testToHeaderValueWritesTheWeakPrefix(): void
    {
        self::assertSame('W/"xyzzy"', (new EntityTag('xyzzy', true))->toHeaderValue());
    }

    #[DataProvider('comparisons')]
    public function testMatchesStronglyFollowsRfc9110(EntityTag $one, EntityTag $other, bool $strong, bool $weak): void
    {
        self::assertSame($strong, $one->matchesStrongly($other));
    }

    #[DataProvider('comparisons')]
    public function testMatchesWeaklyFollowsRfc9110(EntityTag $one, EntityTag $other, bool $strong, bool $weak): void
    {
        self::assertSame($weak, $one->matchesWeakly($other));
    }

    /**
     * the rows of Table 3 of RFC 9110 8.8.3.2 come first, the asymmetric ones also reversed;
     * the rest pin a character-by-character comparison
     *
     * @return array<string, array{EntityTag, EntityTag, bool, bool}> ETag 1, ETag 2, strong, weak
     */
    public static function comparisons(): array
    {
        return [
            'W/"1" and W/"1"' => [new EntityTag('1', true), new EntityTag('1', true), false, true],
            'W/"1" and W/"2"' => [new EntityTag('1', true), new EntityTag('2', true), false, false],
            'W/"2" and W/"1"' => [new EntityTag('2', true), new EntityTag('1', true), false, false],
            'W/"1" and "1"' => [new EntityTag('1', true), new EntityTag('1'), false, true],
            '"1" and W/"1"' => [new EntityTag('1'), new EntityTag('1', true), false, true],
            '"1" and "1"' => [new EntityTag('1'), new EntityTag('1'), true, true],
            'strong against another strong' => [new EntityTag('a'), new EntityTag('b'), false, false],
            'weak against another strong' => [new EntityTag('a', true), new EntityTag('b'), false, false],
            'case differs' => [new EntityTag('a'), new EntityTag('A'), false, false],
            'weak and case differs' => [new EntityTag('a', true), new EntityTag('A', true), false, false],
            'a prefix of the other' => [new EntityTag('ab'), new EntityTag('a'), false, false],
            'two empty tags' => [new EntityTag(''), new EntityTag(''), true, true],
        ];
    }

    /**
     * the examples of RFC 9110 13.1.1 and 13.1.2 come first.
     * tags are compared as rendered, so their weakness is checked too
     *
     * @param list<string> $expected
     */
    #[DataProvider('lists')]
    public function testParseListReadsEveryTagInOrder(string $fieldValue, array $expected): void
    {
        $tags = EntityTag::parseList($fieldValue);

        self::assertNotNull($tags);
        self::assertSame($expected, array_map(static function (EntityTag $tag): string {
            return $tag->toHeaderValue();
        }, $tags));
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function lists(): array
    {
        return [
            'the example of a single tag' => ['"xyzzy"', ['"xyzzy"']],
            'the example of a single weak tag' => ['W/"xyzzy"', ['W/"xyzzy"']],
            'the example list' => [
                '"xyzzy", "r2d2xxxx", "c3piozzzz"',
                ['"xyzzy"', '"r2d2xxxx"', '"c3piozzzz"'],
            ],
            'the example list of weak tags' => [
                'W/"xyzzy", W/"r2d2xxxx", W/"c3piozzzz"',
                ['W/"xyzzy"', 'W/"r2d2xxxx"', 'W/"c3piozzzz"'],
            ],
            'strong and weak tags mixed' => ['W/"1", "1"', ['W/"1"', '"1"']],
            'a comma inside a tag' => ['"a,b", "c"', ['"a,b"', '"c"']],
            'a backslash before the closing quote' => ['"a\\"', ['"a\\"']],
            'obs-text inside a tag' => ["\"\x80\xFF\"", ["\"\x80\xFF\""]],
            'an empty tag' => ['""', ['""']],
            'a quoted asterisk is a tag' => ['"*"', ['"*"']],
            'empty list elements' => [',, "a" ,, "b" ,', ['"a"', '"b"']],
            'no whitespace around the comma' => ['"a","b"', ['"a"', '"b"']],
            'horizontal tabs around the comma' => ["\"a\"\t,\t\"b\"", ['"a"', '"b"']],
            'surrounding whitespace' => ["\t \"a\" ", ['"a"']],
            'the same tag twice' => ['"a", "a"', ['"a"', '"a"']],
        ];
    }

    #[DataProvider('emptyLists')]
    public function testParseListReadsAValueOfNoElementAsTheEmptyList(string $fieldValue): void
    {
        self::assertSame([], EntityTag::parseList($fieldValue));
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

    #[DataProvider('malformedLists')]
    public function testParseListRefusesAValueThatIsNoListOfEntityTags(string $fieldValue): void
    {
        self::assertNull(EntityTag::parseList($fieldValue));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedLists(): array
    {
        return [
            'the wildcard, which is no element of the list' => ['*'],
            'the wildcard beside a tag' => ['"a", *'],
            'an unquoted tag' => ['xyzzy'],
            'an unterminated tag' => ['"a'],
            'a tag without its opening quote' => ['xyzzy"'],
            'a weak tag without its opening quote' => ['W/xyzzy"'],
            'a lone quote' => ['"'],
            'the weak prefix alone' => ['W/'],
            'the weak prefix before an unquoted tag' => ['W/x'],
            'the weak prefix in lower case' => ['w/"a"'],
            'whitespace between the weak prefix and the tag' => ['W/ "a"'],
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
            // OWS is `*( SP / HTAB )` (RFC 9110 5.6.3),
            // so no other control character separates or surrounds an element
            'a line feed behind the comma' => ["\"a\",\n\"b\""],
            'a carriage return behind a tag' => ["\"a\"\r"],
            'a line feed before a tag' => ["\n\"a\""],
            'a vertical tab behind a tag' => ["\"a\"\x0B, \"b\""],
            'a NUL between two elements' => ["\"a\", \0, \"b\""],
        ];
    }
}
