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

namespace Polunich\WpJsonApi\Tests\Unit\Query\Pagination;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Query\Pagination\Cursor;
use Polunich\WpJsonApi\Query\Pagination\CursorText;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(CursorText::class)]
final class CursorTextTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(CursorText::class))->isReadOnly());
    }

    #[DataProvider('cursors')]
    public function testACursorItWroteComesBackAsItWas(Cursor $cursor): void
    {
        $decoded = CursorText::decode(CursorText::encode($cursor));

        self::assertEquals($cursor, $decoded);
        self::assertSame($cursor->sortValues, $decoded->sortValues);
        self::assertSame($cursor->id, $decoded->id);
    }

    public function testAnIntegralFloatComesBackAsAFloat(): void
    {
        $decoded = CursorText::decode(CursorText::encode(new Cursor(['rank' => 2.0, 'seats' => 2], '42')));

        self::assertSame(2.0, $decoded->sortValues['rank'] ?? null);
        self::assertSame(2, $decoded->sortValues['seats'] ?? null);
    }

    /**
     * @return iterable<string, array{Cursor}>
     */
    public static function cursors(): iterable
    {
        yield 'one sort field' => [new Cursor(['created' => '2026-01-01T10:00:00Z'], '42')];
        yield 'several sort fields' => [
            new Cursor(['created' => '2026-01-01', 'name' => 'a', 'rank' => 7], '42'),
        ];
        yield 'no sort field but the id' => [new Cursor([], '42')];
        yield 'a field of a related resource' => [new Cursor(['owner.name' => 'Ada'], '42')];
        yield 'a member name of digits' => [new Cursor(['0' => 7], '42')];
        yield 'a number' => [new Cursor(['rank' => 1.5], '42')];
        yield 'an integral number' => [new Cursor(['rank' => 2.0], '42')];
        yield 'a negative zero' => [new Cursor(['rank' => -0.0], '42')];
        yield 'an integral number and an integer of the same value' => [new Cursor(
            ['rank' => 2.0, 'seats' => 2],
            '42',
        )];
        yield 'a boolean' => [new Cursor(['featured' => true], '42')];
        yield 'the empty string' => [new Cursor(['name' => ''], '42')];
        yield 'text outside ASCII' => [new Cursor(['name' => "\u{041a}\u{0438}\u{0457}\u{0432}"], '42')];
        yield 'a string that reads as a number' => [new Cursor(['rank' => '7'], '42')];
        yield 'an id that reads as a number' => [new Cursor(['rank' => 7], '0')];
        yield 'a value with a slash and a quote' => [new Cursor(['path' => '/a/"b"'], '42')];
    }

    #[DataProvider('cursors')]
    public function testWritesBase64UrlWithoutPadding(Cursor $cursor): void
    {
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/D', CursorText::encode($cursor));
    }

    /**
     * a cursor holding these three characters is base64 with both `+` and `/`
     */
    public function testWritesTheTwoCharactersOfBase64UrlAndNotTheOnesOfBase64(): void
    {
        $cursor = new Cursor(['t' => "\u{0080}\u{0f80}\u{01bf}"], '42');

        $text = CursorText::encode($cursor);

        self::assertStringContainsString('-', $text);
        self::assertStringContainsString('_', $text);
        self::assertStringNotContainsString('+', $text);
        self::assertStringNotContainsString('/', $text);
        self::assertEquals($cursor, CursorText::decode($text));
    }

    public function testWritesOneCursorOneWay(): void
    {
        $first = new Cursor(['created' => '2026-01-01', 'rank' => 7], '42');
        $second = new Cursor(['created' => '2026-01-01', 'rank' => 7], '42');

        self::assertSame(CursorText::encode($first), CursorText::encode($second));
    }

    /**
     * the wire form is a compatibility surface, since a client keeps its cursors across an upgrade;
     * the texts are therefore literals, not computed by the class
     */
    #[DataProvider('wireForms')]
    public function testWritesTheWireFormOfACursor(Cursor $cursor, string $text): void
    {
        self::assertSame($text, CursorText::encode($cursor));
    }

    #[DataProvider('wireForms')]
    public function testReadsTheWireFormOfACursor(Cursor $cursor, string $text): void
    {
        $decoded = CursorText::decode($text);

        self::assertSame($cursor->sortValues, $decoded->sortValues);
        self::assertSame($cursor->id, $decoded->id);
    }

    /**
     * the texts decode to `{"sort":{"created":"2026-01-01"},"id":"42"}`;
     * `{"sort":{"owner.city":"...","path":"/a/b","rank":7,"score":1.5,"featured":false},"id":"7"}`
     * with the city in raw UTF-8 and the slash unescaped;
     * and `{"sort":{"rank":2.0,"score":-0.0},"id":"42"}`, whose fractions keep both values floats
     *
     * @return iterable<string, array{Cursor, string}>
     */
    public static function wireForms(): iterable
    {
        yield 'one sort field' => [
            new Cursor(['created' => '2026-01-01'], '42'),
            'eyJzb3J0Ijp7ImNyZWF0ZWQiOiIyMDI2LTAxLTAxIn0sImlkIjoiNDIifQ',
        ];
        yield 'a path, a slash, text outside ASCII and every kind of scalar' => [
            new Cursor(
                [
                    'owner.city' => "\u{041a}\u{0438}\u{0457}\u{0432}",
                    'path' => '/a/b',
                    'rank' => 7,
                    'score' => 1.5,
                    'featured' => false,
                ],
                '7',
            ),
            'eyJzb3J0Ijp7Im93bmVyLmNpdHkiOiLQmtC40ZfQsiIsInBhdGgiOiIvYS9iIiwicmFuayI6Nywic2NvcmUiOjEuNSwiZmVhdHVyZW'
            . 'QiOmZhbHNlfSwiaWQiOiI3In0',
        ];
        yield 'an integral number and a negative zero, with their fractions' => [
            new Cursor(['rank' => 2.0, 'score' => -0.0], '42'),
            'eyJzb3J0Ijp7InJhbmsiOjIuMCwic2NvcmUiOi0wLjB9LCJpZCI6IjQyIn0',
        ];
    }

    #[DataProvider('foreignText')]
    public function testRefusesTextItDidNotWrite(string $text): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the text is no cursor of this API, because ');

        CursorText::decode($text);
    }

    /**
     * each form is built from a text `encode()` wrote, so it carries the bytes of a real cursor
     * and only its spelling is foreign
     *
     * @return iterable<string, array{string}>
     */
    public static function foreignText(): iterable
    {
        $written = self::written();
        $withBothCharacters = self::writtenWithBothCharacters();

        yield 'empty' => [''];
        yield 'truncated' => [substr($written, 0, -3)];
        yield 'one character removed' => [substr($written, 0, -1)];
        yield 'a character of base64 appended' => [$written . '+'];
        yield 'a single pad character' => [$written . '='];
        yield 'padded as base64 pads it' => [self::padded($written)];
        yield 'written with the two characters of base64' => [strtr($withBothCharacters, '-_', '+/')];
        yield 'written as base64_encode() writes it, padding included' => [
            self::padded(strtr($withBothCharacters, '-_', '+/')),
        ];
        yield 'a last character whose unused bits are not zero' => [
            substr($written, 0, -1) . strtr(
                substr($written, -1),
                'ACEGIKMOQSUWYacegikmoqsuwy02468-',
                'BDFHJLNPRTVXZbdfhjlnprtvxz13579_',
            ),
        ];
        yield 'a character outside the alphabet' => [$written . '.'];
        yield 'a space' => [$written . ' '];
        yield 'a space inside' => [substr($written, 0, 8) . ' ' . substr($written, 8)];
        yield 'a line feed at the end' => [$written . "\n"];
        yield 'a line break inside' => [substr($written, 0, 8) . "\r\n" . substr($written, 8)];
        yield 'a tab inside' => [substr($written, 0, 8) . "\t" . substr($written, 8)];
        yield 'no base64 at all' => ['@'];
        yield 'base64 of bytes that are no JSON' => [self::base64url('not json')];
        yield 'JSON that is no object' => [self::base64url('"cursor"')];
        yield 'a JSON array' => [self::base64url('[{"created":"2026-01-01"},"42"]')];
        yield 'JSON null' => [self::base64url('null')];
        yield 'without the sort values' => [self::base64url('{"id":"42"}')];
        yield 'without the id' => [self::base64url('{"sort":{"created":"2026-01-01"}}')];
        yield 'with a member it does not write' => [
            self::base64url('{"sort":{"created":"2026-01-01"},"id":"42","direction":"after"}'),
        ];
        yield 'with the sort values under another name' => [
            self::base64url('{"values":{"created":"2026-01-01"},"id":"42"}'),
        ];
        yield 'with the id under another name' => [
            self::base64url('{"sort":{"created":"2026-01-01"},"key":"42"}'),
        ];
        yield 'the sort values as an array' => [self::base64url('{"sort":["2026-01-01"],"id":"42"}')];
        yield 'the sort values as a string' => [self::base64url('{"sort":"created","id":"42"}')];
        yield 'the id as a number' => [self::base64url('{"sort":{},"id":42}')];
        yield 'the id as null' => [self::base64url('{"sort":{},"id":null}')];
        yield 'an empty id' => [self::base64url('{"sort":{},"id":""}')];
        yield 'a sort value keyed by nothing' => [self::base64url('{"sort":{"":"a"},"id":"42"}')];
        yield 'a sort value keyed by no field path' => [
            self::base64url('{"sort":{"not a field path!!":1},"id":"42"}'),
        ];
        yield 'a sort value keyed by a path with an empty segment' => [
            self::base64url('{"sort":{"owner..name":1},"id":"42"}'),
        ];
        yield 'a sort value that is no finite number' => [self::base64url('{"sort":{"rank":1e999},"id":"42"}')];
        yield 'a sort value that is null' => [self::base64url('{"sort":{"created":null},"id":"42"}')];
        yield 'a sort value that is an object' => [
            self::base64url('{"sort":{"created":{"date":"2026-01-01"}},"id":"42"}'),
        ];
        yield 'a sort value that is an array' => [self::base64url('{"sort":{"created":[1]},"id":"42"}')];
    }

    /**
     * a text whose length is a multiple of four has no padding to add and no unused bits to set,
     * so the forms above are foreign only while their source texts are of another length
     */
    public function testTheTextsTheFormsOfTheEncodingStartFromHaveUnusedBits(): void
    {
        self::assertNotSame(0, strlen(self::written()) % 4);
        self::assertNotSame(0, strlen(self::writtenWithBothCharacters()) % 4);
    }

    /**
     * `1e999` decodes to `INF`, which `Cursor` refuses
     */
    public function testKeepsTheReasonTheCursorGaveAsThePreviousException(): void
    {
        try {
            CursorText::decode(self::base64url('{"sort":{"rank":1e999},"id":"42"}'));
        } catch (InvalidArgumentException $exception) {
            $previous = $exception->getPrevious();

            self::assertInstanceOf(InvalidArgumentException::class, $previous);
            self::assertStringContainsString('is not a finite number', $previous->getMessage());

            return;
        }

        self::fail('a sort value that is no finite number was read as a cursor');
    }

    public function testRefusesASortValueThatNoJsonTextCanCarry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be written as JSON text');

        CursorText::encode(new Cursor(['name' => "\xb1\x31"], '42'));
    }

    private static function written(): string
    {
        return CursorText::encode(new Cursor(['created' => '2026-01-01'], '42'));
    }

    /**
     * its text holds both `-` and `_`, which base64 spells `+` and `/`
     */
    private static function writtenWithBothCharacters(): string
    {
        return CursorText::encode(new Cursor(['t' => "\u{0080}\u{0f80}\u{01bf}"], '42'));
    }

    private static function base64url(string $json): string
    {
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    private static function padded(string $text): string
    {
        return $text . str_repeat('=', (4 - strlen($text) % 4) % 4);
    }
}
