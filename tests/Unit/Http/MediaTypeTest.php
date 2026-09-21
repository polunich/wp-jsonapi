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

use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Http\MediaType;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(MediaType::class)]
final class MediaTypeTest extends TestCase
{
    public function testTheClassIsInternal(): void
    {
        self::assertMatchesRegularExpression(
            '/@internal\b/',
            (string) (new ReflectionClass(MediaType::class))->getDocComment(),
        );
    }

    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(MediaType::class))->isReadOnly());
    }

    public function testTheJsonApiConstantIsTheRegisteredMediaType(): void
    {
        self::assertSame('application/vnd.api+json', MediaType::JSON_API);
    }

    /**
     * the atomic extension states: "This extension has the URI https://jsonapi.org/ext/atomic"
     */
    public function testTheAtomicExtensionConstantIsTheUriOfTheExtension(): void
    {
        self::assertSame('https://jsonapi.org/ext/atomic', MediaType::ATOMIC_EXTENSION);
    }

    public function testNamesAreLoweredAndValuesKeepTheirCase(): void
    {
        $mediaType = new MediaType('APPLICATION', 'VND.Api+JSON', ['Charset' => 'UTF-8']);

        self::assertSame('application', $mediaType->type);
        self::assertSame('vnd.api+json', $mediaType->subtype);
        self::assertSame(['charset' => 'UTF-8'], $mediaType->parameters);
    }

    public function testAParameterNameOfDecimalDigitsIsAccepted(): void
    {
        $mediaType = new MediaType('application', 'vnd.api+json', ['8' => 'x']);

        self::assertSame([8 => 'x'], $mediaType->parameters);
        self::assertSame('application/vnd.api+json;8=x', $mediaType->toHeaderValue());
    }

    /**
     * a client may send such a name, and it has to reach negotiation, not end in a `TypeError`
     */
    #[DataProvider('valuesWithADigitParameterName')]
    public function testAParameterNameOfDecimalDigitsIsParsed(string $value, string $expected): void
    {
        self::assertSame($expected, MediaType::parse($value)?->toHeaderValue());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function valuesWithADigitParameterName(): array
    {
        return [
            'a digit name with a token value' => [
                'application/vnd.api+json;8=x',
                'application/vnd.api+json;8=x',
            ],
            'a digit name with a digit value' => [
                'application/vnd.api+json;1=2',
                'application/vnd.api+json;1=2',
            ],
        ];
    }

    /**
     * @param array<array-key, string> $parameters
     */
    #[DataProvider('constructorRefusals')]
    public function testTheConstructorRefusesAValueNoMediaTypeMayHold(
        string $type,
        string $subtype,
        array $parameters,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        new MediaType($type, $subtype, $parameters);
    }

    /**
     * @return array<string, array{string, string, array<array-key, string>}>
     */
    public static function constructorRefusals(): array
    {
        return [
            'an empty type' => ['', 'json', []],
            'an empty subtype' => ['application', '', []],
            'a type that is no token' => ['app lication', 'json', []],
            'a subtype that is no token' => ['application', 'vnd/api', []],
            'a parameter name that is no token' => ['application', 'json', ['a b' => 'x']],
            'an empty parameter name' => ['application', 'json', ['' => 'x']],
            'a value no header field can carry' => ['application', 'json', ['a' => "x\ny"]],
            'the same parameter name twice' => ['application', 'json', ['Ext' => 'a', 'ext' => 'b']],
            'a parameter named q' => ['application', 'vnd.api+json', ['q' => '0.5']],
            'a parameter named q in upper case' => ['application', 'vnd.api+json', ['Q' => '0.5']],
        ];
    }

    public function testTheConstructorRefusesAParameterNamedQ(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('q is the weight of a media range and no media type parameter');

        new MediaType('application', 'vnd.api+json', ['q' => '0.5']);
    }

    #[DataProvider('weightsThatAreNoQvalue')]
    public function testTheConstructorRefusesAWeightThatIsNoQvalue(float $weight): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MediaType('application', 'vnd.api+json', [], $weight);
    }

    /**
     * @return array<string, array{float}>
     */
    public static function weightsThatAreNoQvalue(): array
    {
        return [
            'above one' => [1.5],
            'below zero' => [-0.1],
            'four digits after the decimal point' => [0.12345],
            'not a number' => [\NAN],
        ];
    }

    #[DataProvider('qvalues')]
    public function testAQvalueIsKeptAsItIs(float $weight, string $expected): void
    {
        $mediaType = new MediaType('application', 'vnd.api+json', [], $weight);

        self::assertSame($weight, $mediaType->weight);
        self::assertSame($expected, $mediaType->toHeaderValue());
    }

    /**
     * @return array<string, array{float, string}>
     */
    public static function qvalues(): array
    {
        return [
            'not acceptable' => [0.0, 'application/vnd.api+json;q=0'],
            'the smallest weight' => [0.001, 'application/vnd.api+json;q=0.001'],
            'a weight of two digits' => [0.25, 'application/vnd.api+json;q=0.25'],
            'the default weight' => [1.0, 'application/vnd.api+json'],
        ];
    }

    public function testAMediaTypeThatWasNeverWeightedCarriesTheDefaultWeight(): void
    {
        self::assertSame(1.0, (new MediaType('application', 'vnd.api+json'))->weight);
        self::assertSame(1.0, MediaType::parse('application/vnd.api+json')?->weight);
        self::assertSame(1.0, MediaType::jsonApi()->weight);
    }

    public function testAParameterStandingBehindTheWeightBelongsToTheMediaRange(): void
    {
        $list = MediaType::parseList('application/vnd.api+json;q=0.5;ext="https://jsonapi.org/ext/atomic"');

        self::assertNotNull($list);
        self::assertCount(1, $list);
        self::assertSame(['ext'], array_keys($list[0]->parameters));
        self::assertSame(0.5, $list[0]->weight);
        self::assertSame(['https://jsonapi.org/ext/atomic'], $list[0]->ext());
        self::assertSame(
            'application/vnd.api+json;ext="https://jsonapi.org/ext/atomic";q=0.5',
            $list[0]->toHeaderValue(),
        );
    }

    /**
     * the loop applies the JSON:API refusal of any parameter other than ext or profile:
     * `q` is the weight, no parameter, so a weighted instance passes it
     */
    public function testAWeightedInstanceOfTheJsonApiMediaTypeCarriesNoForeignParameter(): void
    {
        $list = MediaType::parseList('application/vnd.api+json;q=0.8');

        self::assertNotNull($list);
        self::assertCount(1, $list);

        $instance = $list[0];
        $foreign = [];

        foreach (array_keys($instance->parameters) as $name) {
            if ((string) $name !== MediaType::EXT && (string) $name !== MediaType::PROFILE) {
                $foreign[] = (string) $name;
            }
        }

        self::assertSame([], $foreign);
        self::assertTrue($instance->isJsonApi());
        self::assertSame([], $instance->parameters);
        self::assertSame(0.8, $instance->weight);
    }

    public function testParseRefusesAWeight(): void
    {
        self::assertNull(MediaType::parse('application/vnd.api+json;q=0.8'));
        self::assertNull(MediaType::parse('application/vnd.api+json;Q=0.8'));
        self::assertNull(MediaType::parse('application/vnd.api+json;ext="https://a.example/x";q=1'));
    }

    #[DataProvider('malformedWeights')]
    public function testParseListRefusesAWeightThatIsNoQvalue(string $fieldValue): void
    {
        self::assertNull(MediaType::parseList($fieldValue));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedWeights(): array
    {
        return [
            'above one' => ['text/html;q=2'],
            'above one with digits' => ['text/html;q=1.5'],
            'four digits after the decimal point' => ['text/html;q=0.1234'],
            'no number at all' => ['text/html;q=abc'],
            'an empty weight' => ['text/html;q=""'],
            'a leading point' => ['text/html;q=.5'],
            'the same weight twice' => ['text/html;q=0.5;Q=0.2'],
        ];
    }

    /**
     * a line feed never reaches `FieldValueGrammar::weight()` from here:
     * a token cannot hold one and a quoted-string refuses it.
     * `FieldValueGrammarTest` pins `weight()`
     */
    #[DataProvider('qvaluesWithATrailingLineFeed')]
    public function testAQValueWithATrailingLineFeedIsNoWeight(string $value): void
    {
        $list = MediaType::parseList('text/html;q=' . $value);

        self::assertNotNull($list);
        self::assertSame((float) $value, $list[0]->weight);
        self::assertNull(MediaType::parseList('text/html;q="' . $value . "\n" . '"'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function qvaluesWithATrailingLineFeed(): array
    {
        return [
            'a weight with digits' => ['0.5'],
            'not acceptable' => ['0'],
            'the highest weight' => ['1.000'],
        ];
    }

    #[DataProvider('parsedWeights')]
    public function testParseListReadsTheWeightOfAnElement(string $fieldValue, float $expected): void
    {
        $list = MediaType::parseList($fieldValue);

        self::assertNotNull($list);
        self::assertCount(1, $list);
        self::assertSame($expected, $list[0]->weight);
        self::assertSame([], $list[0]->parameters);
    }

    /**
     * @return array<string, array{string, float}>
     */
    public static function parsedWeights(): array
    {
        return [
            'not acceptable' => ['text/html;q=0', 0.0],
            'not acceptable with digits' => ['text/html;q=0.000', 0.0],
            'three digits' => ['text/html;q=0.001', 0.001],
            'one digit' => ['text/html;q=0.8', 0.8],
            'the highest weight' => ['text/html;q=1', 1.0],
            'the highest weight with digits' => ['text/html;q=1.000', 1.0],
            'a point without digits, which 0*3DIGIT allows' => ['text/html;q=0.', 0.0],
            'no weight at all' => ['text/html', 1.0],
        ];
    }

    #[DataProvider('parsedValues')]
    public function testParseReadsAMediaType(string $value, string $expected): void
    {
        self::assertSame($expected, MediaType::parse($value)?->toHeaderValue());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function parsedValues(): array
    {
        return [
            'a bare media type' => ['application/json', 'application/json'],
            'surrounding whitespace' => ["  application/json\t", 'application/json'],
            'whitespace around the semicolon' => ['application/json ; a=1', 'application/json;a=1'],
            'a quoted parameter value' => ['application/json;a="x y"', 'application/json;a="x y"'],
            'a quoted pair' => ['application/json;a="x\\"y"', 'application/json;a="x\\"y"'],
            'an escaped backslash' => ['application/json;a="x\\\\y"', 'application/json;a="x\\\\y"'],
            'an empty parameter' => ['application/json;;a=1', 'application/json;a=1'],
            'a trailing semicolon' => ['application/json;', 'application/json'],
            'the JSON:API type with ext' => [
                'application/vnd.api+json;ext="https://jsonapi.org/ext/atomic"',
                'application/vnd.api+json;ext="https://jsonapi.org/ext/atomic"',
            ],
        ];
    }

    #[DataProvider('malformedValues')]
    public function testParseRefusesAMalformedValue(string $value): void
    {
        self::assertNull(MediaType::parse($value));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedValues(): array
    {
        return [
            'an empty value' => [''],
            'no slash' => ['application'],
            'no subtype' => ['application/'],
            'no type' => ['/json'],
            'a space inside the type' => ['app lication/json'],
            'a parameter without a value' => ['application/json;a'],
            'a parameter without a name' => ['application/json;=1'],
            'whitespace around the equals sign' => ['application/json;a = 1'],
            'an unterminated quoted value' => ['application/json;a="x'],
            'a dangling escape' => ['application/json;a="x\\'],
            'trailing rubbish' => ['application/json x'],
            'the same parameter name twice' => ['application/json;a=1;A=2'],
        ];
    }

    public function testParseRefusesAValueThatCarriesMoreThanOneMediaRange(): void
    {
        self::assertNull(MediaType::parse('application/vnd.api+json, application/json'));
    }

    /**
     * the example of RFC 9110 12.5.1
     */
    public function testParseListReadsTheRangesInOrder(): void
    {
        $list = MediaType::parseList('text/plain; q=0.5, text/html, text/x-dvi; q=0.8, text/x-c');

        self::assertNotNull($list);
        self::assertSame(
            ['text/plain;q=0.5', 'text/html', 'text/x-dvi;q=0.8', 'text/x-c'],
            self::rendered($list),
        );
        self::assertSame(
            [0.5, 1.0, 0.8, 1.0],
            array_map(static function (MediaType $mediaType): float {
                return $mediaType->weight;
            }, $list),
        );
        self::assertSame([[], [], [], []], array_map(
            static function (MediaType $mediaType): array {
                return $mediaType->parameters;
            },
            $list,
        ));
    }

    public function testParseListKeepsACommaInsideAQuotedParameterValue(): void
    {
        $list = MediaType::parseList('application/vnd.api+json;ext="https://a.example/x,y", application/json');

        self::assertNotNull($list);
        self::assertCount(2, $list);
        self::assertSame(['https://a.example/x,y'], $list[0]->ext());
        self::assertSame('application/json', $list[1]->toHeaderValue());
    }

    /**
     * JSON:API lets a client list the media type several times, with other ext and profile values
     */
    public function testParseListKeepsEveryInstanceOfTheJsonApiMediaType(): void
    {
        $list = MediaType::parseList(
            'application/vnd.api+json;ext="https://jsonapi.org/ext/atomic",'
            . ' application/vnd.api+json;charset=utf-8,'
            . ' application/vnd.api+json',
        );

        self::assertNotNull($list);
        self::assertCount(3, $list);
        self::assertSame(['ext'], array_keys($list[0]->parameters));
        self::assertSame(['charset'], array_keys($list[1]->parameters));
        self::assertSame([], $list[2]->parameters);
    }

    public function testParseListSkipsEmptyElements(): void
    {
        $list = MediaType::parseList(',, text/html ,, text/plain ,');

        self::assertNotNull($list);
        self::assertSame(['text/html', 'text/plain'], self::rendered($list));
    }

    public function testParseListReadsAnEmptyFieldValueAsNoRange(): void
    {
        self::assertSame([], MediaType::parseList('   '));
    }

    #[DataProvider('malformedLists')]
    public function testParseListRefusesAMalformedValue(string $fieldValue): void
    {
        self::assertNull(MediaType::parseList($fieldValue));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedLists(): array
    {
        return [
            'a malformed element' => ['text/html, application/'],
            'a missing comma' => ['text/html text/plain'],
            'a missing comma behind a parameter' => ['text/html;a=1 text/plain'],
            'a missing comma behind a quoted parameter value' => ['text/html;a="x" text/plain'],
            'a missing comma behind whitespace' => ["text/html ; a=1 \t text/plain"],
            'an unterminated quoted value' => ['text/html;a="x, text/plain'],
            'a parameter without a value' => ['text/html;a, text/plain'],
        ];
    }

    public function testJsonApiRendersTheExtAndProfileLists(): void
    {
        $mediaType = MediaType::jsonApi(
            ['https://jsonapi.org/ext/atomic'],
            ['https://jsonapi.org/profiles/ethanresnick/cursor-pagination', 'https://b.example/p'],
        );

        self::assertSame(
            'application/vnd.api+json'
            . ';ext="https://jsonapi.org/ext/atomic"'
            . ';profile="https://jsonapi.org/profiles/ethanresnick/cursor-pagination https://b.example/p"',
            $mediaType->toHeaderValue(),
        );
    }

    public function testJsonApiWithoutExtAndProfileCarriesNoParameter(): void
    {
        $mediaType = MediaType::jsonApi();

        self::assertSame([], $mediaType->parameters);
        self::assertSame('application/vnd.api+json', $mediaType->toHeaderValue());
        self::assertTrue($mediaType->isJsonApi());
    }

    /**
     * a URI holding the space could not be split back out of the list
     *
     * @param list<string> $ext
     */
    #[DataProvider('unusableUris')]
    public function testJsonApiRefusesAUriThatCannotBeListed(array $ext): void
    {
        $this->expectException(InvalidArgumentException::class);

        MediaType::jsonApi($ext);
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function unusableUris(): array
    {
        return [
            'an empty URI' => [['']],
            'a URI holding the separator' => [['https://a.example/x y']],
            'a URI holding a control character' => [["https://a.example/\x01"]],
            'a URI holding a line feed' => [["https://a.example/\nx"]],
        ];
    }

    /**
     * each URI is checked before the join, so the message names the URI, not the joined parameter
     *
     * @param list<string> $ext
     * @param list<string> $profile
     */
    #[DataProvider('uriRefusals')]
    public function testJsonApiNamesTheUriItRefuses(array $ext, array $profile): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('an extension or profile URI must not be empty, must hold no space');

        MediaType::jsonApi($ext, $profile);
    }

    /**
     * @return array<string, array{list<string>, list<string>}>
     */
    public static function uriRefusals(): array
    {
        return [
            'an extension URI holding a control character' => [["https://a.example/\x01"], []],
            'a profile URI holding a control character' => [[], ["https://a.example/\x7f"]],
            'an extension URI holding the separator' => [['https://a.example/x y'], []],
            'an empty profile URI' => [[], ['']],
        ];
    }

    public function testExtAndProfileAreEmptyWhenTheParametersAreAbsent(): void
    {
        $mediaType = new MediaType('application', 'vnd.api+json');

        self::assertSame([], $mediaType->ext());
        self::assertSame([], $mediaType->profile());
    }

    public function testExtAndProfileSplitOnTheSeparator(): void
    {
        $mediaType = new MediaType(
            'application',
            'vnd.api+json',
            ['ext' => 'https://a.example/x  https://b.example/y', 'profile' => 'https://c.example/z'],
        );

        self::assertSame(['https://a.example/x', 'https://b.example/y'], $mediaType->ext());
        self::assertSame(['https://c.example/z'], $mediaType->profile());
    }

    #[DataProvider('jsonApiTests')]
    public function testIsJsonApiReturnsTrueForTheRegisteredType(string $value, bool $expected): void
    {
        self::assertSame($expected, MediaType::parse($value)?->isJsonApi());
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function jsonApiTests(): array
    {
        return [
            'the JSON:API media type' => ['application/vnd.api+json', true],
            'the JSON:API media type in upper case' => ['APPLICATION/VND.API+JSON', true],
            'the JSON:API media type with a foreign parameter' => ['application/vnd.api+json;charset=utf-8', true],
            'plain JSON' => ['application/json', false],
            'a wildcard range' => ['*/*', false],
        ];
    }

    #[DataProvider('jsonTests')]
    public function testIsJsonReturnsTrueForJsonAndTheJsonSuffix(string $value, bool $expected): void
    {
        self::assertSame($expected, MediaType::parse($value)?->isJson());
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function jsonTests(): array
    {
        return [
            'plain JSON' => ['application/json', true],
            'plain JSON with a charset' => ['Application/JSON; charset=utf-8', true],
            'a type of the suffix' => ['application/problem+json', true],
            'the JSON:API media type' => ['application/vnd.api+json', true],
            'a text type' => ['text/json', false],
            'a type that ends like the suffix' => ['application/xjson', false],
            'CSV' => ['text/csv', false],
        ];
    }

    public function testTheEssenceIsTheTypeAndSubtypeWithoutParameters(): void
    {
        self::assertSame('text/csv', MediaType::parse('Text/CSV; header=present')?->essence());
    }

    #[DataProvider('admissions')]
    public function testARangeAdmitsATypeByTypeSubtypeAndParameters(
        string $range,
        string $mediaType,
        bool $expected,
    ): void {
        $parsed = MediaType::parse($mediaType);
        self::assertNotNull($parsed);

        $ranges = MediaType::parseList($range);
        self::assertNotNull($ranges);
        self::assertCount(1, $ranges);

        self::assertSame($expected, $ranges[0]->admits($parsed));
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function admissions(): array
    {
        return [
            'the type itself' => ['text/csv', 'text/csv', true],
            'the type in another case' => ['TEXT/CSV', 'text/csv', true],
            'the wildcard of its type' => ['text/*', 'text/csv', true],
            'the full wildcard' => ['*/*', 'image/png', true],
            'another subtype' => ['text/plain', 'text/csv', false],
            'the wildcard of another type' => ['image/*', 'text/csv', false],
            'a parameter the type carries' => [
                'text/csv;header=present',
                'text/csv; header=present; charset=utf-8',
                true,
            ],
            'a parameter the type lacks' => ['text/plain;format=flowed', 'text/plain', false],
            'a parameter of another value' => ['text/csv;header=absent', 'text/csv;header=present', false],
            'a range whose weight takes no part' => ['text/csv;q=0', 'text/csv', true],
        ];
    }

    public function testTheMoreSpecificRangeHasTheHigherPrecedence(): void
    {
        $ranges = MediaType::parseList('*/*, text/*, text/plain, text/plain;format=flowed');
        self::assertNotNull($ranges);

        self::assertSame([0, 1, 2, 3], array_map(static function (MediaType $range): int {
            return $range->specificity();
        }, $ranges));
    }

    public function testARangeHoldsAWildcard(): void
    {
        self::assertTrue(MediaType::parse('*/*')?->isRange());
        self::assertTrue(MediaType::parse('text/*')?->isRange());
        self::assertFalse(MediaType::parse('text/csv')?->isRange());
    }

    #[DataProvider('renderedParameters')]
    public function testToHeaderValueQuotesOnlyWhatMustBeQuoted(string $parameterValue, string $expected): void
    {
        self::assertSame($expected, (new MediaType('a', 'b', ['p' => $parameterValue]))->toHeaderValue());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function renderedParameters(): array
    {
        return [
            'a token' => ['x', 'a/b;p=x'],
            'a value holding a space' => ['x y', 'a/b;p="x y"'],
            'a value holding a quote' => ['x"y', 'a/b;p="x\\"y"'],
            'a value holding a backslash' => ['x\\y', 'a/b;p="x\\\\y"'],
            'an empty value' => ['', 'a/b;p=""'],
        ];
    }

    public function testToHeaderValueAlwaysQuotesExtAndProfile(): void
    {
        $mediaType = new MediaType('application', 'vnd.api+json', ['ext' => 'x', 'profile' => 'y']);

        self::assertSame('application/vnd.api+json;ext="x";profile="y"', $mediaType->toHeaderValue());
    }

    /**
     * @param list<MediaType> $mediaTypes
     *
     * @return list<string>
     */
    private static function rendered(array $mediaTypes): array
    {
        return array_map(static function (MediaType $mediaType): string {
            return $mediaType->toHeaderValue();
        }, $mediaTypes);
    }

    /**
     * @param Closure(): mixed $build
     */
    #[DataProvider('refusalsOfUncheckedText')]
    public function testARefusalWritesTheRefusedTextEscaped(Closure $build, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $build();
    }

    /**
     * @return iterable<string, array{Closure(): mixed, string}>
     */
    public static function refusalsOfUncheckedText(): iterable
    {
        yield 'a type' => [
            static function (): MediaType {
                return new MediaType("text\n", 'csv');
            },
            'got "text\\n/csv"',
        ];
        yield 'a parameter name' => [
            static function (): MediaType {
                return new MediaType('text', 'csv', ["a\nb" => '1']);
            },
            'got "a\\nb"',
        ];
        yield 'a profile URI' => [
            static function (): MediaType {
                return MediaType::jsonApi([], ["https://a.example/p\n"]);
            },
            'got "https://a.example/p\\n"',
        ];
    }
}
