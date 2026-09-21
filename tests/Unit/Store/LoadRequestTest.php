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

namespace Polunich\WpJsonApi\Tests\Unit\Store;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Localization\LanguageRange;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\Fieldset\FieldHint;
use Polunich\WpJsonApi\Store\LoadRequest;
use Polunich\WpJsonApi\Tests\Fixtures\Store\PortSignatureReader;
use ReflectionClass;
use ReflectionParameter;
use ReflectionProperty;

/**
 * @internal
 */
#[CoversClass(LoadRequest::class)]
final class LoadRequestTest extends TestCase
{
    public function testTheClassIsFinalAndReadonly(): void
    {
        $class = new ReflectionClass(LoadRequest::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
    }

    public function testTheClassIsPublicApi(): void
    {
        self::assertStringNotContainsString(
            '@internal',
            (string) (new ReflectionClass(LoadRequest::class))->getDocComment(),
        );
    }

    /**
     * the parameter names are public API, and so are the properties a loader reads
     */
    public function testTheConstructorPromotesItsPublicParameters(): void
    {
        $class = new ReflectionClass(LoadRequest::class);
        $expected = [
            ['type', 'string'],
            ['ids', 'array'],
            ['fields', FieldHint::class],
            ['languages', LanguagePreferences::class],
            ['queryParameters', Values::class],
        ];

        self::assertSame(
            $expected,
            array_map(
                static function (ReflectionParameter $parameter): array {
                    return [$parameter->getName(), (string) $parameter->getType()];
                },
                $class->getConstructor()?->getParameters() ?? [],
            ),
        );

        $properties = [];

        foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $properties[$property->getName()] = (string) $property->getType();
        }

        ksort($properties);
        $expectedProperties = array_column($expected, 1, 0);
        ksort($expectedProperties);

        self::assertSame($expectedProperties, $properties);
    }

    public function testTheIdsAreANonEmptyList(): void
    {
        self::assertSame(
            ['non-empty-list<string>'],
            PortSignatureReader::tags(new ReflectionProperty(LoadRequest::class, 'ids'), 'var'),
        );
    }

    public function testCarriesTheTypeTheIdsTheFieldsAndTheLanguages(): void
    {
        $fields = new FieldHint(['name'], ['owner']);
        $languages = new LanguagePreferences([new LanguageRange('uk')]);

        $request = new LoadRequest(
            type: 'pets',
            ids: ['7', '3', '12'],
            fields: $fields,
            languages: $languages,
            queryParameters: new Values([]),
        );

        self::assertSame('pets', $request->type);
        self::assertSame(['7', '3', '12'], $request->ids);
        self::assertSame($fields, $request->fields);
        self::assertSame($languages, $request->languages);
    }

    /**
     * type and ids are opaque strings: only emptiness and repetition are checked
     *
     * @param list<string> $ids
     */
    #[DataProvider('distinctIds')]
    public function testTakesEveryListOfDistinctIdsThatAreNotEmpty(string $type, array $ids): void
    {
        $request = self::request($type, $ids);

        self::assertSame($type, $request->type);
        self::assertSame($ids, $request->ids);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function distinctIds(): iterable
    {
        yield 'one id' => ['pets', ['1']];
        yield 'zero' => ['pets', ['0']];
        yield 'a space' => ['pets', [' ']];
        yield 'numeric strings that differ as strings' => ['pets', ['1', '01', '1.0', ' 1', '-0']];
        yield 'a type that is no member name' => ['pe.ts', ['1']];
    }

    public function testRefusesAnEmptyType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('records are loaded for a resource type, and a type is never empty');

        self::request('', ['1']);
    }

    public function testRefusesARequestWithoutIds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('records of type "pets" are loaded by one or more ids, and this request names'
            . ' none');

        self::request('pets', []);
    }

    /**
     * @param list<string> $ids
     */
    #[DataProvider('idListsWithAnEmptyId')]
    public function testRefusesAnEmptyId(array $ids): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('a request to load records of type "pets" names an empty id, which identifies'
            . ' no resource');

        self::request('pets', $ids);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function idListsWithAnEmptyId(): iterable
    {
        yield 'the only id' => [['']];
        yield 'after another id' => [['1', '']];
    }

    /**
     * @param list<string> $ids
     */
    #[DataProvider('idListsWithARepeatedId')]
    public function testRefusesAnIdNamedTwice(array $ids, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::request('pets', $ids);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function idListsWithARepeatedId(): iterable
    {
        yield 'side by side' => [['1', '1'], 'names id "1" twice, and each type and id pair is loaded once'];
        yield 'apart' => [['7', '3', '7'], 'names id "7" twice'];
        yield 'an id that is no decimal integer' => [['a', 'b', 'a'], 'names id "a" twice'];
    }

    /**
     * @param list<string> $ids
     */
    #[DataProvider('refusalsNamingOutsideValues')]
    public function testEscapesAControlCharacterOfEveryValueItNamesInTheMessage(
        string $type,
        array $ids,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::request($type, $ids);
    }

    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function refusalsNamingOutsideValues(): iterable
    {
        yield 'the type of a request without ids' => [
            "pe\tts",
            [],
            'records of type "pe\tts" are loaded by one or more ids, and this request names none',
        ];
        yield 'the type of a request with an empty id' => [
            "pe\tts",
            ['1', ''],
            'a request to load records of type "pe\tts" names an empty id, which identifies no resource',
        ];
        yield 'the type and the id named twice' => [
            "pe\tts",
            ["1\n2", "1\n2"],
            'a request to load records of type "pe\tts" names id "1\n2" twice',
        ];
    }

    /**
     * @param list<string> $ids
     */
    private static function request(string $type, array $ids): LoadRequest
    {
        return new LoadRequest($type, $ids, new FieldHint(null), new LanguagePreferences([]), new Values([]));
    }
}
