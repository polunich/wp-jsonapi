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
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Http\UploadedFile;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * @internal
 */
#[CoversClass(ServerRequest::class)]
final class ServerRequestTest extends TestCase
{
    public function testTheClassAndEveryMemberAreReadonly(): void
    {
        $class = new ReflectionClass(ServerRequest::class);

        self::assertTrue($class->isReadOnly());

        foreach ($class->getProperties() as $property) {
            self::assertTrue($property->isReadOnly(), $property->getName() . ' is not readonly');
        }
    }

    public function testTheClassIsPublicApi(): void
    {
        $docComment = (new ReflectionClass(ServerRequest::class))->getDocComment();

        self::assertIsString($docComment);
        self::assertDoesNotMatchRegularExpression('/@internal\b/', $docComment);
    }

    /**
     * parameter names are public API too: a project that builds a request, in a test of its own,
     * may pass arguments by name
     */
    public function testTheConstructorDeclaresTheSignatureOfThePublicApi(): void
    {
        $parameters = array_map(
            static function (ReflectionParameter $parameter): array {
                return [$parameter->getName(), (string) $parameter->getType()];
            },
            (new ReflectionMethod(ServerRequest::class, '__construct'))->getParameters(),
        );

        self::assertSame([
            ['method', 'string'],
            ['headers', Headers::class],
            ['query', 'array'],
            ['path', 'array'],
            ['body', 'string'],
            ['form', 'array'],
            ['files', 'array'],
            ['formDiscarded', 'bool'],
        ], $parameters);
    }

    public function testTheBodyIsKeptAsItWasReceived(): void
    {
        $body = "{\n  \"data\": { \"type\": \"pets\" }\n}\n";

        self::assertSame($body, self::request(body: $body)->body);
    }

    public function testAnEmptyBodyStaysEmpty(): void
    {
        self::assertSame('', self::request()->body);
    }

    public function testABodyThatIsNoJsonIsKept(): void
    {
        self::assertSame('not json', self::request(body: 'not json')->body);
    }

    public function testTheQueryParametersAreKeptAsTheyWereHandedOver(): void
    {
        $query = ['filter' => ['city' => ['@eq' => 'Kyiv']], 'page' => ['number' => '2']];

        self::assertSame($query, self::request(query: $query)->query);
    }

    public function testAQueryParameterStaysTheTextTheClientWrote(): void
    {
        $query = [
            'page' => ['size' => '10'],
            'filter' => ['active' => ['@eq' => 'true'], 'seats' => ['@gt' => '0']],
        ];
        $request = self::request(query: $query);
        $page = $request->query['page'];
        $filter = $request->query['filter'];

        self::assertIsArray($page);
        self::assertIsString($page['size'] ?? null);
        self::assertIsArray($filter);
        self::assertIsArray($filter['active'] ?? null);
        self::assertIsString($filter['active']['@eq'] ?? null);
        self::assertSame($query, $request->query);
    }

    public function testAQueryParameterNamedWithDigitsArrivesUnderAnIntKey(): void
    {
        $read = [];
        parse_str('0=x&12[3]=y&filter[city]=Kyiv', $read);
        $request = self::request(query: $read);
        $names = array_keys($request->query);

        self::assertSame([0, 12, 'filter'], $names);
        self::assertSame('0', (string) $names[0]);
        self::assertSame(['0' => 'x', '12' => ['3' => 'y'], 'filter' => ['city' => 'Kyiv']], $request->query);
    }

    /**
     * a PHPDoc type is not checked at runtime, so the test reads the declaration.
     * a query name of digits arrives under an int key.
     * typed with string keys, `$query` would let a consumer pass one to a `string` parameter,
     * a `TypeError` under strict_types
     */
    public function testTheConstructorDeclaresTheKeyTypeEachSourceCanGive(): void
    {
        $constructor = (new ReflectionClass(ServerRequest::class))->getConstructor();

        self::assertNotNull($constructor);
        $declaration = $constructor->getDocComment();

        self::assertIsString($declaration);
        self::assertMatchesRegularExpression('/@param\s+array<array-key, mixed>\s+\$query\b/', $declaration);
        self::assertMatchesRegularExpression('/@param\s+array<string, string>\s+\$path\b/', $declaration);
    }

    public function testTheRouteParametersAreKeptAsTheyWereHandedOver(): void
    {
        $path = ['id' => '1', 'relationship' => 'tags'];

        self::assertSame($path, self::request(path: $path)->path);
    }

    public function testTheMethodAndTheHeadersAreTheOnesGiven(): void
    {
        $headers = new Headers(['content_type' => 'application/vnd.api+json']);
        $request = new ServerRequest('PATCH', $headers, [], [], '');

        self::assertSame('PATCH', $request->method);
        self::assertSame($headers, $request->headers);
    }

    public function testAFormIsEmptyUnlessOneIsGiven(): void
    {
        $request = self::request();

        self::assertSame([], $request->form);
        self::assertSame([], $request->files);
        self::assertFalse($request->formDiscarded);
    }

    /**
     * @param array<string, string>   $headers
     * @param array<array-key, mixed> $form
     * @param array<array-key, mixed> $files
     */
    #[DataProvider('contents')]
    public function testTellsWhetherItCarriesContent(
        array $headers,
        string $body,
        array $form,
        array $files,
        bool $expected,
    ): void {
        self::assertSame(
            $expected,
            (new ServerRequest('POST', new Headers($headers), [], [], $body, $form, $files))->hasContent(),
        );
    }

    /**
     * @return iterable<string, array{
     *     array<string, string>,
     *     string,
     *     array<array-key, mixed>,
     *     array<array-key, mixed>,
     *     bool,
     * }>
     */
    public static function contents(): iterable
    {
        $photo = new UploadedFile('rex.jpg', 'image/jpeg', 2048, '/tmp/phpA1b2', UPLOAD_ERR_OK);

        yield 'nothing at all' => [[], '', [], [], false];
        yield 'a length of zero' => [['content_length' => '0'], '', [], [], false];
        yield 'a length of zeros' => [['content_length' => '00'], '', [], [], false];
        yield 'a multipart body PHP took apart' => [['content_length' => '48213'], '', [], [], true];
        yield 'a chunked body' => [['transfer_encoding' => 'chunked'], '', [], [], true];
        yield 'bytes of a request built in code' => [[], '{}', [], [], true];
        yield 'fields of a request built in code' => [[], '', ['caption' => 'Rex'], [], true];
        yield 'files of a request built in code' => [[], '', [], ['photo' => $photo], true];
    }

    /**
     * @param array<array-key, mixed> $query
     * @param array<string, string>   $path
     */
    private static function request(array $query = [], array $path = [], string $body = ''): ServerRequest
    {
        return new ServerRequest('GET', new Headers(), $query, $path, $body);
    }
}
