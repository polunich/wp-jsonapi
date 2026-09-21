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
use stdClass;

/**
 * @internal
 */
#[CoversNothing]
final class ReadRoutesTest extends WordPressTestCase
{
    private const CURSOR = 'application/vnd.api+json;'
        . 'profile="http://jsonapi.org/profiles/ethanresnick/cursor-pagination/"';

    public function testACollectionIsADocumentOfTheContract(): void
    {
        $response = self::send(
            'GET',
            '/pets',
            ['include' => 'owner,orders.customer', 'sort' => 'owner.name,-listedAt'],
        );

        self::assertSame(200, $response->status, $response->body);
        self::assertSame(self::JSON_API, $response->header('Content-Type'));
        self::assertSame('Accept, Accept-Language', $response->header('Vary'));
        self::assertMatchesContract($response, '/pets', 'GET');
        self::assertSame(['3', '1', '2'], array_map(static function (int $index) use ($response): mixed {
            return $response->member('data', $index, 'id');
        }, array_keys($response->members('data'))));
    }

    /**
     * the parameters a route declares reach the store, and the self link carries them;
     * a parameter another route declares is refused
     */
    public function testTheQueryParametersOfARouteReachTheStore(): void
    {
        $pets = self::send('GET', '/pets', ['namePrefix' => 'Re']);

        self::assertSame(200, $pets->status, $pets->body);
        self::assertMatchesContract($pets, '/pets', 'GET');
        self::assertSame(['1'], array_map(static function (int $index) use ($pets): mixed {
            return $pets->member('data', $index, 'id');
        }, array_keys($pets->members('data'))));
        $self = $pets->member('links', 'self');

        self::assertIsString($self);
        self::assertStringContainsString('namePrefix=Re', $self);

        $orders = self::send('GET', '/pets/1/orders', ['placedAfter' => '2026-01-03T00:00:00Z']);

        self::assertSame(200, $orders->status, $orders->body);
        self::assertMatchesContract($orders, '/pets/{id}/orders', 'GET');
        self::assertCount(1, $orders->members('data'));

        $refused = self::send('GET', '/pets', ['placedAfter' => '2026-01-03T00:00:00Z']);

        self::assertSame(400, $refused->status, $refused->body);
        self::assertMatchesContract($refused, '/pets', 'GET');
        self::assertSame('placedAfter', $refused->member('errors', 0, 'source', 'parameter'));
    }

    public function testEveryLinkIsFetchableWithPlainPermalinks(): void
    {
        $this->permalinks('');

        $links = self::links(self::send('GET', '/pets', ['include' => 'owner,orders']));

        self::assertNotSame([], $links);

        foreach ($links as $link) {
            self::assertStringContainsString('?rest_route=', $link);
            self::assertFetchable($link);
        }
    }

    public function testEveryLinkIsFetchableWithPrettyPermalinks(): void
    {
        $this->permalinks('/%postname%/');

        $links = self::links(self::send('GET', '/pets', ['include' => 'owner,orders']));

        self::assertNotSame([], $links);

        foreach ($links as $link) {
            self::assertStringStartsWith('http://' . WP_TESTS_DOMAIN . '/' . rest_get_url_prefix() . '/', $link);
            self::assertFetchable($link);
        }
    }

    /**
     * WordPress routes the decoded `PATH_INFO` there,
     * which the library encodes back into the form of its links
     */
    public function testEveryLinkIsFetchableWithIndexPermalinks(): void
    {
        $this->rewrite('/index.php/%postname%/');

        $links = self::links(self::send('GET', '/pets', ['include' => 'owner,orders']));

        self::assertNotSame([], $links);

        foreach ($links as $link) {
            self::assertStringStartsWith(
                'http://' . WP_TESTS_DOMAIN . '/index.php/' . rest_get_url_prefix() . '/',
                $link,
            );
            self::assertFetchable($link);
        }
    }

    public function testAnIdSentPercentEncodedReachesItsResource(): void
    {
        $response = self::send('GET', '/pets/%31');

        self::assertSame(200, $response->status, $response->body);
        self::assertSame('1', $response->member('data', 'id'));
        self::assertMatchesContract($response, '/pets/1', 'GET');
    }

    public function testACursorPaginationNamesItsProfile(): void
    {
        $response = self::send('GET', '/pets', ['page' => ['size' => '1']], ['accept' => self::CURSOR]);
        $ids = [];

        for ($pages = 0; $pages < 5; ++$pages) {
            self::assertSame(200, $response->status, $response->body);
            self::assertSame(self::CURSOR, $response->header('Content-Type'));
            self::assertMatchesContract($response, '/pets', 'GET');

            $ids[] = $response->member('data', 0, 'id');
            $next = $response->member('links', 'next');

            if (!is_string($next)) {
                break;
            }

            $response = RestClient::follow($next, ['accept' => self::CURSOR]);
        }

        self::assertSame(['3', '2', '1'], $ids);
    }

    /**
     * the pets 1 and 3 in the default sort, the latest listed first
     */
    public function testACollectionIsFilteredByTheIdsOfItsResources(): void
    {
        $response = self::send('GET', '/pets', ['filter' => ['id' => ['@in' => ['1', '3']]]]);

        self::assertSame(200, $response->status, $response->body);
        self::assertMatchesContract($response, '/pets', 'GET');
        self::assertSame(['3', '1'], self::ids($response));
    }

    /**
     * customer 1 owns the pets 1 and 3, customer 2 the pet 2
     */
    public function testACollectionIsFilteredByTheIdOfARelatedResource(): void
    {
        $one = self::send('GET', '/pets', ['filter' => ['owner' => ['id' => ['@eq' => '1']]]]);
        $list = self::send('GET', '/pets', ['filter' => ['owner' => ['id' => ['@in' => ['2']]]]]);

        self::assertSame(200, $one->status, $one->body);
        self::assertMatchesContract($one, '/pets', 'GET');
        self::assertSame(['3', '1'], self::ids($one));
        self::assertSame(200, $list->status, $list->body);
        self::assertSame(['2'], self::ids($list));
    }

    /**
     * the cursor of each page carries the id of the owner, and `id` orders the pets of one owner
     */
    public function testACursorPaginationSortsByTheIdOfARelatedResource(): void
    {
        $response = self::send(
            'GET',
            '/pets',
            ['sort' => '-owner.id', 'page' => ['size' => '1']],
            ['accept' => self::CURSOR],
        );
        $ids = [];

        for ($pages = 0; $pages < 5; ++$pages) {
            self::assertSame(200, $response->status, $response->body);
            self::assertMatchesContract($response, '/pets', 'GET');

            $ids = [...$ids, ...self::ids($response)];
            $next = $response->member('links', 'next');

            if (!is_string($next)) {
                break;
            }

            $response = RestClient::follow($next, ['accept' => self::CURSOR]);
        }

        self::assertSame(['2', '1', '3'], $ids);
    }

    /**
     * an excluded read stays registered, so the library responds to it with its own 403
     * where WordPress would respond to a route it does not know with 404
     */
    public function testAnExcludedReadIsForbidden(): void
    {
        $collection = self::send('GET', '/shop/orders');
        $related = self::send('GET', '/pets/1/owner');

        self::assertSame(403, $collection->status, $collection->body);
        self::assertSame(self::JSON_API, $collection->header('Content-Type'));
        self::assertSame('operation_not_supported', $collection->member('errors', 0, 'code'));
        self::assertSame(403, $related->status, $related->body);
        self::assertSame('relationship_fetch_not_supported', $related->member('errors', 0, 'code'));
    }

    /**
     * the owner of a pet keeps its relationship route alone,
     * so its relationship object has a self link and no related link
     */
    public function testARelationshipLinksTheRoutesItKeeps(): void
    {
        $response = self::send('GET', '/pets/1');

        self::assertSame(200, $response->status, $response->body);
        self::assertIsString($response->member('data', 'relationships', 'owner', 'links', 'self'));
        self::assertNull($response->member('data', 'relationships', 'owner', 'links', 'related'));
        self::assertIsString($response->member('data', 'relationships', 'orders', 'links', 'related'));
        self::assertMatchesContract($response, '/pets/1', 'GET');
    }

    public function testAMissingResourceIsNotFound(): void
    {
        $response = self::send('GET', '/pets/99');

        self::assertSame(404, $response->status);
        self::assertSame(self::JSON_API, $response->header('Content-Type'));
        self::assertMatchesContract($response, '/pets/99', 'GET');
    }

    public function testANotModifiedResponseCarriesNoContentType(): void
    {
        $response = self::send('GET', '/pets/1');
        $tag = (string) $response->header('ETag');

        self::assertSame(200, $response->status);
        self::assertNotSame('', $tag);
        self::assertMatchesContract($response, '/pets/1', 'GET');

        $response = self::send('GET', '/pets/1', [], ['if-none-match' => $tag]);

        self::assertSame(304, $response->status);
        self::assertSame('', $response->body);
        self::assertNull($response->header('Content-Type'));
        self::assertSame($tag, $response->header('ETag'));
    }

    /**
     * WordPress, not the library, drops the content of a `HEAD` response
     */
    public function testHeadIsGetWithoutContent(): void
    {
        $response = self::send('HEAD', '/pets/1');

        self::assertSame(200, $response->status);
        self::assertSame('', $response->body);
        self::assertSame(self::JSON_API, $response->header('Content-Type'));
    }

    /**
     * WordPress applies `_fields` after dispatch; the library refuses it as an unknown parameter
     */
    public function testAReservedParameterOfWordPressThatChangesTheResponseIsRefused(): void
    {
        $response = self::send('GET', '/pets', ['_fields' => 'id']);

        self::assertSame(400, $response->status, $response->body);
        self::assertSame('_fields', $response->member('errors', 0, 'source', 'parameter'));
        self::assertMatchesContract($response, '/pets', 'GET');
    }

    /**
     * `uiLocale` decides over `Accept-Language`, so `Vary` does not name the header field,
     * and every link carries the parameter on to a route that takes it
     */
    #[DataProvider('permalinkForms')]
    public function testTheLanguageParameterTravelsOnEveryLink(string $structure, bool $rewritten): void
    {
        if ($rewritten) {
            $this->rewrite($structure);
        } else {
            $this->permalinks($structure);
        }

        $response = self::send(
            'GET',
            '/pets',
            ['include' => 'owner,orders', 'uiLocale' => 'en-GB'],
            ['accept-language' => 'uk'],
        );
        $links = self::links($response);

        self::assertSame(200, $response->status, $response->body);
        self::assertSame('Accept', $response->header('Vary'));
        self::assertMatchesContract($response, '/pets', 'GET');
        self::assertNotSame([], $links);

        foreach ($links as $link) {
            self::assertStringContainsString('uiLocale=en-GB', $link);
            self::assertStringNotContainsString('include=', $link);

            $followed = RestClient::follow($link, ['accept' => self::JSON_API]);

            self::assertSame(200, $followed->status, $link . "\n" . $followed->body);
            self::assertSame('Accept', $followed->header('Vary'), $link);
        }
    }

    /**
     * an `index.php/` structure goes into the rewrite rules too, the others into the option alone
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function permalinkForms(): iterable
    {
        yield 'plain permalinks' => ['', false];
        yield 'pretty permalinks' => ['/%postname%/', false];
        yield 'index permalinks' => ['/index.php/%postname%/', true];
    }

    /**
     * @param array<array-key, mixed> $query
     */
    #[DataProvider('badLanguageValues')]
    public function testABadValueOfTheLanguageParameterIsABadRequest(array $query): void
    {
        $response = self::send('GET', '/pets/1', $query, ['accept-language' => 'uk']);

        self::assertSame(400, $response->status, $response->body);
        self::assertSame('invalid_query_parameter', $response->member('errors', 0, 'code'));
        self::assertSame('uiLocale', $response->member('errors', 0, 'source', 'parameter'));
        self::assertSame('Accept', $response->header('Vary'));
        self::assertMatchesContract($response, '/pets/1', 'GET');
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function badLanguageValues(): iterable
    {
        yield 'the empty value' => [['uiLocale' => '']];
        yield 'a low line' => [['uiLocale' => 'en_GB']];
        yield 'brackets' => [['uiLocale' => ['uk']]];
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

    /**
     * every self and related link, each once
     *
     * @return list<string>
     */
    private static function links(RestResponse $response): array
    {
        $links = [];

        foreach (['data', 'included'] as $member) {
            foreach (array_keys($response->members($member)) as $index) {
                $links[] = $response->member($member, $index, 'links', 'self');
                $relationships = $response->member($member, $index, 'relationships');

                foreach (array_keys($relationships instanceof stdClass ? get_object_vars(
                    $relationships,
                ) : []) as $name) {
                    $links[] = $response->member($member, $index, 'relationships', (string) $name, 'links', 'self');
                    $links[] = $response->member($member, $index, 'relationships', (string) $name, 'links', 'related');
                }
            }
        }

        return array_values(array_unique(array_filter($links, is_string(...))));
    }
}
