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

namespace Polunich\WpJsonApi\Tests\Integration\WordPress;

use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use Polunich\WpJsonApi\Tests\Fixtures\Plugin\FixturePlugin;
use Polunich\WpJsonApi\Tests\Integration\RestClient;
use Polunich\WpJsonApi\Tests\Integration\WordPressTestCase;

/**
 * @internal
 */
#[CoversNothing]
final class WriteRoutesTest extends WordPressTestCase
{
    private const ATOMIC = 'application/vnd.api+json;ext="https://jsonapi.org/ext/atomic"';

    private const ORDER = '00000000-0000-4000-8000-00000000000';

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(1);
    }

    public function testACreateRespondsWithTheLocationOfTheResource(): void
    {
        $response = self::send('POST', '/pets', [], ['content-type' => self::JSON_API], self::pet('A new pet'));

        self::assertSame(201, $response->status, $response->body);
        self::assertSame(self::JSON_API, $response->header('Content-Type'));
        self::assertMatchesContract($response, '/pets', 'POST');

        $self = $response->member('data', 'links', 'self');

        self::assertIsString($self);
        self::assertSame($self, $response->header('Location'));

        $fetched = RestClient::follow($self, ['accept' => self::JSON_API]);

        self::assertSame(200, $fetched->status);
        self::assertSame('A new pet', $fetched->member('data', 'attributes', 'name'));
    }

    /**
     * `notifyOwner` is declared on the create alone:
     * the fetch the self link leads to would refuse it with 400
     */
    public function testTheSelfLinkOfACreateLeavesOutAParameterOnlyTheCreateReads(): void
    {
        $response = self::send(
            'POST',
            '/pets',
            ['notifyOwner' => 'true', 'include' => 'owner'],
            ['content-type' => self::JSON_API],
            self::pet('A linked pet'),
        );
        $self = $response->member('links', 'self');

        self::assertSame(201, $response->status, $response->body);
        self::assertMatchesContract($response, '/pets', 'POST');
        self::assertIsString($self);
        self::assertStringNotContainsString('notifyOwner', $self);
        self::assertStringContainsString('include=owner', $self);

        $fetched = RestClient::follow($self, ['accept' => self::JSON_API]);

        self::assertSame(200, $fetched->status, $fetched->body);
        self::assertSame('A linked pet', $fetched->member('data', 'attributes', 'name'));
    }

    public function testAnUpdateEvaluatesIfMatch(): void
    {
        $tag = (string) self::send('GET', '/pets/1')->header('ETag');
        $body = '{"data": {"type": "pets", "id": "1", "attributes": {"name": "Changed"}}}';

        $response = self::send('PATCH', '/pets/1', [], ['content-type' => self::JSON_API, 'if-match' => $tag], $body);

        self::assertSame(200, $response->status, $response->body);
        self::assertMatchesContract($response, '/pets/1', 'PATCH');
        self::assertNotSame($tag, $response->header('ETag'));

        $response = self::send('PATCH', '/pets/1', [], ['content-type' => self::JSON_API, 'if-match' => $tag], $body);

        self::assertSame(412, $response->status, $response->body);
        self::assertMatchesContract($response, '/pets/1', 'PATCH');
    }

    public function testADeletionRespondsWithNoContent(): void
    {
        $response = self::send('DELETE', '/shop/orders/' . self::ORDER . '2');

        self::assertSame(204, $response->status, $response->body);
        self::assertSame('', $response->body);
        self::assertNull($response->header('Content-Type'));
        self::assertNull(FixturePlugin::store()->find('orders', self::ORDER . '2'));
        self::assertSame(404, self::send('GET', '/shop/orders/' . self::ORDER . '2')->status);
    }

    public function testAnAdditionARemovalAndAReplacementOfARelationshipRespondWithTheirLinkage(): void
    {
        $identifier = '{"data": [{"type": "orders", "id": "' . self::ORDER . '3"}]}';
        $headers = ['content-type' => self::JSON_API];

        $added = self::send('POST', '/pets/2/relationships/orders', [], $headers, $identifier);

        self::assertSame(200, $added->status, $added->body);
        self::assertMatchesContract($added, '/pets/2/relationships/orders', 'POST');
        self::assertSame(self::ORDER . '3', $added->member('data', 0, 'id'));
        self::assertCount(1, $added->members('data'));

        $removed = self::send('DELETE', '/pets/2/relationships/orders', [], $headers, $identifier);

        self::assertSame(200, $removed->status, $removed->body);
        self::assertSame([], $removed->members('data'));

        $replaced = self::send(
            'PATCH',
            '/pets/1/relationships/owner',
            [],
            $headers,
            '{"data": {"type": "customers", "id": "3"}}',
        );

        self::assertSame(200, $replaced->status, $replaced->body);
        self::assertMatchesContract($replaced, '/pets/1/relationships/owner', 'PATCH');
        self::assertSame('3', $replaced->member('data', 'id'));
    }

    public function testAtomicOperationsCommitTogetherAndAFailingOneLeavesNoWrite(): void
    {
        $headers = ['content-type' => self::ATOMIC, 'accept' => self::ATOMIC];
        $body = '{"atomic:operations": ['
            . '{"op": "add", "data": {"type": "pets", "lid": "a", "attributes": {"name": "Atomic", "listedAt":'
            . ' "2026-09-02T00:00:00Z"}, "relationships": {"owner": {"data": {"type": "customers", "id": "1"}}}}},'
            . '{"op": "add", "ref": {"type": "pets", "lid": "a", "relationship": "orders"}, "data": [{"type":'
            . ' "orders", "id": "'
                . self::ORDER
                . '3"}]}'
            . ']}';

        $response = self::send('POST', '/operations', [], $headers, $body);

        self::assertSame(200, $response->status, $response->body);
        self::assertSame(self::ATOMIC, $response->header('Content-Type'));
        self::assertMatchesContract($response, '/operations', 'POST');
        self::assertCount(4, FixturePlugin::store()->all('pets'));

        $failing = str_replace('"id": "1"}}}}', '"id": "99"}}}}', $body);
        $response = self::send('POST', '/operations', [], $headers, $failing);

        self::assertSame(404, $response->status, $response->body);
        self::assertMatchesContract($response, '/operations', 'POST');
        self::assertCount(4, FixturePlugin::store()->all('pets'));
    }

    /**
     * `/pets/operations` takes pets alone: another type gets 409 and nothing is written
     */
    public function testARouteOfOneTypeRunsItsOperationsAndRefusesAnotherType(): void
    {
        $headers = ['content-type' => self::ATOMIC, 'accept' => self::ATOMIC];
        $pet = '{"atomic:operations": [{"op": "add", "data": {"type": "pets", "attributes": {"name": "Scoped",'
            . ' "listedAt": "2026-09-03T00:00:00Z"}, "relationships": {"owner": {"data": {"type": "customers", "id":'
            . ' "1"}}}}}]}';

        $response = self::send('POST', '/pets/operations', [], $headers, $pet);

        self::assertSame(200, $response->status, $response->body);
        self::assertSame(self::ATOMIC, $response->header('Content-Type'));
        self::assertMatchesContract($response, '/pets/operations', 'POST');
        self::assertCount(4, FixturePlugin::store()->all('pets'));

        $customers = count(FixturePlugin::store()->all('customers'));
        $customer = '{"atomic:operations": [{"op": "add", "data": {"type": "customers", "attributes": {"name":'
            . ' "Refused"}}}]}';
        $response = self::send('POST', '/pets/operations', [], $headers, $customer);

        self::assertSame(409, $response->status, $response->body);
        self::assertMatchesContract($response, '/pets/operations', 'POST');
        self::assertSame('/atomic:operations/0/data/type', $response->member('errors', 0, 'source', 'pointer'));
        self::assertCount($customers, FixturePlugin::store()->all('customers'));
    }

    public function testABodyInAnotherMediaTypeIsRefused(): void
    {
        $response = self::send('POST', '/pets', [], ['content-type' => 'application/json'], self::pet('x'));

        self::assertSame(415, $response->status, $response->body);
        self::assertSame(self::JSON_API, $response->header('Accept'));
        self::assertMatchesContract($response, '/pets', 'POST');
    }

    public function testAWriteOfAClientThatIsNotLoggedInIsForbidden(): void
    {
        wp_set_current_user(0);

        $response = self::send('POST', '/pets', [], ['content-type' => self::JSON_API], self::pet('x'));

        self::assertSame(403, $response->status, $response->body);
        self::assertSame('authentication_required', $response->member('errors', 0, 'code'));
        self::assertNull($response->header('WWW-Authenticate'));
        self::assertMatchesContract($response, '/pets', 'POST');
    }

    /**
     * WordPress refuses a body that is no JSON before any callback;
     * the library takes the request back, so a client that is not logged in
     * learns first that it may not write, as for any other body
     */
    public function testABrokenDocumentOfAClientThatIsNotLoggedInIsForbiddenFirst(): void
    {
        wp_set_current_user(0);

        $response = self::send('POST', '/pets', [], ['content-type' => self::JSON_API], '{"data": ');

        self::assertSame(403, $response->status, $response->body);
        self::assertSame('authentication_required', $response->member('errors', 0, 'code'));
        self::assertMatchesContract($response, '/pets', 'POST');
    }

    public function testTheLibraryRespondsToABrokenDocument(): void
    {
        $response = self::send('POST', '/pets', [], ['content-type' => self::JSON_API], '{"data": ');

        self::assertSame(400, $response->status, $response->body);
        self::assertSame('invalid_json', $response->member('errors', 0, 'code'));
        self::assertMatchesContract($response, '/pets', 'POST');
    }

    private static function pet(string $name): string
    {
        return sprintf(
            '{"data": {"type": "pets", "attributes": {"name": %s, "listedAt": "2026-09-01T00:00:00Z"},'
            . ' "relationships": {"owner": {"data": {"type": "customers", "id": "3"}}}}}',
            json_encode($name, JSON_THROW_ON_ERROR),
        );
    }
}
