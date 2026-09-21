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

namespace Polunich\WpJsonApi\Tests\Unit\Document;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Document\MemberName;

/**
 * @internal
 */
#[CoversClass(MemberName::class)]
final class MemberNameTest extends TestCase
{
    /**
     * the reserved characters in the order of the specification, except `@`, tested on its own.
     * U+007F and the C0 controls, which close the list, are added by the provider
     */
    private const RESERVED = '+,.[]!"#$%&\'()*/:;<=>?\\^`{|}~';

    #[DataProvider('legalNames')]
    public function testANameOfAllowedCharactersIsAccepted(string $name): void
    {
        self::assertTrue(MemberName::isValid($name));
        self::assertSame($name, MemberName::from($name)->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function legalNames(): iterable
    {
        yield 'one letter' => ['a'];
        yield 'one digit' => ['0'];
        yield 'a name of digits, which PHP stores under an int key' => ['12'];
        yield 'letters of both cases' => ['aZ'];
        yield 'a word' => ['name'];
        yield 'a hyphen inside' => ['date-listed'];
        yield 'a low line inside' => ['date_listed'];
        yield 'a space inside' => ['date listed'];
        yield 'several of them in a row' => ['a-_ b'];
        yield 'an @-member' => ['@context'];
        yield 'an @-member whose second character may not stand at an end' => ['@-a'];
        yield 'one character above U+007F' => ["\u{0080}"];
        yield 'the highest code point' => ["\u{10FFFF}"];
        yield 'a word of letters above U+007F' => ["\u{00E9}t\u{00E9}"];
        yield 'such a character inside an ASCII name' => ["a\u{2028}b"];
    }

    #[DataProvider('namesWithAnInnerCharacterAtAnEnd')]
    public function testACharacterThatMayNotStandAtAnEndIsRefusedThere(string $name): void
    {
        self::assertFalse(MemberName::isValid($name));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namesWithAnInnerCharacterAtAnEnd(): iterable
    {
        foreach (['hyphen' => '-', 'low line' => '_', 'space' => ' '] as $name => $character) {
            yield $name . ' first' => [$character . 'a'];
            yield $name . ' last' => ['a' . $character];
            yield $name . ' alone' => [$character];
            yield $name . ' last of an @-member' => ['@a' . $character];
        }
    }

    public function testAnEmptyNameIsRefused(): void
    {
        self::assertFalse(MemberName::isValid(''));
    }

    /**
     * `@` alone is refused: a name must end in a globally allowed character
     */
    #[DataProvider('namesWithAnAtSign')]
    public function testAnAtSignIsAllowedAsTheFirstCharacterAlone(string $name, bool $legal): void
    {
        self::assertSame($legal, MemberName::isValid($name));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function namesWithAnAtSign(): iterable
    {
        yield 'first' => ['@context', true];
        yield 'alone' => ['@', false];
        yield 'twice at the start' => ['@@context', false];
        yield 'inside' => ['a@b', false];
        yield 'last' => ['ab@', false];
    }

    #[DataProvider('reservedCharacters')]
    public function testAReservedCharacterIsRefusedWhereverItStands(string $character): void
    {
        self::assertFalse(MemberName::isValid('a' . $character . 'a'));
        self::assertFalse(MemberName::isValid($character . 'aa'));
        self::assertFalse(MemberName::isValid('aa' . $character));
        self::assertFalse(MemberName::isValid($character));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedCharacters(): iterable
    {
        foreach (str_split(self::RESERVED) as $character) {
            yield sprintf('U+%04X', ord($character)) => [$character];
        }

        yield 'U+007F, DELETE' => ["\x7F"];
        yield 'U+0000, a C0 control' => ["\x00"];
        yield 'U+000A, a C0 control' => ["\n"];
        yield 'U+001F, the last C0 control' => ["\x1F"];
    }

    #[DataProvider('charactersBelowU0080')]
    public function testEveryCharacterBelowU0080StandsWhereTheSpecificationAllowsIt(
        string $character,
        string $stands,
    ): void {
        self::assertSame(
            $stands === 'anywhere' || $stands === 'inside',
            MemberName::isValid('a' . $character . 'a'),
            'inside',
        );
        self::assertSame(
            $stands === 'anywhere' || $stands === 'first',
            MemberName::isValid($character . 'aa'),
            'first',
        );
        self::assertSame($stands === 'anywhere', MemberName::isValid('aa' . $character), 'last');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function charactersBelowU0080(): iterable
    {
        $globallyAllowed = array_merge(range('a', 'z'), range('A', 'Z'), range('0', '9'));

        for ($code = 0; $code < 128; ++$code) {
            $character = chr($code);

            yield sprintf('U+%04X', $code) => [
                $character,
                match (true) {
                    in_array($character, $globallyAllowed, true) => 'anywhere',
                    in_array($character, ['-', '_', ' '], true) => 'inside',
                    $character === '@' => 'first',
                    default => 'nowhere',
                },
            ];
        }
    }

    public function testACharacterAboveU007FStandsAnywhere(): void
    {
        self::assertTrue(MemberName::isValid("\u{00E9}"));
        self::assertTrue(MemberName::isValid("\u{00E9}ab"));
        self::assertTrue(MemberName::isValid("ab\u{00E9}"));
        self::assertTrue(MemberName::isValid("a\u{00E9}b"));
    }

    public function testAStringThatIsNotUtf8IsRefused(): void
    {
        self::assertFalse(MemberName::isValid("a\xFFb"));
        self::assertFalse(MemberName::isValid("\xC3"));
    }

    public function testANameWithATrailingLineFeedIsRefused(): void
    {
        self::assertFalse(MemberName::isValid("ab\n"));
    }

    public function testAnExtensionMemberNameIsNoMemberName(): void
    {
        self::assertFalse(MemberName::isValid('atomic:operations'));
        self::assertTrue(MemberName::isValid('operations'));
    }

    public function testFromRefusesAnIllegalNameWithTheRule(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'a member name must hold at least one character, all of them letters, digits or '
            . 'characters above U+007F, with "-", "_" and " " allowed anywhere but at the ends '
            . 'and "@" as the first character: a+b',
        );

        MemberName::from('a+b');
    }

    /**
     * member names are case sensitive, so none is normalized
     */
    public function testTheNameIsKeptAsItIs(): void
    {
        self::assertSame('Date-Published', MemberName::from('Date-Published')->toString());
    }

    public function testStringCastGivesTheSameStringAsToString(): void
    {
        $name = MemberName::from('@context');

        self::assertSame($name->toString(), (string) $name);
    }

    public function testARefusedNameIsWrittenEscaped(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('as the first character: a\\nb');

        MemberName::from("a\nb");
    }
}
