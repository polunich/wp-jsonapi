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

namespace Polunich\WpJsonApi\Tests\Unit\Testing;

use InvalidArgumentException;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Server\Server;
use Polunich\WpJsonApi\Testing\ContractSchemas;
use Polunich\WpJsonApi\Tests\Fixtures\Api\PetsApi;
use Polunich\WpJsonApi\Tests\Fixtures\Api\RecordingLogger;
use Polunich\WpJsonApi\Tests\Fixtures\TemporaryDirectory;
use ReflectionClass;
use stdClass;

/**
 * @internal
 */
#[CoversClass(ContractSchemas::class)]
final class ContractSchemasTest extends TestCase
{
    private const JSON_API = 'application/vnd.api+json';

    private string $directory;

    #[Override]
    protected function setUp(): void
    {
        $this->directory = TemporaryDirectory::create();
    }

    #[Override]
    protected function tearDown(): void
    {
        TemporaryDirectory::remove($this->directory);
    }

    public function testTheClassIsFinalAndPublic(): void
    {
        $class = new ReflectionClass(ContractSchemas::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertStringNotContainsString('@internal', (string) $class->getDocComment());
    }

    public function testTheResponsesOfTheLibraryMatchItsContract(): void
    {
        $server = self::pets();
        $contract = ContractSchemas::fromFile($this->write('openapi.json', $server->openApiJson()));

        [$status, $body] = self::respond($server, 'GET', '/pets', []);

        self::assertSame(200, $status);
        self::assertSame([], $contract->violations('/pets', 'GET', $status, self::JSON_API, $body));

        [$status, $body] = self::respond($server, 'GET', '/pets/{id}', ['id' => '1']);

        self::assertSame(200, $status);
        self::assertSame([], $contract->violations('/pets/1', 'GET', $status, self::JSON_API, $body));

        [$status, $body] = self::respond($server, 'GET', '/pets/{id}', ['id' => '404']);

        self::assertSame(404, $status);
        self::assertSame([], $contract->violations('/pets/404', 'GET', $status, self::JSON_API, $body));

        [$status, $body] = self::respond($server, 'DELETE', '/pets/{id}', ['id' => '1']);

        self::assertSame(204, $status);
        self::assertSame('', $body);
        self::assertSame([], $contract->violations('/pets/1', 'DELETE', $status, self::JSON_API, $body));

        $document = json_decode(
            self::respond($server, 'GET', '/pets/{id}', ['id' => '2'])[1],
            false,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertInstanceOf(stdClass::class, $document);
        self::assertInstanceOf(stdClass::class, $document->data);
        $document->data->type = 42;

        self::assertSame(
            ['/data/type: The data (integer) must match the type: string'],
            $contract->violations('/pets/2', 'GET', 200, self::JSON_API, json_encode($document, JSON_THROW_ON_ERROR)),
        );
    }

    #[DataProvider('unreadableContracts')]
    public function testRefusesAFileThatIsNoContract(string $bytes, string $message): void
    {
        $file = $this->write('openapi.json', $bytes);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('the contract "%s" %s', $file, $message));

        ContractSchemas::fromFile($file);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unreadableContracts(): iterable
    {
        $library = 'is no OpenAPI 3.1 document with paths, the document the library writes';

        yield 'no JSON' => ['{"openapi": ', 'is no JSON: Syntax error'];
        yield 'a list' => ['[]', $library];
        yield 'no version' => ['{"paths": {}}', $library];
        yield 'OpenAPI 3.0' => ['{"openapi": "3.0.3", "paths": {}}', $library];
        yield 'OpenAPI 3.2' => ['{"openapi": "3.2.0", "paths": {}}', $library];
        yield 'a number for a version' => ['{"openapi": 3.1, "paths": {}}', $library];
        yield 'no paths' => ['{"openapi": "3.1.2"}', $library];
        yield 'a list of paths' => ['{"openapi": "3.1.2", "paths": []}', $library];
    }

    public function testRefusesAFileThatDoesNotExist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('the contract "%s/openapi.json" cannot be read', $this->directory));

        ContractSchemas::fromFile($this->directory . '/openapi.json');
    }

    public function testAConcretePathIsMatchedBeforeATemplate(): void
    {
        $contract = $this->contract([
            '/pets/{petId}' => ['get' => self::responses(['200' => self::content(['const' => 'template'])])],
            '/pets/mine' => ['get' => self::responses(['200' => self::content(['const' => 'concrete'])])],
        ]);

        self::assertSame([], $contract->violations('/pets/mine', 'GET', 200, self::JSON_API, '"concrete"'));
        self::assertSame([], $contract->violations('/pets/7', 'GET', 200, self::JSON_API, '"template"'));
    }

    public function testTwoTemplatesThatMatchAreRefused(): void
    {
        $contract = $this->contract([
            '/{a}/b' => ['get' => self::responses(['200' => self::content(true)])],
            '/a/{b}' => ['get' => self::responses(['200' => self::content(true)])],
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('matches the path /a/b to /{a}/b and /a/{b}, and a path is matched to one');

        $contract->violations('/a/b', 'GET', 200, self::JSON_API, '{}');
    }

    #[DataProvider('templates')]
    public function testATemplateStandsForOneSegment(string $template, string $path, bool $matches): void
    {
        $contract = $this->contract([$template => ['get' => self::responses(['200' => self::content(true)])]]);

        if (!$matches) {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('describes no path');
        }

        self::assertSame([], $contract->violations($path, 'GET', 200, self::JSON_API, '{}'));
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function templates(): iterable
    {
        yield 'a segment' => ['/pets/{id}', '/pets/1', true];
        yield 'a part of a segment' => ['/files/{name}.json', '/files/report.json', true];
        yield 'an empty segment' => ['/pets/{id}', '/pets/', false];
        yield 'two segments' => ['/pets/{id}', '/pets/1/2', false];
        yield 'a longer path' => ['/pets/{id}', '/pets/1/orders', false];
        yield 'a literal part of another case' => ['/pets/{id}', '/Pets/1', false];
        yield 'a query string, which is not read' => ['/pets/{id}', '/pets/1?include=owner', true];
        yield 'a fragment, which is not read' => ['/pets/{id}', '/pets/1#top', true];
    }

    #[DataProvider('spellings')]
    public function testAPathIsComparedInNormalForm(string $described, string $path): void
    {
        $contract = $this->contract([$described => ['get' => self::responses(['200' => self::content(true)])]]);

        self::assertSame([], $contract->violations($path, 'GET', 200, self::JSON_API, '{}'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function spellings(): iterable
    {
        yield 'the same encoding' => ['/pet%20toys', '/pet%20toys'];
        yield 'lower-case digits' => ['/caf%C3%A9', '/caf%c3%a9'];
        yield 'an encoded unreserved character' => ['/a-b', '/a%2Db'];
        yield 'an encoded tilde in the contract' => ['/%7Euser', '/~user'];
        yield 'in a template' => ['/pet%20toys/{id}', '/pet%20toys/7'];
    }

    public function testTheMethodIsReadInAnyCase(): void
    {
        $contract = $this->contract(['/a' => ['get' => self::responses(['200' => self::content(true)])]]);

        self::assertSame([], $contract->violations('/a', 'get', 200, self::JSON_API, '{}'));
        self::assertSame([], $contract->violations('/a', 'GET', 200, self::JSON_API, '{}'));
    }

    #[DataProvider('undescribedResponses')]
    public function testAResponseTheContractDoesNotDescribeIsRefused(
        string $path,
        string $method,
        int $status,
        string $message,
    ): void {
        $contract = $this->contract(['/a/{id}' => ['get' => self::responses(['200' => self::content(true)])]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $contract->violations($path, $method, $status, self::JSON_API, '{}');
    }

    /**
     * @return iterable<string, array{string, string, int, string}>
     */
    public static function undescribedResponses(): iterable
    {
        yield 'a path' => ['/b', 'GET', 200, 'describes no path /b'];
        yield 'a method' => ['/a/1', 'DELETE', 204, 'describes no DELETE on /a/{id}'];
        yield 'no method at all' => ['/a/1', 'FETCH', 200, 'describes no FETCH on /a/{id}'];
        yield 'the parameters field' => ['/a/1', 'parameters', 200, 'describes no PARAMETERS on /a/{id}'];
        yield 'a status' => ['/a/1', 'GET', 418, 'describes no 418 response to GET /a/{id}'];
    }

    public function testAStatusFallsBackToItsRangeAndToDefault(): void
    {
        $contract = $this->contract(['/a' => ['get' => self::responses([
            '404' => self::content(['const' => 'exact']),
            '4XX' => self::content(['const' => 'range']),
            'default' => self::content(['const' => 'default']),
        ])]]);

        self::assertSame([], $contract->violations('/a', 'GET', 404, self::JSON_API, '"exact"'));
        self::assertSame([], $contract->violations('/a', 'GET', 409, self::JSON_API, '"range"'));
        self::assertSame([], $contract->violations('/a', 'GET', 500, self::JSON_API, '"default"'));
        self::assertNotSame([], $contract->violations('/a', 'GET', 404, self::JSON_API, '"range"'));
    }

    public function testAReferencedResponseIsFollowed(): void
    {
        $contract = $this->contract(
            ['/a' => ['get' => self::responses(['200' => ['$ref' => '#/components/responses/one']])]],
            [
                'responses' => ['one' => self::content(['$ref' => '#/components/schemas/text~1plain'])],
                'schemas' => ['text/plain' => ['type' => 'string']],
            ],
        );

        self::assertSame([], $contract->violations('/a', 'GET', 200, self::JSON_API, '"text"'));
        self::assertSame(
            ['/: The data (integer) must match the type: string'],
            $contract->violations('/a', 'GET', 200, self::JSON_API, '7'),
        );
    }

    #[DataProvider('brokenReferences')]
    public function testABrokenReferenceIsRefused(string $reference, string $message): void
    {
        $contract = $this->contract(
            ['/a' => ['get' => self::responses(['200' => ['$ref' => $reference]])]],
            ['responses' => ['loop' => ['$ref' => '#/components/responses/loop']]],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $contract->violations('/a', 'GET', 200, self::JSON_API, '{}');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function brokenReferences(): iterable
    {
        yield 'another document' => [
            'responses.json#/one',
            'refers to responses.json#/one, and the check follows a reference within the contract that ends',
        ];
        yield 'nothing' => [
            '#/components/responses/none',
            'refers to #/components/responses/none, which names no object of the contract',
        ];
        yield 'itself' => [
            '#/components/responses/loop',
            'refers to #/components/responses/loop, and the check follows a reference within the contract that ends',
        ];
    }

    public function testAResponseWithoutContentHasAnEmptyBody(): void
    {
        $contract = $this->contract(['/a' => ['delete' => self::responses([
            '204' => ['description' => 'gone'],
            '200' => ['description' => 'x', 'content' => new stdClass()],
        ])]]);

        self::assertSame([], $contract->violations('/a', 'DELETE', 204, null, ''));
        self::assertSame([], $contract->violations('/a', 'DELETE', 200, null, ''));
        self::assertSame(
            ['the contract describes no content for the 204 response, and the body holds 2 bytes'],
            $contract->violations('/a', 'DELETE', 204, self::JSON_API, '{}'),
        );
    }

    public function testTheContentTypeOfTheResponsePicksTheMostSpecificMediaType(): void
    {
        $contract = $this->contract(['/a' => ['get' => self::responses([
            '200' => ['description' => 'x', 'content' => [
                'text/*' => ['schema' => ['type' => 'string', 'maxLength' => 1]],
                'text/csv' => ['schema' => ['type' => 'string', 'pattern' => '^id,']],
                'text/csv;header=present' => ['schema' => ['type' => 'string', 'pattern' => '^id,name']],
                self::JSON_API => ['schema' => ['type' => 'object']],
            ]],
        ])]]);

        self::assertSame([], $contract->violations('/a', 'GET', 200, 'Text/CSV; charset=utf-8', "id,x\n"));
        self::assertSame([], $contract->violations('/a', 'GET', 200, 'text/csv; header=present', "id,name\n"));
        self::assertNotSame([], $contract->violations('/a', 'GET', 200, 'text/csv; header=present', "id,x\n"));
        self::assertSame([], $contract->violations('/a', 'GET', 200, 'text/plain', 'x'));
        self::assertSame(
            [],
            $contract->violations('/a', 'GET', 200, self::JSON_API . '; ext="https://jsonapi.org/ext/atomic"', '{}'),
        );
    }

    public function testTheFirstWrittenOfEquallySpecificMediaTypesIsPicked(): void
    {
        $contract = $this->contract(['/a' => ['get' => self::responses([
            '200' => ['description' => 'x', 'content' => [
                'text/csv;header=present' => ['schema' => ['type' => 'string', 'pattern' => '^id,']],
                'text/csv;charset=utf-8' => ['schema' => ['type' => 'string', 'pattern' => '^name,']],
            ]],
        ])]]);

        self::assertSame(
            [],
            $contract->violations('/a', 'GET', 200, 'text/csv; header=present; charset=utf-8', "id,name\n"),
        );
    }

    public function testAResponseInAMediaTypeTheContractDoesNotDescribeIsAViolation(): void
    {
        $contract = $this->contract(['/a' => ['get' => self::responses(['200' => self::content(true)])]]);

        self::assertSame(
            ['the contract describes the 200 response in application/vnd.api+json, and none of them is text/csv'],
            $contract->violations('/a', 'GET', 200, 'text/csv', 'id'),
        );
        self::assertSame(
            ['the contract describes the content of the 200 response in application/vnd.api+json, and the response'
                . ' names no media type it is in'],
            $contract->violations('/a', 'GET', 200, null, '{}'),
        );
    }

    public function testTheBodyIsReadByItsMediaType(): void
    {
        $contract = $this->contract(['/a' => ['get' => self::responses([
            '200' => ['description' => 'x', 'content' => [
                'text/csv' => ['schema' => ['type' => 'string', 'pattern' => '^id,']],
                'image/png' => new stdClass(),
                'application/json' => new stdClass(),
            ]],
        ])]]);

        self::assertSame([], $contract->violations('/a', 'GET', 200, 'text/csv', "id,name\n1,Rex\n"));
        self::assertNotSame([], $contract->violations('/a', 'GET', 200, 'text/csv', "name\n"));
        self::assertSame(
            ['the body of the media type text/csv is no text in UTF-8'],
            $contract->violations('/a', 'GET', 200, 'text/csv', "id,caf\xe9"),
        );
        self::assertSame([], $contract->violations('/a', 'GET', 200, 'image/png', "\x89PNG\r\n"));
        self::assertSame([], $contract->violations('/a', 'GET', 200, 'application/json', '[1, 2]'));
        self::assertSame(
            ['the body is no JSON: Syntax error'],
            $contract->violations('/a', 'GET', 200, 'application/json', '[1,'),
        );
    }

    public function testABodyThatIsNoJsonIsAViolation(): void
    {
        $contract = $this->contract(['/a' => ['get' => self::responses(['200' => self::content(true)])]]);

        self::assertSame(
            ['the body is no JSON: Syntax error'],
            $contract->violations('/a', 'GET', 200, self::JSON_API, '{"a": '),
        );
        self::assertSame(
            ['the body is no JSON: Syntax error'],
            $contract->violations('/a', 'GET', 200, self::JSON_API, ''),
        );
    }

    /**
     * the shape that trips the plain `Validator` of opis:
     * it writes the default of an `allOf` branch into the response, then refuses it as unevaluated
     */
    public function testADefaultChangesNothingOfTheResponse(): void
    {
        $contract = $this->contract(['/a' => ['get' => self::responses(['200' => self::content([
            'type' => 'object',
            'properties' => ['in' => ['type' => 'string']],
            'allOf' => [['properties' => ['style' => ['type' => 'string', 'default' => 'form']]]],
            'unevaluatedProperties' => false,
        ])])]]);

        self::assertSame([], $contract->violations('/a', 'GET', 200, self::JSON_API, '{"in": "query"}'));
    }

    public function testAFieldThatIsNoMethodNamesNoOperation(): void
    {
        $contract = $this->contract(['/a' => [
            'get' => self::responses(['200' => self::content(true)]),
            'x-internal' => self::responses(['200' => self::content(true)]),
        ]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('describes no X-INTERNAL on /a');

        $contract->violations('/a', 'X-INTERNAL', 200, self::JSON_API, '{}');
    }

    public function testAFormatIsAsserted(): void
    {
        $contract = $this->contract(['/a' => ['get' => self::responses(['200' => self::content([
            'type' => 'string',
            'format' => 'uri',
        ])])]]);

        self::assertSame(
            [],
            $contract->violations('/a', 'GET', 200, self::JSON_API, '"https://example.com/wp-json/acme/v1/a"'),
        );
        self::assertSame(
            ['/: The data must match the \'uri\' format'],
            $contract->violations('/a', 'GET', 200, self::JSON_API, '"no uri"'),
        );
    }

    public function testASchemaTheValidatorCannotReadIsRefused(): void
    {
        $contract = $this->contract(['/a' => ['get' => self::responses(['200' => self::content([
            '$ref' => '#/components/schemas/missing',
        ])])]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('holds a schema the validator cannot read at'
            . ' /paths/~1a/get/responses/200/content/application~1vnd.api+json/schema: ');

        $contract->violations('/a', 'GET', 200, self::JSON_API, '{}');
    }

    private static function pets(): Server
    {
        return PetsApi::server(PetsApi::store(), PetsApi::permission(true), new RecordingLogger());
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return array{int, string}
     */
    private static function respond(Server $server, string $method, string $path, array $parameters): array
    {
        foreach ($server->routes() as $route) {
            if ($route->template->path !== $path || !in_array($method, $route->methods, true)) {
                continue;
            }

            $request = new ServerRequest($method, new Headers(['accept' => [self::JSON_API]]), [], $parameters, '');
            $response = $server->authorize($route, $request) ?? $server->handle($route, $request);

            return [
                $response->status,
                $response->document === null
                    ? ''
                    : json_encode($response->document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ];
        }

        throw new LogicException(sprintf('the pets API has no route %s %s', $method, $path));
    }

    /**
     * @param array<string, mixed> $paths
     * @param array<string, mixed> $components
     */
    private function contract(array $paths, array $components = []): ContractSchemas
    {
        $document = ['openapi' => '3.1.2', 'info' => ['title' => 't', 'version' => '1'], 'paths' => $paths];

        if ($components !== []) {
            $document['components'] = $components;
        }

        return ContractSchemas::fromFile($this->write(
            'openapi.json',
            json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ));
    }

    /**
     * @param array<array-key, mixed> $responses by status; PHP turns a decimal key into an int,
     *                                           which `json_encode()` still writes as a member
     *
     * @return array<string, mixed>
     */
    private static function responses(array $responses): array
    {
        return ['responses' => $responses];
    }

    /**
     * @param array<string, mixed>|bool $schema
     *
     * @return array<string, mixed>
     */
    private static function content(array|bool $schema): array
    {
        return ['description' => 'x', 'content' => [self::JSON_API => ['schema' => $schema]]];
    }

    private function write(string $name, string $bytes): string
    {
        $file = $this->directory . '/' . $name;
        file_put_contents($file, $bytes);

        return $file;
    }
}
