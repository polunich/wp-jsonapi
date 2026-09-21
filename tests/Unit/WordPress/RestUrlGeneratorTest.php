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

namespace Polunich\WpJsonApi\Tests\Unit\WordPress;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\Schema\JsonType;
use Polunich\WpJsonApi\Codec\Schema\Schema;
use Polunich\WpJsonApi\Metadata\OperationRoute;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Routing\PathParameter;
use Polunich\WpJsonApi\Routing\UrlGenerator;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\DescribedCodec;
use Polunich\WpJsonApi\Tests\Fixtures\WordPress\CoreFiles;
use Polunich\WpJsonApi\WordPress\RequestFactory;
use Polunich\WpJsonApi\WordPress\RestUrlGenerator;
use Polunich\WpJsonApi\WordPress\RoutePatterns;
use ReflectionClass;
use WP_REST_Request;

/**
 * @internal
 */
#[CoversClass(RestUrlGenerator::class)]
final class RestUrlGeneratorTest extends TestCase
{
    private const PRETTY_ROOT = 'https://example.com/wp-json';

    private const PLAIN_ROOT = 'https://example.com/index.php?rest_route=';

    public function testTheClassIsAFinalReadonlyInternalUrlGenerator(): void
    {
        $class = new ReflectionClass(RestUrlGenerator::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertTrue($class->implementsInterface(UrlGenerator::class));
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testTheSegmentsAreThePathBelowTheNamespace(): void
    {
        $urls = self::pretty();

        self::assertSame(self::PRETTY_ROOT . '/acme/v1/pets', $urls->url(['pets']));
        self::assertSame(
            self::PRETTY_ROOT . '/acme/v1/database/tables/1/relationships/tags',
            $urls->url(['database', 'tables', '1', 'relationships', 'tags']),
        );
    }

    /**
     * a member name may hold a space
     */
    public function testEverySegmentIsPercentEncoded(): void
    {
        self::assertSame(
            self::PRETTY_ROOT . '/acme/v1/my%20pets/a%2Fb%3Fc%23d%25e',
            self::pretty()->url(['my pets', 'a/b?c#d%e']),
        );
    }

    public function testTheQueryIsPercentEncodedAsRfc3986WritesIt(): void
    {
        self::assertSame(
            self::PRETTY_ROOT . '/acme/v1/pets?page%5Bnumber%5D=2&filter%5Bname%5D=a%20b%2Bc&0=x',
            self::pretty()->url(
                ['pets'],
                ['page' => ['number' => '2'], 'filter' => ['name' => 'a b+c'], 0 => 'x'],
            ),
        );
    }

    public function testASegmentOfTheValueOfAParameterIsEncodedWhole(): void
    {
        self::assertSame(
            self::PRETTY_ROOT . '/acme/v1/customers/a%2Fb/favorite-pet?page%5Bsize%5D=2',
            self::pretty()->url(['customers', 'a/b', 'favorite-pet'], ['page' => ['size' => '2']]),
        );
        self::assertSame(
            self::PLAIN_ROOT . '/acme/v1/customers/a%252Fb/favorite-pet',
            self::plain()->url(['customers', 'a/b', 'favorite-pet']),
        );
    }

    public function testWithoutPrettyPermalinksThePathIsTheValueOfRestRoute(): void
    {
        self::assertSame(
            self::PLAIN_ROOT . '/acme/v1/pets/a%252Fb&page%5Bnumber%5D=2',
            self::plain()->url(['pets', 'a/b'], ['page' => ['number' => '2']]),
        );
    }

    public function testWithPrettyPermalinksRestUrlIsAskedOncePerUrl(): void
    {
        $asked = [];
        $urls = new RestUrlGenerator('acme/v1', static function (string $path) use (&$asked): string {
            $asked[] = $path;

            return self::PRETTY_ROOT . $path;
        });

        $urls->url(['pets', 'a/b']);

        self::assertSame(['/acme/v1/pets/a%2Fb'], $asked);
    }

    /**
     * a `rest_url` filter may add a query argument of its own
     */
    public function testAQueryArgumentAFilterAddsLeavesThePermalinkForm(): void
    {
        $pretty = new RestUrlGenerator('acme/v1', static function (string $path): string {
            return self::PRETTY_ROOT . $path . '?lang=uk';
        });
        $plain = new RestUrlGenerator('acme/v1', static function (string $path): string {
            return self::PLAIN_ROOT . $path . '&lang=uk';
        });

        self::assertSame(
            self::PRETTY_ROOT . '/acme/v1/pets/a%2Fb?lang=uk&page%5Bnumber%5D=2',
            $pretty->url(['pets', 'a/b'], ['page' => ['number' => '2']]),
        );
        self::assertSame(
            self::PLAIN_ROOT . '/acme/v1/pets/a%252Fb&lang=uk&page%5Bnumber%5D=2',
            $plain->url(['pets', 'a/b'], ['page' => ['number' => '2']]),
        );
    }

    /**
     * replays how WordPress routes a link: with pretty permalinks the path as it was sent,
     * without them the `rest_route` value as PHP decodes it,
     * then the normal form the library writes before WordPress matches the route.
     * the codec admits any segment
     */
    #[DataProvider('ids')]
    public function testAnIdTravelsThroughWordPressAndBack(string $id): void
    {
        CoreFiles::load();

        $patterns = new RoutePatterns('acme/v1', [
            new OperationRoute(
                OperationKind::FetchResource,
                'pets',
                null,
                new PathParameter('id', new DescribedCodec(new Schema(type: JsonType::String))),
                ['pets'],
            ),
        ]);
        $pattern = array_key_last($patterns->all());

        $pretty = substr((string) parse_url(self::pretty()->url(['pets', $id]), PHP_URL_PATH), strlen('/wp-json'));
        $query = [];
        parse_str((string) parse_url(self::plain()->url(['pets', $id]), PHP_URL_QUERY), $query);
        $plain = $query['rest_route'] ?? null;

        self::assertIsString($plain);

        foreach ([$pretty, $plain] as $route) {
            $route = $patterns->normalizedRoute($route) ?? $route;
            $matches = [];

            self::assertSame(1, preg_match('@^/acme/v1' . $pattern . '$@i', $route, $matches));

            $request = new WP_REST_Request('GET', $route);
            $request->set_url_params(['id' => $matches['id']]);

            self::assertSame(['id' => $id], (new RequestFactory())->fromWordPress($request)->path);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ids(): iterable
    {
        yield 'a slash' => ['a/b'];
        yield 'a space' => ['a b'];
        yield 'a plus' => ['a+b'];
        yield 'a percent sign' => ['100%'];
        yield 'an encoded text' => ['a%2Fb'];
        yield 'a quote' => ["o'b"];
        yield 'the delimiters of a query' => ['a?b#c&d=e'];
        yield 'a letter outside ASCII' => ["\u{e9}"];
    }

    private static function pretty(): RestUrlGenerator
    {
        return new RestUrlGenerator('acme/v1', static function (string $path): string {
            return self::PRETTY_ROOT . $path;
        });
    }

    private static function plain(): RestUrlGenerator
    {
        return new RestUrlGenerator('acme/v1', static function (string $path): string {
            return self::PLAIN_ROOT . $path;
        });
    }
}
