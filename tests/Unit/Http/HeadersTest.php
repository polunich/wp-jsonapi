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
use Polunich\WpJsonApi\Http\Headers;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * @internal
 */
#[CoversClass(Headers::class)]
final class HeadersTest extends TestCase
{
    public function testTheClassIsReadonly(): void
    {
        self::assertTrue((new ReflectionClass(Headers::class))->isReadOnly());
    }

    public function testTheClassIsPublicApi(): void
    {
        $docComment = (new ReflectionClass(Headers::class))->getDocComment();

        self::assertIsString($docComment);
        self::assertDoesNotMatchRegularExpression('/@internal\b/', $docComment);
    }

    /**
     * parameter names are public API too: a caller may pass arguments by name
     *
     * @param list<array{string, string}> $parameters name and type of each parameter, in order
     */
    #[DataProvider('signatures')]
    public function testTheMethodsDeclareTheSignaturesOfThePublicApi(
        string $method,
        string $returnType,
        array $parameters,
    ): void {
        $reflection = new ReflectionMethod(Headers::class, $method);

        self::assertSame($returnType, (string) $reflection->getReturnType());
        self::assertSame($parameters, array_map(
            static function (ReflectionParameter $parameter): array {
                return [$parameter->getName(), (string) $parameter->getType()];
            },
            $reflection->getParameters(),
        ));
    }

    /**
     * @return array<string, array{string, string, list<array{string, string}>}>
     */
    public static function signatures(): array
    {
        return [
            'the constructor' => ['__construct', '', [['fields', 'array']]],
            'the presence of a field' => ['has', 'bool', [['name', 'string']]],
            'the combined field value' => ['line', '?string', [['name', 'string']]],
            'the values of the field lines' => ['values', 'array', [['name', 'string']]],
            'every field' => ['all', 'array', []],
            'a field set to one value' => ['with', Headers::class, [['name', 'string'], ['value', 'string']]],
            'a field line added' => ['withAdded', Headers::class, [['name', 'string'], ['value', 'string']]],
        ];
    }

    /**
     * forces a new public method into the signature pins above
     */
    public function testThePublicMethodsAreTheOnesPinned(): void
    {
        $methods = array_map(
            static function (ReflectionMethod $method): string {
                return $method->getName();
            },
            (new ReflectionClass(Headers::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        sort($methods);

        self::assertSame(['__construct', 'all', 'has', 'line', 'values', 'with', 'withAdded'], $methods);
    }

    #[DataProvider('spellingsOfContentType')]
    public function testANameIsFoundInEverySpelling(string $given, string $looked): void
    {
        $headers = new Headers([$given => 'application/vnd.api+json']);

        self::assertTrue($headers->has($looked));
        self::assertSame('application/vnd.api+json', $headers->line($looked));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function spellingsOfContentType(): array
    {
        return [
            'header case, looked up in WordPress case' => ['Content-Type', 'content_type'],
            'WordPress case, looked up in header case' => ['content_type', 'Content-Type'],
            'upper case, looked up in lower case' => ['CONTENT-TYPE', 'content-type'],
            'lower case, looked up in upper case' => ['content-type', 'CONTENT_TYPE'],
        ];
    }

    public function testAFieldNameOfDecimalDigitsIsAccepted(): void
    {
        $headers = new Headers(['123' => 'foo']);
        $keys = array_keys($headers->all());

        self::assertCount(1, $keys);
        self::assertIsInt($keys[0]);
        self::assertSame('123', (string) $keys[0]);
        self::assertTrue($headers->has('123'));
        self::assertSame(['foo'], $headers->values('123'));
        self::assertSame([123 => ['foo']], $headers->all());
    }

    /**
     * @param array<array-key, list<string>|string> $fields
     */
    #[DataProvider('namesThatAreNoToken')]
    public function testTheConstructorRefusesANameThatIsNoToken(array $fields): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Headers($fields);
    }

    /**
     * @return array<string, array{array<array-key, list<string>|string>}>
     */
    public static function namesThatAreNoToken(): array
    {
        return [
            'a name holding CRLF' => [["x\r\nset-cookie" => 'v']],
            'a name holding a space' => [['x y' => 'v']],
            'a name holding a colon' => [['x:y' => 'v']],
            'a name holding a NUL' => [["x\0y" => 'v']],
            'a name holding a delete character' => [["x\x7f" => 'v']],
            'an empty name' => [['' => 'v']],
        ];
    }

    public function testWithRefusesANameThatIsNoToken(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Headers())->with("x\r\nset-cookie", 'v');
    }

    public function testWithAddedRefusesANameThatIsNoToken(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Headers())->withAdded("x\r\nset-cookie", 'v');
    }

    public function testTheRefusalCarriesNoLineBreakOfTheNameItNames(): void
    {
        try {
            new Headers(["x\r\nset-cookie" => 'v']);
        } catch (InvalidArgumentException $exception) {
            self::assertSame('a header field name must be a token, got "x  set-cookie"', $exception->getMessage());

            return;
        }

        self::fail('a name that is no token was accepted');
    }

    public function testANameThatIsNoTokenNamesNoField(): void
    {
        $headers = new Headers(['accept' => 'x/y']);

        self::assertFalse($headers->has("x\r\nset-cookie"));
        self::assertSame([], $headers->values('x y'));
        self::assertNull($headers->line(''));
    }

    public function testAFieldNameOfDecimalDigitsSurvivesWith(): void
    {
        $headers = (new Headers())->with('123', 'x');

        self::assertSame('x', $headers->line('123'));
    }

    public function testAFieldNameOfDecimalDigitsSurvivesWithAdded(): void
    {
        $headers = (new Headers())->withAdded('123', 'x')->withAdded('123', 'y');

        self::assertSame('x, y', $headers->line('123'));
    }

    public function testAnAbsentFieldHasNoLineAndNoValues(): void
    {
        $headers = new Headers();

        self::assertFalse($headers->has('accept'));
        self::assertNull($headers->line('accept'));
        self::assertSame([], $headers->values('accept'));
    }

    public function testAFieldGivenWithNoLineIsAbsent(): void
    {
        $headers = new Headers(['vary' => []]);

        self::assertFalse($headers->has('vary'));
        self::assertSame([], $headers->all());
    }

    public function testSeveralLinesOfOneFieldAreKeptInOrder(): void
    {
        $headers = new Headers(['vary' => ['Accept', 'Accept-Language']]);

        self::assertSame(['Accept', 'Accept-Language'], $headers->values('vary'));
        self::assertSame('Accept, Accept-Language', $headers->line('vary'));
    }

    #[DataProvider('valuesToClean')]
    public function testAValueIsCleaned(string $given, string $expected): void
    {
        self::assertSame($expected, (new Headers(['x' => $given]))->line('x'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function valuesToClean(): array
    {
        return [
            'leading and trailing space' => ['  a  ', 'a'],
            'leading and trailing tab' => ["\ta\t", 'a'],
            'an embedded CRLF' => ["a\r\nb", 'a  b'],
            'an embedded NUL' => ["a\0b", 'a b'],
            'a folded value' => ["\r\n a", 'a'],
        ];
    }

    public function testWithReplacesEveryLineOfTheField(): void
    {
        $headers = (new Headers(['vary' => ['Accept', 'Accept-Language']]))->with('Vary', 'Origin');

        self::assertSame(['Origin'], $headers->values('vary'));
    }

    public function testWithAddedKeepsTheLinesTheFieldHad(): void
    {
        $headers = (new Headers(['vary' => ['Accept']]))->withAdded('Vary', 'Accept-Language');

        self::assertSame(['Accept', 'Accept-Language'], $headers->values('vary'));
    }

    public function testWithCleansTheValueItSets(): void
    {
        self::assertSame('a b', (new Headers())->with('x', " a\nb ")->line('x'));
    }

    public function testWithAddedCleansTheValueItAdds(): void
    {
        self::assertSame('a b', (new Headers())->withAdded('x', " a\nb ")->line('x'));
    }

    public function testWithLeavesTheOriginalAlone(): void
    {
        $headers = new Headers(['vary' => 'Accept']);
        $changed = $headers->with('Vary', 'Origin');

        self::assertNotSame($headers, $changed);
        self::assertSame(['Accept'], $headers->values('vary'));
    }

    public function testWithAddedLeavesTheOriginalAlone(): void
    {
        $headers = new Headers(['vary' => 'Accept']);
        $changed = $headers->withAdded('Vary', 'Origin');

        self::assertNotSame($headers, $changed);
        self::assertSame(['Accept'], $headers->values('vary'));
    }

    public function testAllIsKeyedByTheNormalizedName(): void
    {
        $headers = new Headers(['Content_Type' => 'application/vnd.api+json', 'ACCEPT' => 'x/y']);

        self::assertSame(
            ['content-type' => ['application/vnd.api+json'], 'accept' => ['x/y']],
            $headers->all(),
        );
    }
}
