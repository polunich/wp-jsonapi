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

namespace Polunich\WpJsonApi\Tests\Contract;

use LogicException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Access\AllowAny;
use Polunich\WpJsonApi\Authentication\Challenge;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Http\UploadedFile;
use Polunich\WpJsonApi\Metadata\EndpointRoute;
use Polunich\WpJsonApi\Metadata\Route;
use Polunich\WpJsonApi\Routing\PathParameter;
use Polunich\WpJsonApi\Server\Server;
use Polunich\WpJsonApi\Testing\JsonApiAssertions;
use Polunich\WpJsonApi\Tests\Fixtures\Api\FixtureApi;
use Polunich\WpJsonApi\Tests\Fixtures\Api\InMemoryStore;
use Polunich\WpJsonApi\Tests\Fixtures\Api\PetsApi;
use Polunich\WpJsonApi\Tests\Fixtures\Api\RecordingLogger;
use stdClass;

/**
 * each request runs through `Server::authorize()` and `Server::handle()`,
 * the two calls the WordPress adapter makes
 *
 * @internal
 */
#[CoversNothing]
final class ResponseDocumentsTest extends TestCase
{
    use JsonApiAssertions;

    private const JSON_API = 'application/vnd.api+json';

    private const ATOMIC = 'application/vnd.api+json;ext="https://jsonapi.org/ext/atomic"';

    private const CURSOR = 'application/vnd.api+json;'
        . 'profile="http://jsonapi.org/profiles/ethanresnick/cursor-pagination/"';

    private const ORDER = '00000000-0000-4000-8000-00000000000';

    /**
     * @param array<array-key, mixed> $query
     * @param array<string, string>   $headers
     */
    #[DataProvider('requests')]
    public function testTheResponseMatchesTheContract(
        string $method,
        string $path,
        array $query,
        array $headers,
        string $body,
        int $status,
    ): void {
        [$actualStatus, $document, $contentType] = self::send(self::server(), $method, $path, $query, $headers, $body);

        self::assertSame($status, $actualStatus, $document);
        self::assertResponseMatchesContract(
            GeneratedOpenApiTest::CONTRACT,
            $path,
            $method,
            $actualStatus,
            $contentType,
            $document,
        );
    }

    /**
     * @return iterable<string, array{
     *     string,
     *     string,
     *     array<array-key, mixed>,
     *     array<string, string>,
     *     string,
     *     int,
     * }>
     */
    public static function requests(): iterable
    {
        $order = self::ORDER;

        yield 'a collection' => ['GET', '/pets', [], [], '', 200];
        yield 'a collection narrowed by a query parameter of its route' => [
            'GET',
            '/pets',
            ['namePrefix' => 'Re'],
            [],
            '',
            200,
        ];
        yield 'a query parameter of another route' => [
            'GET',
            '/pets',
            ['placedAfter' => '2026-01-01T00:00:00Z'],
            [],
            '',
            400,
        ];
        yield 'a value the codec of a query parameter refuses' => ['GET', '/pets', ['namePrefix' => ''], [], '', 400];
        yield 'related resources narrowed by a query parameter of their relationship' => [
            'GET',
            '/pets/1/orders',
            ['placedAfter' => '2026-01-03T00:00:00Z'],
            [],
            '',
            200,
        ];
        yield 'a compound document with sparse fieldsets' => [
            'GET',
            '/pets',
            ['include' => 'owner,orders.customer', 'fields' => ['pets' => 'name,owner,orders', 'customers' => 'name']],
            [],
            '',
            200,
        ];
        yield 'a filter on an attribute' => [
            'GET',
            '/pets',
            ['filter' => ['name' => ['@containsi' => 'rex']]],
            [],
            '',
            200,
        ];
        yield 'a filter through a relationship' => [
            'GET',
            '/pets',
            ['filter' => ['owner' => ['name' => ['@eq' => 'Ada Lovelace']]]],
            [],
            '',
            200,
        ];
        yield 'a filter with @or and @between' => [
            'GET',
            '/pets',
            ['filter' => ['@or' => [
                ['name' => ['@starts_with' => 'Whisk']],
                ['listedAt' => ['@between' => ['2026-03-01T00:00:00Z', '2026-04-01T00:00:00Z']]],
            ]]],
            [],
            '',
            200,
        ];
        yield 'a sort through a relationship' => ['GET', '/pets', ['sort' => 'owner.name,-listedAt'], [], '', 200];
        yield 'a page by number' => ['GET', '/pets', ['page' => ['number' => '2', 'size' => '1']], [], '', 200];
        yield 'a page by offset without a total' => [
            'GET',
            '/pets',
            ['page' => ['offset' => '1', 'limit' => '1', 'with_count' => 'false']],
            [],
            '',
            200,
        ];
        yield 'a page by cursor' => ['GET', '/pets', ['page' => ['size' => '1']], ['accept' => self::CURSOR], '', 200];
        yield 'a page size above the maximum' => ['GET', '/pets', ['page' => ['size' => '1000']], [], '', 400];
        yield 'an unknown query parameter' => ['GET', '/pets', ['unknown' => '1'], [], '', 400];
        yield 'an unsortable field' => ['GET', '/pets', ['sort' => 'description'], [], '', 400];
        yield 'an Accept the route cannot respond to' => [
            'GET',
            '/pets',
            [],
            ['accept' => self::JSON_API . ';charset=utf-8'],
            '',
            406,
        ];
        yield 'a resource' => ['GET', '/pets/1', [], [], '', 200];
        yield 'a resource with its includes' => ['GET', '/pets/1', ['include' => 'orders.customer'], [], '', 200];
        yield 'a missing resource' => ['GET', '/pets/99', [], [], '', 404];
        yield 'a to-one relationship' => ['GET', '/pets/1/relationships/owner', [], [], '', 200];
        yield 'related resources' => [
            'GET',
            '/pets/1/orders',
            ['include' => 'customer', 'sort' => '-placedAt'],
            [],
            '',
            200,
        ];
        yield 'a to-many relationship' => [
            'GET',
            '/pets/1/relationships/orders',
            ['page' => ['size' => '1']],
            [],
            '',
            200,
        ];
        yield 'customers' => ['GET', '/customers', ['sort' => '-name'], [], '', 200];
        yield 'a customer, in place of the route of one customer' => ['GET', '/customers/2', [], [], '', 200];
        yield 'a customer that does not exist' => ['GET', '/customers/99', [], [], '', 404];
        yield 'the customer of an order, in place of its related route' => [
            'GET',
            '/shop/orders/' . $order . '1/customer',
            [],
            [],
            '',
            200,
        ];
        yield 'the customer of an order that does not exist' => [
            'GET',
            '/shop/orders/' . $order . '9/customer',
            [],
            [],
            '',
            404,
        ];
        yield 'the relationship of an order' => [
            'GET',
            '/shop/orders/' . $order . '1/relationships/customer',
            [],
            [],
            '',
            200,
        ];
        yield 'related resources filtered through their relationship' => [
            'GET',
            '/pets/1/orders',
            ['filter' => ['customer' => ['name' => ['@containsi' => 'grace']]]],
            [],
            '',
            200,
        ];

        $pet = '{"data": {"type": "pets", "attributes": {"name": "New", "listedAt": "2026-09-01T00:00:00Z"},'
            . ' "relationships": {"owner": {"data": {"type": "customers", "id": "3"}}, "orders": {"data": []}}}}';

        yield 'a create' => ['POST', '/pets', [], ['content-type' => self::JSON_API], $pet, 201];
        yield 'a create with include' => [
            'POST',
            '/pets',
            ['include' => 'owner'],
            ['content-type' => self::JSON_API],
            $pet,
            201,
        ];
        yield 'a create without a required attribute' => [
            'POST',
            '/pets',
            [],
            ['content-type' => self::JSON_API],
            '{"data": {"type": "pets", "attributes": {"listedAt": "2026-09-01T00:00:00Z"}, "relationships":'
            . ' {"owner": {"data": {"type": "customers", "id": "3"}}}}}',
            422,
        ];
        yield 'a create of another type' => [
            'POST',
            '/pets',
            [],
            ['content-type' => self::JSON_API],
            '{"data": {"type": "customers", "attributes": {"name": "x"}}}',
            409,
        ];
        yield 'a create with a related resource that does not exist' => [
            'POST',
            '/pets',
            [],
            ['content-type' => self::JSON_API],
            str_replace('"id": "3"', '"id": "99"', $pet),
            404,
        ];
        yield 'a create in another media type' => [
            'POST',
            '/pets',
            [],
            ['content-type' => 'application/json'],
            $pet,
            415,
        ];
        yield 'a malformed document' => ['POST', '/pets', [], ['content-type' => self::JSON_API], '{"data": ', 400];
        yield 'an update' => [
            'PATCH',
            '/pets/1',
            [],
            ['content-type' => self::JSON_API],
            '{"data": {"type": "pets", "id": "1", "attributes": {"name": "Changed"}}}',
            200,
        ];
        yield 'an update with a precondition that holds' => [
            'PATCH',
            '/pets/1',
            [],
            ['content-type' => self::JSON_API, 'if-match' => '*'],
            '{"data": {"type": "pets", "id": "1", "attributes": {"description": null}}}',
            200,
        ];
        yield 'an update with a precondition that fails' => [
            'PATCH',
            '/pets/2',
            [],
            ['content-type' => self::JSON_API, 'if-match' => '"no-such-version"'],
            '{"data": {"type": "pets", "id": "2", "attributes": {"name": "Changed"}}}',
            412,
        ];
        yield 'a deletion' => ['DELETE', '/pets/3', [], [], '', 204];
        yield 'a deletion of an order' => ['DELETE', '/shop/orders/' . $order . '2', [], [], '', 204];
        yield 'a replacement of a to-one relationship' => [
            'PATCH',
            '/pets/1/relationships/owner',
            [],
            ['content-type' => self::JSON_API],
            '{"data": {"type": "customers", "id": "2"}}',
            200,
        ];
        yield 'an addition to a to-many relationship' => [
            'POST',
            '/pets/2/relationships/orders',
            [],
            ['content-type' => self::JSON_API],
            '{"data": [{"type": "orders", "id": "' . $order . '3"}]}',
            200,
        ];
        yield 'a removal from a to-many relationship' => [
            'DELETE',
            '/pets/1/relationships/orders',
            [],
            ['content-type' => self::JSON_API],
            '{"data": [{"type": "orders", "id": "' . $order . '1"}]}',
            200,
        ];
        yield 'a replacement of a to-many relationship' => [
            'PATCH',
            '/pets/1/relationships/orders',
            [],
            ['content-type' => self::JSON_API],
            '{"data": []}',
            200,
        ];
        yield 'an order with a client id' => [
            'POST',
            '/shop/orders',
            [],
            ['content-type' => self::JSON_API],
            '{"data": {"type": "orders", "id": "3f2504e0-4f89-41d3-9a0c-0305e82c3301", "attributes": {"address": "4'
            . ' Pine Road"}, "relationships": {"customer": {"data": {"type": "customers", "id": "1"}}}}}',
            201,
        ];
        yield 'an order with a client id that exists' => [
            'POST',
            '/shop/orders',
            [],
            ['content-type' => self::JSON_API],
            '{"data": {"type": "orders", "id": "' . $order . '1", "attributes": {"address": "4 Pine Road"},'
            . ' "relationships": {"customer": {"data": {"type": "customers", "id": "1"}}}}}',
            409,
        ];
        yield 'atomic operations' => [
            'POST',
            '/operations',
            [],
            ['content-type' => self::ATOMIC, 'accept' => self::ATOMIC],
            '{"atomic:operations": ['
            . '{"op": "add", "data": {"type": "pets", "lid": "a", "attributes": {"name": "Atomic", "listedAt":'
            . ' "2026-09-02T00:00:00Z"}, "relationships": {"owner": {"data": {"type": "customers", "id": "1"}}}}},'
            . '{"op": "add", "ref": {"type": "pets", "lid": "a", "relationship": "orders"}, "data": [{"type":'
            . ' "orders", "id": "'
                . $order
                . '3"}]},'
            . '{"op": "remove", "ref": {"type": "orders", "id": "' . $order . '2"}}'
            . ']}',
            200,
        ];
        yield 'an adoption' => [
            'POST',
            '/pets/2/adopt',
            [],
            ['content-type' => self::JSON_API],
            '{"data": {"type": "customers", "id": "1"}}',
            200,
        ];
        yield 'an adoption with a precondition that fails' => [
            'POST',
            '/pets/2/adopt',
            [],
            ['content-type' => self::JSON_API, 'if-match' => '"no-such-version"'],
            '{"data": {"type": "customers", "id": "1"}}',
            412,
        ];
        yield 'an adoption by a resource of another type' => [
            'POST',
            '/pets/2/adopt',
            [],
            ['content-type' => self::JSON_API],
            '{"data": {"type": "pets", "id": "1"}}',
            409,
        ];
        yield 'an adoption without a document' => ['POST', '/pets/2/adopt', [], [], '', 400];
        yield 'the recent pets' => ['GET', '/pets/recent', ['listedAfter' => '2026-02-01T00:00:00Z'], [], '', 200];
        yield 'the recent pets with their owners included' => [
            'GET',
            '/pets/recent',
            ['listedAfter' => '2026-02-01T00:00:00Z', 'include' => 'owner', 'fields' => ['customers' => 'name']],
            [],
            '',
            200,
        ];
        yield 'the recent pets without their required parameter' => ['GET', '/pets/recent', [], [], '', 400];
        yield 'the recent pets with a parameter the endpoint does not read' => [
            'GET',
            '/pets/recent',
            ['listedAfter' => '2026-02-01T00:00:00Z', 'sort' => 'name'],
            [],
            '',
            400,
        ];
        yield 'the stats' => ['GET', '/stats', [], [], '', 200];
        yield 'content the stats do not read' => [
            'GET',
            '/stats',
            [],
            ['content-type' => self::JSON_API],
            '{"meta": {}}',
            415,
        ];
        yield 'the pets as CSV' => ['GET', '/pets/export', [], ['accept' => 'text/csv'], '', 200];
        yield 'the pets as a JSON:API collection' => ['GET', '/pets/export', [], [], '', 200];
        yield 'the pets in no representation the export has' => [
            'GET',
            '/pets/export',
            [],
            ['accept' => 'image/png'],
            '',
            406,
        ];
        yield 'the photo of a pet' => ['GET', '/pets/1/photo', [], ['accept' => 'image/*'], '', 200];
        yield 'the photo of no pet' => ['GET', '/pets/99/photo', [], ['accept' => 'image/png'], '', 404];
        yield 'a payment' => [
            'POST',
            '/webhooks/payments',
            [],
            ['content-type' => 'application/json', 'accept' => 'application/json'],
            '{"id": "pay_1", "amount": 1200}',
            200,
        ];
        yield 'a payment that is no JSON' => [
            'POST',
            '/webhooks/payments',
            [],
            ['content-type' => 'application/json', 'accept' => '*/*'],
            '{"id": ',
            400,
        ];
        yield 'a payment of a wrong amount' => [
            'POST',
            '/webhooks/payments',
            [],
            ['content-type' => 'application/json', 'accept' => '*/*'],
            '{"id": "pay_1", "amount": "twelve"}',
            422,
        ];
        yield 'a payment in another media type' => [
            'POST',
            '/webhooks/payments',
            [],
            ['content-type' => 'text/plain', 'accept' => '*/*'],
            'paid',
            415,
        ];
        yield 'a payment whose sender accepts JSON:API alone' => [
            'POST',
            '/webhooks/payments',
            [],
            ['content-type' => 'application/json'],
            '{"id": "pay_1", "amount": 1200}',
            406,
        ];
        yield 'atomic operations that fail' => [
            'POST',
            '/operations',
            [],
            ['content-type' => self::ATOMIC, 'accept' => self::ATOMIC],
            '{"atomic:operations": [{"op": "remove", "ref": {"type": "pets", "id": "99"}}]}',
            404,
        ];
    }

    /**
     * a multipart form arrives as fields and files, which the request carries beside its empty body
     *
     * @param array<array-key, mixed> $form
     * @param array<array-key, mixed> $files
     */
    #[DataProvider('uploads')]
    public function testAnUploadMatchesTheContract(array $form, array $files, bool $discarded, int $status): void
    {
        $server = self::server();
        $route = null;

        foreach ($server->routes() as $candidate) {
            if ($candidate instanceof EndpointRoute && $candidate->endpoint->name === 'addPetPhoto') {
                $route = $candidate;
            }
        }

        self::assertNotNull($route);
        $request = new ServerRequest(
            'POST',
            new Headers([
                'content-type' => 'multipart/form-data; boundary=x',
                'content-length' => '4812',
                'accept' => self::JSON_API,
            ]),
            [],
            ['id' => '1'],
            '',
            $form,
            $files,
            $discarded,
        );
        $response = $server->authorize($route, $request) ?? $server->handle($route, $request);
        $document = $response->document === null
            ? ''
            : json_encode($response->document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        self::assertSame($status, $response->status, $document);
        self::assertResponseMatchesContract(
            GeneratedOpenApiTest::CONTRACT,
            '/pets/1/photos',
            'POST',
            $response->status,
            $response->headers->line('Content-Type'),
            $document,
        );
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, array<array-key, mixed>, bool, int}>
     */
    public static function uploads(): iterable
    {
        $photo = new UploadedFile('rex.jpg', 'image/jpeg', 2048, '/tmp/phpA1b2', UPLOAD_ERR_OK);

        yield 'a photo with its caption' => [['caption' => 'Rex at the park'], ['photo' => $photo], false, 204];
        yield 'a photo without its caption' => [[], ['photo' => $photo], false, 422];
        yield 'a photo larger than PHP takes' => [
            ['caption' => 'Rex'],
            ['photo' => new UploadedFile('rex.jpg', 'image/jpeg', 0, '', UPLOAD_ERR_INI_SIZE)],
            false,
            413,
        ];
        yield 'a form PHP dropped' => [[], [], true, 413];
    }

    /**
     * a denied write gets 403 for a logged-in client,
     * and 401 with the challenges for an anonymous one
     */
    public function testARefusedWriteMatchesTheContract(): void
    {
        $denied = PetsApi::permission(false);
        $body = '{"data": {"type": "pets", "id": "1", "attributes": {"name": "Changed"}}}';
        $headers = ['content-type' => self::JSON_API];

        [
            $status,
            $document,
            $contentType,
        ] = self::send(
            FixtureApi::server(InMemoryStore::seeded(), $denied, new RecordingLogger()),
            'PATCH',
            '/pets/1',
            [],
            $headers,
            $body,
        );

        self::assertSame(403, $status, $document);
        self::assertResponseMatchesContract(
            GeneratedOpenApiTest::CONTRACT,
            '/pets/1',
            'PATCH',
            $status,
            $contentType,
            $document,
        );

        $server = FixtureApi::server(
            InMemoryStore::seeded(),
            $denied,
            new RecordingLogger(),
            false,
            [new Challenge('Basic', ['realm' => 'WordPress'])],
        );
        [$status, $document, $contentType] = self::send($server, 'PATCH', '/pets/1', [], $headers, $body);

        self::assertSame(401, $status, $document);
        self::assertResponseMatchesContract(
            GeneratedOpenApiTest::CONTRACT,
            '/pets/1',
            'PATCH',
            $status,
            $contentType,
            $document,
        );
    }

    public function testEveryPageOfACursorPaginationMatchesTheContract(): void
    {
        $server = self::server();
        $query = ['page' => ['size' => '1']];
        $pages = 0;

        do {
            [
                $status,
                $document,
                $contentType,
            ] = self::send($server, 'GET', '/pets', $query, ['accept' => self::CURSOR], '');

            self::assertSame(200, $status, $document);
            self::assertResponseMatchesContract(
                GeneratedOpenApiTest::CONTRACT,
                '/pets',
                'GET',
                $status,
                $contentType,
                $document,
            );

            $decoded = json_decode($document, false, 512, JSON_THROW_ON_ERROR);
            $links = $decoded instanceof stdClass ? ($decoded->links ?? null) : null;
            $next = $links instanceof stdClass ? ($links->next ?? null) : null;
            $query = [];

            if (is_string($next)) {
                parse_str((string) parse_url($next, PHP_URL_QUERY), $query);
            }

            ++$pages;
        } while (is_string($next) && $pages < 10);

        self::assertSame(3, $pages);
    }

    private static function server(): Server
    {
        return FixtureApi::server(InMemoryStore::seeded(), new AllowAny(), new RecordingLogger());
    }

    /**
     * @param array<array-key, mixed> $query
     * @param array<string, string>   $headers
     *
     * @return array{int, string, ?string}
     */
    private static function send(
        Server $server,
        string $method,
        string $path,
        array $query,
        array $headers,
        string $body,
    ): array {
        foreach ($server->routes() as $route) {
            $parameters = self::match($route, $method, $path);

            if ($parameters === null) {
                continue;
            }

            $request = new ServerRequest(
                $method,
                new Headers($headers + ['accept' => self::JSON_API]),
                $query,
                $parameters,
                $body,
            );
            $response = $server->authorize($route, $request) ?? $server->handle($route, $request);

            return [
                $response->status,
                $response->content !== null
                    ? implode('', iterator_to_array($response->content->pieces(), false))
                    : ($response->document === null ? '' : json_encode(
                        $response->document,
                        JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                    )),
                $response->headers->line('Content-Type'),
            ];
        }

        throw new LogicException(sprintf('the fixture API has no route %s %s', $method, $path));
    }

    /**
     * the parameters of the path when the route's template matches it,
     * as WordPress matches the route's pattern:
     * each literal encoded, each parameter one segment its codec admits
     *
     * @return array<string, string>|null
     */
    private static function match(Route $route, string $method, string $path): ?array
    {
        if (!in_array($method, $route->methods, true)) {
            return null;
        }

        $segments = array_map(static function (string|PathParameter $segment): string {
            return $segment instanceof PathParameter
                ? '(?P<' . $segment->name . '>' . $segment->codec->segmentPattern() . ')'
                : preg_quote(rawurlencode($segment), '~');
        }, $route->template->segments);

        if (preg_match('~^/' . implode('/', $segments) . '$~D', $path, $matches) !== 1) {
            return null;
        }

        return array_filter($matches, is_string(...), ARRAY_FILTER_USE_KEY);
    }
}
