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

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Polunich\WpJsonApi\Tests\Integration\RestClient;
use Polunich\WpJsonApi\Tests\Integration\RestResponse;
use Polunich\WpJsonApi\Tests\Integration\WordPressTestCase;

/**
 * @internal
 */
#[CoversNothing]
final class EndpointRoutesTest extends WordPressTestCase
{
    private const ORDER = '00000000-0000-4000-8000-00000000000';

    private const CURSOR = 'application/vnd.api+json;'
        . 'profile="http://jsonapi.org/profiles/ethanresnick/cursor-pagination/"';

    public function testAnEndpointRespondsInPlaceOfAnExcludedRead(): void
    {
        $customer = self::send('GET', '/customers/2');
        $ofOrder = self::send('GET', '/shop/orders/' . self::ORDER . '1/customer');

        self::assertSame(200, $customer->status, $customer->body);
        self::assertSame(self::JSON_API, $customer->header('Content-Type'));
        self::assertSame('Grace Hopper', $customer->member('data', 'attributes', 'name'));
        self::assertMatchesContract($customer, '/customers/2', 'GET');
        self::assertSame(200, $ofOrder->status, $ofOrder->body);
        self::assertSame('2', $ofOrder->member('data', 'id'));
        self::assertMatchesContract($ofOrder, '/shop/orders/' . self::ORDER . '1/customer', 'GET');
    }

    /**
     * a link to a read keeps pointing at its route once an endpoint responds there
     */
    public function testTheLinksToAReplacedReadAreFetchable(): void
    {
        $order = self::send('GET', '/shop/orders/' . self::ORDER . '1', ['include' => 'customer']);
        $related = $order->member('data', 'relationships', 'customer', 'links', 'related');
        $self = $order->member('included', 0, 'links', 'self');

        self::assertSame(200, $order->status, $order->body);
        self::assertIsString($related);
        self::assertIsString($self);
        self::assertFetchable($related);
        self::assertFetchable($self);
    }

    /**
     * the type declares the path `/shop/orders`, and documents keep the type `orders`
     */
    public function testTheLinksOfATypeLieBelowThePathItDeclares(): void
    {
        $order = self::send('GET', '/shop/orders/' . self::ORDER . '1');
        $self = $order->member('data', 'links', 'self');
        $related = $order->member('data', 'relationships', 'customer', 'links', 'related');

        self::assertSame(200, $order->status, $order->body);
        self::assertSame('orders', $order->member('data', 'type'));
        self::assertIsString($self);
        self::assertIsString($related);
        self::assertStringEndsWith('/fixture/v1/shop/orders/' . self::ORDER . '1', $self);
        self::assertStringEndsWith('/fixture/v1/shop/orders/' . self::ORDER . '1/customer', $related);
        self::assertFetchable($self);
    }

    /**
     * customer 1 owns Rex and Nemo, one to a page and by name backwards by default;
     * the pagination links lead to the URL of the endpoint
     */
    public function testAPageOfAnEndpointWalksTheCollectionItsHandlerSelects(): void
    {
        $response = self::send('GET', '/customers/1/pets');
        $ids = [];

        for ($pages = 0; $pages < 3; ++$pages) {
            self::assertSame(200, $response->status, $response->body);
            self::assertMatchesContract($response, '/customers/1/pets', 'GET');
            self::assertSame(2, $response->member('meta', 'page', 'total'));

            $ids = [...$ids, ...self::ids($response)];
            $next = $response->member('links', 'next');

            if (!is_string($next)) {
                break;
            }

            self::assertStringContainsString('/fixture/v1/customers/1/pets', $next);
            $response = RestClient::follow($next, ['accept' => self::JSON_API]);
        }

        self::assertSame(['1', '3'], $ids);
    }

    public function testAPageOfAnEndpointIsFilteredAndSortedAsTheRequestAsks(): void
    {
        $sorted = self::send('GET', '/customers/1/pets', ['sort' => 'name', 'page' => ['size' => '2']]);
        $filtered = self::send('GET', '/customers/1/pets', ['filter' => ['name' => ['@eq' => 'Rex']]]);

        self::assertSame(200, $sorted->status, $sorted->body);
        self::assertSame(['3', '1'], self::ids($sorted));
        self::assertSame(200, $filtered->status, $filtered->body);
        self::assertSame(['1'], self::ids($filtered));
        self::assertMatchesContract($filtered, '/customers/1/pets', 'GET');
    }

    public function testAPageOfAnEndpointPagesByCursorUnderTheCursorProfile(): void
    {
        $response = self::send('GET', '/customers/1/pets', [], ['accept' => self::CURSOR]);
        $ids = [];

        for ($pages = 0; $pages < 3; ++$pages) {
            self::assertSame(200, $response->status, $response->body);
            self::assertSame(self::CURSOR, $response->header('Content-Type'));

            $ids = [...$ids, ...self::ids($response)];
            $next = $response->member('links', 'next');

            if (!is_string($next)) {
                break;
            }

            $response = RestClient::follow($next, ['accept' => self::CURSOR]);
        }

        self::assertSame(['1', '3'], $ids);
    }

    public function testAPageOfAnEndpointRefusesASizeAboveItsMaximum(): void
    {
        $response = self::send('GET', '/customers/1/pets', ['page' => ['size' => '11']]);

        self::assertSame(400, $response->status, $response->body);
        self::assertSame('max_page_size_exceeded', $response->member('errors', 0, 'code'));
        self::assertMatchesContract($response, '/customers/1/pets', 'GET');
    }

    public function testARecordAnEndpointBindsIsNotFoundWhereItIsMissing(): void
    {
        $response = self::send('GET', '/customers/99');

        self::assertSame(404, $response->status, $response->body);
        self::assertSame(self::JSON_API, $response->header('Content-Type'));
        self::assertMatchesContract($response, '/customers/99', 'GET');
    }

    /**
     * the adoption is conditional on the version of the pet, which its response carries as `ETag`
     */
    public function testAWriteOfAnEndpointIsConditionalOnTheVersionOfItsRecord(): void
    {
        wp_set_current_user(1);
        $tag = (string) self::send('GET', '/pets/2')->header('ETag');
        $body = '{"data": {"type": "customers", "id": "1"}}';

        $adopted = self::send(
            'POST',
            '/pets/2/adopt',
            [],
            ['content-type' => self::JSON_API, 'if-match' => $tag],
            $body,
        );
        $stale = self::send('POST', '/pets/2/adopt', [], ['content-type' => self::JSON_API, 'if-match' => $tag], $body);

        self::assertSame(200, $adopted->status, $adopted->body);
        self::assertMatchesContract($adopted, '/pets/2/adopt', 'POST');
        self::assertNotSame($tag, $adopted->header('ETag'));
        self::assertSame('1', self::send('GET', '/pets/2/relationships/owner')->member('data', 'id'));
        self::assertSame(412, $stale->status, $stale->body);
        self::assertMatchesContract($stale, '/pets/2/adopt', 'POST');
    }

    public function testAWriteOfAnEndpointAsksItsPermission(): void
    {
        $response = self::send(
            'POST',
            '/pets/2/adopt',
            [],
            ['content-type' => self::JSON_API],
            '{"data": {"type": "customers", "id": "1"}}',
        );

        self::assertSame(403, $response->status, $response->body);
        self::assertSame(self::JSON_API, $response->header('Content-Type'));
        self::assertMatchesContract($response, '/pets/2/adopt', 'POST');
    }

    /**
     * the self link is the URL of the endpoint with its query, in the form of the site's permalinks
     */
    #[DataProvider('permalinkForms')]
    public function testTheSelfLinkOfAGetEndpointIsFetchable(string $structure, bool $rewritten, string $form): void
    {
        if ($rewritten) {
            $this->rewrite($structure);
        } else {
            $this->permalinks($structure);
        }

        $response = self::send('GET', '/pets/recent', ['listedAfter' => '2026-02-01T00:00:00Z']);
        $self = $response->member('links', 'self');

        self::assertSame(200, $response->status, $response->body);
        self::assertSame(['2', '3'], [$response->member('data', 0, 'id'), $response->member('data', 1, 'id')]);
        self::assertMatchesContract($response, '/pets/recent', 'GET');
        self::assertIsString($self);
        self::assertStringContainsString($form, $self);
        self::assertStringContainsString('listedAfter=2026-02-01T00%3A00%3A00Z', $self);
        self::assertSame($response->body, RestClient::follow($self, ['accept' => self::JSON_API])->body);
    }

    /**
     * @return iterable<string, array{string, bool, string}>
     */
    public static function permalinkForms(): iterable
    {
        yield 'plain permalinks' => ['', false, '?rest_route=/fixture/v1/pets/recent&'];
        yield 'pretty permalinks' => ['/%postname%/', false, '/fixture/v1/pets/recent?'];
        yield 'index permalinks' => ['/index.php/%postname%/', true, '/index.php/'];
    }

    /**
     * an endpoint takes the language parameter beside its own, and its links carry it on
     */
    public function testAnEndpointTakesTheLanguageParameter(): void
    {
        $response = self::send('GET', '/pets/recent', ['listedAfter' => '2026-02-01T00:00:00Z', 'uiLocale' => 'uk']);

        $self = $response->member('links', 'self');
        $pet = $response->member('data', 0, 'links', 'self');

        self::assertSame(200, $response->status, $response->body);
        self::assertSame('Accept', $response->header('Vary'));
        self::assertMatchesContract($response, '/pets/recent', 'GET');
        self::assertIsString($self);
        self::assertIsString($pet);
        self::assertStringContainsString('uiLocale=uk', $self);
        self::assertStringContainsString('uiLocale=uk', $pet);
        self::assertStringNotContainsString('listedAfter', $pet);
    }

    public function testARequiredQueryParameterThatIsMissingIsABadRequest(): void
    {
        $response = self::send('GET', '/pets/recent');

        self::assertSame(400, $response->status, $response->body);
        self::assertSame('missing_query_parameter', $response->member('errors', 0, 'code'));
        self::assertSame('listedAfter', $response->member('errors', 0, 'source', 'parameter'));
        self::assertMatchesContract($response, '/pets/recent', 'GET');
    }

    public function testAnEndpointOfMetaAloneRespondsWithADocumentOfMeta(): void
    {
        $response = self::send('GET', '/stats');

        self::assertSame(200, $response->status, $response->body);
        self::assertSame(['pets' => 3, 'customers' => 3], (array) $response->member('meta'));
        self::assertNull($response->member('data'));
        self::assertMatchesContract($response, '/stats', 'GET');
    }

    public function testAnExportIsWrittenInTheRepresentationAcceptPicked(): void
    {
        $csv = self::send('GET', '/pets/export', [], ['accept' => 'text/csv']);
        $document = self::send('GET', '/pets/export');

        self::assertSame(200, $csv->status, $csv->body);
        self::assertSame('text/csv', $csv->header('Content-Type'));
        self::assertSame('attachment; filename="pets.csv"', $csv->header('Content-Disposition'));
        self::assertSame("id,name\n1,Rex\n2,Whiskers\n3,Nemo\n", $csv->body);
        self::assertMatchesContract($csv, '/pets/export', 'GET');
        self::assertSame(200, $document->status, $document->body);
        self::assertSame(self::JSON_API, $document->header('Content-Type'));
        self::assertMatchesContract($document, '/pets/export', 'GET');
    }

    public function testAFileIsWrittenAsItLiesOnDisk(): void
    {
        $photo = self::send('GET', '/pets/1/photo', [], ['accept' => 'image/png']);

        self::assertSame(200, $photo->status, $photo->body);
        self::assertSame('image/png', $photo->header('Content-Type'));
        self::assertSame(file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Api/rex.png'), $photo->body);
        self::assertMatchesContract($photo, '/pets/1/photo', 'GET');
    }

    public function testHeadOfAContentIsItsGetWithoutContent(): void
    {
        $get = self::send('GET', '/pets/export', [], ['accept' => 'text/csv']);
        $head = self::send('HEAD', '/pets/export', [], ['accept' => 'text/csv']);

        self::assertSame(200, $head->status);
        self::assertSame('', $head->body);
        self::assertSame($get->header('Content-Type'), $head->header('Content-Type'));
        self::assertSame($get->header('Content-Disposition'), $head->header('Content-Disposition'));
    }

    public function testNoAcceptableRepresentationIsNotAcceptable(): void
    {
        $response = self::send('GET', '/pets/export', [], ['accept' => 'image/png']);

        self::assertSame(406, $response->status, $response->body);
        self::assertSame(self::JSON_API, $response->header('Content-Type'));
        self::assertMatchesContract($response, '/pets/export', 'GET');
    }

    public function testAWebhookReadsJsonAndRespondsWithJson(): void
    {
        $headers = ['content-type' => 'application/json', 'accept' => 'application/json'];

        $paid = self::send('POST', '/webhooks/payments', [], $headers, '{"id": "pay_1", "amount": 1200}');
        $wrong = self::send('POST', '/webhooks/payments', [], $headers, '{"id": "pay_1", "amount": "twelve"}');

        self::assertSame(200, $paid->status, $paid->body);
        self::assertSame('application/json', $paid->header('Content-Type'));
        self::assertSame('{"received":"pay_1"}', $paid->body);
        self::assertMatchesContract($paid, '/webhooks/payments', 'POST');
        self::assertSame(422, $wrong->status, $wrong->body);
        self::assertSame('/amount', $wrong->member('errors', 0, 'source', 'pointer'));
        self::assertMatchesContract($wrong, '/webhooks/payments', 'POST');
    }

    public function testTheLibraryRespondsToAWebhookThatIsNoJson(): void
    {
        $response = self::send(
            'POST',
            '/webhooks/payments',
            [],
            ['content-type' => 'application/json', 'accept' => 'application/json'],
            '{"id": ',
        );

        self::assertSame(400, $response->status, $response->body);
        self::assertSame('invalid_json', $response->member('errors', 0, 'code'));
        self::assertMatchesContract($response, '/webhooks/payments', 'POST');
    }

    public function testContentOfAMediaTypeAnEndpointDoesNotReadNamesTheOnesItReads(): void
    {
        $response = self::send(
            'POST',
            '/webhooks/payments',
            [],
            ['content-type' => 'text/plain', 'accept' => 'application/json'],
            'paid',
        );

        self::assertSame(415, $response->status, $response->body);
        self::assertSame('application/json', $response->header('Accept'));
        self::assertMatchesContract($response, '/webhooks/payments', 'POST');
    }

    /**
     * PHP takes a multipart form apart into `$_POST` and `$_FILES`, and its body reaches no one
     */
    public function testAnUploadReadsTheFieldsAndFilesPhpStored(): void
    {
        wp_set_current_user(1);
        $photo = (string) tempnam(sys_get_temp_dir(), 'php');
        file_put_contents($photo, (string) file_get_contents(dirname(__DIR__, 2) . '/Fixtures/Api/rex.png'));
        $files = ['photo' => [
            'name' => 'rex.png',
            'full_path' => 'rex.png',
            'type' => 'image/png',
            'tmp_name' => $photo,
            'error' => UPLOAD_ERR_OK,
            'size' => 70,
        ]];
        $headers = ['content-type' => 'multipart/form-data; boundary=x', 'content-length' => '480'];

        try {
            $added = self::send('POST', '/pets/1/photos', [], $headers, '', ['caption' => 'Rex at the park'], $files);
            $uncaptioned = self::send('POST', '/pets/1/photos', [], $headers, '', [], $files);
        } finally {
            unlink($photo);
        }

        self::assertSame(204, $added->status, $added->body);
        self::assertMatchesContract($added, '/pets/1/photos', 'POST');
        self::assertSame(422, $uncaptioned->status, $uncaptioned->body);
        self::assertSame('missing_form_field', $uncaptioned->member('errors', 0, 'code'));
        self::assertSame('caption', $uncaptioned->member('errors', 0, 'meta', 'field'));
        self::assertMatchesContract($uncaptioned, '/pets/1/photos', 'POST');
    }

    public function testHeadOfAGetEndpointIsGetWithoutContent(): void
    {
        $response = self::send('HEAD', '/stats');

        self::assertSame(200, $response->status);
        self::assertSame('', $response->body);
        self::assertSame(self::JSON_API, $response->header('Content-Type'));
    }

    /**
     * the ids of the primary data, in their order
     *
     * @return list<mixed>
     */
    private static function ids(RestResponse $response): array
    {
        return array_map(static function (int $index) use ($response): mixed {
            return $response->member('data', $index, 'id');
        }, array_keys($response->members('data')));
    }
}
