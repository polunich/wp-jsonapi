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

namespace Polunich\WpJsonApi\Tests\Unit\Routing;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\DigitsCodec;
use Polunich\WpJsonApi\Codec\Schema\JsonType;
use Polunich\WpJsonApi\Codec\Schema\Schema;
use Polunich\WpJsonApi\Codec\SegmentCodec;
use Polunich\WpJsonApi\Codec\UuidCodec;
use Polunich\WpJsonApi\Routing\SegmentPattern;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\DescribedCodec;

/**
 * @internal
 */
#[CoversClass(SegmentPattern::class)]
final class SegmentPatternTest extends TestCase
{
    #[DataProvider('codecsThatFollowTheRules')]
    public function testAPatternThatFollowsTheRulesPasses(SegmentCodec $codec): void
    {
        $this->expectNotToPerformAssertions();

        SegmentPattern::check($codec, 'the id of type "pets"');
    }

    /**
     * @return iterable<string, array{SegmentCodec}>
     */
    public static function codecsThatFollowTheRules(): iterable
    {
        yield 'the digits codec' => [new DigitsCodec()];
        yield 'the UUID codec' => [new UuidCodec()];
        yield 'any segment' => [self::codec('[^/]+')];
        yield 'a non-capturing group' => [self::codec('(?:ab)+')];
        yield 'a percent-encoded octet' => [self::codec('(?:%[0-9A-F]{2}|[a-z])+')];
        yield 'an escaped at sign' => [self::codec('[a-z]+\\@[a-z]+')];
        yield 'an at sign as a hex escape' => [self::codec('[a-z]+\\x40[a-z]+')];
    }

    /**
     * @param non-empty-string $pattern
     */
    #[DataProvider('patternsThatDoNotCompile')]
    public function testAPatternThatDoesNotCompileAsOneSegmentIsRefused(string $pattern): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'the id of type "pets" is read by %s, whose segment pattern "%s" is no pattern PCRE compiles as one'
            . ' path segment',
            DescribedCodec::class,
            $pattern,
        ));

        SegmentPattern::check(self::codec($pattern), 'the id of type "pets"');
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function patternsThatDoNotCompile(): iterable
    {
        yield 'an open group' => ['('];
        yield 'a trailing backslash' => ['a\\'];
        yield 'a pattern that closes the group it is put in' => ['a)|(b'];
        yield 'a verb that compiles only at the start of a pattern' => ['(*UTF)a'];
    }

    /**
     * @param non-empty-string $pattern
     */
    #[DataProvider('patternsThatMatchTheEmptySegment')]
    public function testAPatternThatMatchesTheEmptySegmentIsRefused(string $pattern): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'whose segment pattern "%s" matches the empty segment, and a path parameter is never empty',
            $pattern,
        ));

        SegmentPattern::check(self::codec($pattern), 'the id of type "pets"');
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function patternsThatMatchTheEmptySegment(): iterable
    {
        yield 'a repetition that may be empty' => ['[0-9]*'];
        yield 'an empty alternative' => ['a|'];
        yield 'an optional group' => ['(?:ab)?'];
    }

    /**
     * @param non-empty-string $pattern
     */
    #[DataProvider('patternsWithANamedGroup')]
    public function testAPatternWithANamedGroupIsRefused(string $pattern, string $group): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'whose segment pattern "%s" holds the named group "%s", and WordPress hands every named group of a'
            . ' route over as a route parameter',
            $pattern,
            $group,
        ));

        SegmentPattern::check(self::codec($pattern), 'the id of type "pets"');
    }

    /**
     * @return iterable<string, array{non-empty-string, string}>
     */
    public static function patternsWithANamedGroup(): iterable
    {
        yield 'the Python syntax' => ['(?P<id>[0-9]+)', 'id'];
        yield 'the Perl syntax with angle brackets' => ['(?<id>[0-9]+)', 'id'];
        yield 'the Perl syntax with quotes' => ["(?'id'[0-9]+)", 'id'];
        yield 'a group in an alternative that does not match' => ['[0-9]+|(?<word>[a-z]+)', 'word'];
        yield 'a group defined for reuse' => ['(?(DEFINE)(?<digit>[0-9]))(?&digit)+', 'digit'];
    }

    /**
     * @param non-empty-string $pattern
     */
    #[DataProvider('patternsWithAnUnescapedAtSign')]
    public function testAPatternWithAnAtSignWithoutABackslashIsRefused(string $pattern): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'whose segment pattern "%s" holds an "@" without a backslash, and WordPress delimits a route pattern'
            . ' with "@", so the route pattern would end there: write "\\@" or "\\x40"',
            $pattern,
        ));

        SegmentPattern::check(self::codec($pattern), 'the id of type "pets"');
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function patternsWithAnUnescapedAtSign(): iterable
    {
        yield 'a literal' => ['[a-z]+@[a-z]+'];
        yield 'in a character class' => ['[^@/]+'];
        yield 'quoted' => ['\\Q@\\E'];
        yield 'after an escaped backslash' => ['a\\\\@b'];
    }

    /**
     * @param non-empty-string $pattern
     */
    #[DataProvider('patternsWithACapturingGroup')]
    public function testAPatternWithACapturingGroupIsRefused(string $pattern): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'whose segment pattern "%s" holds a capturing group, and inside a route pattern a group is numbered'
            . ' after the groups before it, so a reference to it would name another group: write a'
            . ' non-capturing group "(?:...)"',
            $pattern,
        ));

        SegmentPattern::check(self::codec($pattern), 'the id of type "pets"');
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function patternsWithACapturingGroup(): iterable
    {
        yield 'a group nothing refers to' => ['(ab)+'];
        yield 'a group a reference names' => ['([a-z])\\1'];
        yield 'a group in an alternative that does not match' => ['[0-9]+|([a-z]+)'];
        yield 'a group in a branch reset' => ['(?|(a)|(b))'];
    }

    public function testAReferenceToAGroupThePatternDoesNotHoldDoesNotCompile(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('whose segment pattern "(?:[a-z])\\1" is no pattern PCRE compiles');

        SegmentPattern::check(self::codec('(?:[a-z])\\1'), 'the id of type "pets"');
    }

    public function testAControlCharacterOfAPatternIsEscapedInTheMessage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('whose segment pattern "a\n|" matches the empty segment');

        SegmentPattern::check(self::codec("a\n|"), 'the id of type "pets"');
    }

    /**
     * @param non-empty-string $pattern
     */
    private static function codec(string $pattern): SegmentCodec
    {
        return new DescribedCodec(new Schema(type: JsonType::String), $pattern);
    }
}
