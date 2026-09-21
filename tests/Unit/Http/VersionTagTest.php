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
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\VersionTag;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * @internal
 */
#[CoversClass(VersionTag::class)]
final class VersionTagTest extends TestCase
{
    /**
     * attr-char of RFC 8187 3.2.1, written out rather than derived,
     * so the test holds the class to the grammar and not to its own alphabet
     */
    private const ATTR_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!#$&+-.^_`|~';

    private const ATOMIC = 'https://jsonapi.org/ext/atomic';

    private const CURSOR = 'https://jsonapi.org/profiles/ethanresnick/cursor-pagination';

    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(VersionTag::class))->isReadOnly());
    }

    public function testTheClassIsInternal(): void
    {
        self::assertMatchesRegularExpression(
            '/@internal\b/',
            (string) (new ReflectionClass(VersionTag::class))->getDocComment(),
        );
    }

    public function testThePublicApiIsTheEncoderAndTheDecoder(): void
    {
        $methods = (new ReflectionClass(VersionTag::class))->getMethods(ReflectionMethod::IS_PUBLIC);
        $names = array_map(static function (ReflectionMethod $method): string {
            return $method->getName();
        }, $methods);

        sort($names);

        self::assertSame(['decode', 'encode'], $names);

        foreach ($methods as $method) {
            self::assertTrue($method->isStatic(), $method->getName() . ' is not static');
        }
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
        $reflection = new ReflectionMethod(VersionTag::class, $method);

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
            'the encoder' => [
                'encode',
                true,
                EntityTag::class,
                [['version', 'string'], ['contentLanguage', '?string'], ['contentType', MediaType::class]],
            ],
            'the decoder' => [
                'decode',
                true,
                '?string',
                [['tag', EntityTag::class], ['contentLanguage', '?string'], ['contentType', MediaType::class]],
            ],
        ];
    }

    #[DataProvider('recordedTags')]
    public function testWritesTheVersionAndTheContentLanguageIntoTheTag(
        string $version,
        ?string $contentLanguage,
        string $expected,
    ): void {
        $tag = VersionTag::encode($version, $contentLanguage, MediaType::jsonApi());

        self::assertSame($expected, $tag->value);
        self::assertFalse($tag->weak);
    }

    /**
     * @return array<string, array{string, string|null, string}>
     */
    public static function recordedTags(): array
    {
        return [
            'a number' => ['42', null, '42'],
            'a date with a space and colons' => ['2026-09-16 10:00:00', null, '2026-09-16%2010%3A00%3A00'],
            'a number under a language' => ['17', 'uk', "17'luk"],
            'the empty version under a language' => ['', 'uk', "'luk"],
        ];
    }

    public function testWritesThePartsInTheirOrder(): void
    {
        $contentType = MediaType::jsonApi([self::ATOMIC], [self::CURSOR, 'https://b.example/p']);

        self::assertSame(
            "7'luk"
            . "'ehttps%3A%2F%2Fjsonapi.org%2Fext%2Fatomic"
            . "'phttps%3A%2F%2Fjsonapi.org%2Fprofiles%2Fethanresnick%2Fcursor-pagination"
            . "'phttps%3A%2F%2Fb.example%2Fp",
            VersionTag::encode('7', 'uk', $contentType)->value,
        );
    }

    /**
     * the input mixes attr-chars a URI encoder escapes with octets that are no attr-char,
     * so a URI encoding of the parts cannot pass
     */
    public function testWritesEveryPartWithTheEncodingOfTheVersion(): void
    {
        $raw = 'x!#$&+^`|~:/?=y';
        $pct = 'x!#$&+^`|~%3A%2F%3F%3Dy';
        $contentType = MediaType::jsonApi(['https://a.example/' . $raw], ['https://b.example/' . $raw]);

        self::assertSame(
            $pct
            . "'l" . $pct
            . "'ehttps%3A%2F%2Fa.example%2F" . $pct
            . "'phttps%3A%2F%2Fb.example%2F" . $pct,
            VersionTag::encode($raw, $raw, $contentType)->value,
        );
    }

    public function testWritesEveryExtensionUriAsAPartOfItsOwn(): void
    {
        $contentType = new MediaType(
            'application',
            'vnd.api+json',
            ['ext' => 'https://a.example/x https://b.example/y'],
        );

        self::assertSame(
            "7'ehttps%3A%2F%2Fa.example%2Fx'ehttps%3A%2F%2Fb.example%2Fy",
            VersionTag::encode('7', null, $contentType)->value,
        );
    }

    public function testWritesEveryOctetAsItStandsOrAsAnUpperCaseEscape(): void
    {
        $contentType = MediaType::jsonApi();

        for ($code = 0; $code <= 255; ++$code) {
            $octet = chr($code);
            $expected = str_contains(self::ATTR_CHARS, $octet) ? $octet : sprintf('%%%02X', $code);
            $tag = VersionTag::encode($octet, null, $contentType);

            self::assertSame($expected, $tag->value, sprintf('octet 0x%02X', $code));
            self::assertSame($octet, VersionTag::decode($tag, null, $contentType), sprintf('octet 0x%02X', $code));
            self::assertStringNotContainsString('"', $tag->value);
            self::assertStringNotContainsString('\\', $tag->value);
        }
    }

    public function testAVersionOfAllOctetsComesBack(): void
    {
        $version = '';

        for ($code = 0; $code <= 255; ++$code) {
            $version .= chr($code);
        }

        $contentType = MediaType::jsonApi([self::ATOMIC], [self::CURSOR]);
        $tag = VersionTag::encode($version, 'uk', $contentType);

        self::assertSame($version, VersionTag::decode($tag, 'uk', $contentType));
    }

    #[DataProvider('versionsAndNegotiations')]
    public function testDecodeReadsBackTheVersionEncodeWrote(
        string $version,
        ?string $contentLanguage,
        MediaType $contentType,
    ): void {
        $tag = VersionTag::encode($version, $contentLanguage, $contentType);

        self::assertSame($version, VersionTag::decode($tag, $contentLanguage, $contentType));
        self::assertSame($version, VersionTag::decode(new EntityTag($tag->value), $contentLanguage, $contentType));
    }

    /**
     * @return array<string, array{string, string|null, MediaType}>
     */
    public static function versionsAndNegotiations(): array
    {
        $plain = MediaType::jsonApi();
        $atomic = MediaType::jsonApi([self::ATOMIC]);
        $cursor = MediaType::jsonApi([], [self::CURSOR]);
        $both = MediaType::jsonApi([self::ATOMIC], [self::CURSOR, 'https://b.example/p']);

        return [
            'a space' => ['a b', null, $plain],
            'a double quote' => ['a"b', null, $plain],
            'a backslash' => ['a\\b', null, $plain],
            'a single quote, which separates the parts' => ["a'b", 'uk', $both],
            'the parts written into the version' => ["'luk'e", 'uk', $both],
            'a percent sign' => ['100%', null, $plain],
            'an escape written into the version' => ['%41', null, $plain],
            'obs-text' => ["\x80\xFF", null, $plain],
            'UTF-8' => ["\u{0432}\u{0435}\u{0440}\u{0441}\u{0456}\u{044f}", 'uk', $plain],
            'the empty version' => ['', null, $plain],
            'the empty version under a language, an extension and profiles' => ['', 'uk', $both],
            'a version under a language' => ['v7', 'uk', $plain],
            'a version under a region subtag' => ['v7', 'en-GB', $plain],
            'a version under an extension' => ['v7', null, $atomic],
            'a version under a profile' => ['v7', null, $cursor],
            'a version under everything' => ['2026-09-16 10:00:00', 'uk', $both],
            'a plus sign, an attr-char that URL decoding in forms reads as a space' => ['a+b', null, $plain],
        ];
    }

    public function testDecodeReturnsNullForAWeakTag(): void
    {
        $tag = VersionTag::encode('v7', 'uk', MediaType::jsonApi());

        self::assertNull(VersionTag::decode(new EntityTag($tag->value, true), 'uk', MediaType::jsonApi()));
    }

    #[DataProvider('otherNegotiations')]
    public function testDecodeReturnsNullForAnotherNegotiation(?string $contentLanguage, MediaType $contentType): void
    {
        $tag = VersionTag::encode('v7', 'uk', MediaType::jsonApi([self::ATOMIC], [self::CURSOR]));

        self::assertNull(VersionTag::decode($tag, $contentLanguage, $contentType));
    }

    /**
     * @return array<string, array{string|null, MediaType}>
     */
    public static function otherNegotiations(): array
    {
        $negotiated = MediaType::jsonApi([self::ATOMIC], [self::CURSOR]);

        return [
            'no language' => [null, $negotiated],
            'another language' => ['en', $negotiated],
            'the language in another case' => ['UK', $negotiated],
            'the empty language' => ['', $negotiated],
            'no extension' => ['uk', MediaType::jsonApi([], [self::CURSOR])],
            'another extension' => ['uk', MediaType::jsonApi(['https://a.example/ext'], [self::CURSOR])],
            'one extension more' => ['uk', MediaType::jsonApi([self::ATOMIC, 'https://a.example/ext'], [self::CURSOR])],
            'no profile' => ['uk', MediaType::jsonApi([self::ATOMIC])],
            'one profile more' => ['uk', MediaType::jsonApi([self::ATOMIC], [self::CURSOR, 'https://b.example/p'])],
            'the extension as a profile' => ['uk', MediaType::jsonApi([], [self::ATOMIC, self::CURSOR])],
            'nothing applied' => ['uk', MediaType::jsonApi()],
        ];
    }

    #[DataProvider('nonCanonicalSpellings')]
    public function testDecodeReturnsNullForASpellingEncodeDoesNotWrite(string $opaque, ?string $contentLanguage): void
    {
        self::assertNull(VersionTag::decode(new EntityTag($opaque), $contentLanguage, MediaType::jsonApi()));
    }

    /**
     * the canonical spelling of each case is noted above it
     *
     * @return array<string, array{string, string|null}>
     */
    public static function nonCanonicalSpellings(): array
    {
        return [
            // 2026-09-16%2010%3A00%3A00
            'lower-case hexadecimal digits' => ['2026-09-16%2010%3a00%3a00', null],
            // 42
            'an escaped attr-char' => ['%342', null],
            // a%3Ab
            'a raw octet that is no attr-char' => ['a:b', null],
            // a%2Ab
            'a raw asterisk, a token character that is no attr-char' => ['a*b', null],
            // a%25
            'a raw percent sign' => ['a%', null],
            // a%252
            'a truncated escape' => ['a%2', null],
            // %25G1
            'an escape of no hexadecimal digits' => ['%G1', null],
            // 17'luk
            'the language part in another spelling' => ["17'l%75k", 'uk'],
            // 17'luk
            'a part that encode() does not write' => ["17'luk'x", 'uk'],
            // 17'luk
            'the language part twice' => ["17'luk'luk", 'uk'],
            // 17
            'a separator with no part behind it' => ["17'", null],
        ];
    }

    /**
     * each input is built to collide with another under a careless joining of the parts
     */
    public function testNoTwoInputsShareATag(): void
    {
        $inputs = [
            ['a', null, MediaType::jsonApi()],
            ['a', '', MediaType::jsonApi()],
            ["a'l", null, MediaType::jsonApi()],
            ['a', 'l', MediaType::jsonApi()],
            ["a'", 'l', MediaType::jsonApi()],
            ['a%27l', null, MediaType::jsonApi()],
            ['', 'a', MediaType::jsonApi()],
            ["'la", null, MediaType::jsonApi()],
            ['ab', 'c', MediaType::jsonApi()],
            ['a', 'bc', MediaType::jsonApi()],
            ['a', null, MediaType::jsonApi(['x'])],
            ['a', null, MediaType::jsonApi([], ['x'])],
            ['a', null, MediaType::jsonApi(['x', 'y'])],
            ['a', null, MediaType::jsonApi(['y', 'x'])],
            ['a', null, MediaType::jsonApi(['xy'])],
            ['a', null, MediaType::jsonApi(['x'], ['y'])],
            ['a', null, MediaType::jsonApi(['y'], ['x'])],
            ['a', null, MediaType::jsonApi([], ['x', 'y'])],
            ["a'ex", null, MediaType::jsonApi()],
            ['a', "x'ex", MediaType::jsonApi()],
            ['a', 'x', MediaType::jsonApi(['x'])],
        ];

        $tags = array_map(
            static function (array $input): string {
                return VersionTag::encode($input[0], $input[1], $input[2])->value;
            },
            $inputs,
        );

        self::assertSame($tags, array_values(array_unique($tags)));
    }

    public function testTheTagCoversTheAppliedUrisAndNothingElseOfTheContentType(): void
    {
        $built = MediaType::jsonApi([self::ATOMIC], [self::CURSOR]);
        $parsed = MediaType::parse(
            'application/vnd.api+json; profile="' . self::CURSOR . '"; ext="' . self::ATOMIC . '"',
        );

        self::assertNotNull($parsed);
        self::assertSame(VersionTag::encode('v7', 'uk', $built)->value, VersionTag::encode('v7', 'uk', $parsed)->value);
    }
}
