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

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\DigitsCodec;
use Polunich\WpJsonApi\Codec\Schema\JsonType;
use Polunich\WpJsonApi\Codec\Schema\Schema;
use Polunich\WpJsonApi\Codec\SegmentCodec;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseShapeKind;
use Polunich\WpJsonApi\Metadata\EndpointMetadata;
use Polunich\WpJsonApi\Metadata\EndpointRoute;
use Polunich\WpJsonApi\Metadata\OperationRoute;
use Polunich\WpJsonApi\Metadata\Route;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Routing\PathParameter;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\DescribedCodec;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use Polunich\WpJsonApi\WordPress\RoutePatterns;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(RoutePatterns::class)]
final class RoutePatternsTest extends TestCase
{
    private const DIGITS = '(?=(?:0|[1-9][0-9]*)(?:/|\z))[^/]+';

    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(RoutePatterns::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testATemplateIsAPatternAndTheRoutesOfOneTemplateShareIt(): void
    {
        $fetch = self::route(OperationKind::FetchCollection);
        $create = self::route(OperationKind::CreateResource);
        $read = self::route(OperationKind::FetchResource);
        $related = self::route(OperationKind::FetchRelationship, 'tags');

        $patterns = new RoutePatterns('acme/v1', [$fetch, $create, $read, $related]);

        self::assertSame('acme/v1', $patterns->namespace);
        self::assertSame([
            '/pets' => [$fetch, $create],
            '/pets/(?P<id>' . self::DIGITS . ')' => [$read],
            '/pets/(?P<id>' . self::DIGITS . ')/relationships/tags' => [$related],
        ], $patterns->all());
    }

    public function testTheRoutesOfATypeLieAtThePatternOfItsPath(): void
    {
        $collection = new OperationRoute(OperationKind::FetchCollection, 'tables', null, null, ['database', 'tables']);
        $resource = new OperationRoute(
            OperationKind::FetchResource,
            'tables',
            null,
            new PathParameter('id', new DigitsCodec()),
            ['database', 'tables'],
        );

        $patterns = new RoutePatterns('acme/v1', [$collection, $resource]);

        self::assertSame(
            ['/database/tables', '/database/tables/(?P<id>' . self::DIGITS . ')'],
            array_keys($patterns->all()),
        );
        self::assertSame($resource, $patterns->routeFor('/acme/v1/database/tables/7', 'GET'));
    }

    public function testALiteralStandsEncodedAndUnescaped(): void
    {
        $patterns = new RoutePatterns('acme/v1', [
            self::route(OperationKind::FetchCollection, type: 'pet toys'),
            self::route(OperationKind::FetchCollection, type: 'pet-toys'),
            self::route(OperationKind::FetchCollection, type: "caf\u{e9}"),
            self::route(OperationKind::FetchCollection, type: 'a~b_c'),
        ]);

        self::assertSame(['/pet%20toys', '/pet-toys', '/caf%C3%A9', '/a~b_c'], array_keys($patterns->all()));
        $route = $patterns->routeFor('/acme/v1/a~b_c', 'GET');

        self::assertInstanceOf(OperationRoute::class, $route);
        self::assertSame(OperationKind::FetchCollection, $route->operationKind);
    }

    public function testALiteralWithACharacterOfPatternSyntaxIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the route "/export.csv" holds the literal "export.csv", which is no pattern of its own text: WordPress'
            . ' matches a route by its pattern and publishes the pattern of a route without parameters as its link,'
            . ' so a literal holds letters, digits, "-", "_" and "~" once encoded',
        );

        new RoutePatterns('acme/v1', [self::route(OperationKind::FetchCollection, type: 'export.csv')]);
    }

    /**
     * matched as WordPress matches a route: ignoring case, against the path as it arrives
     *
     * @param non-empty-string $pattern
     */
    #[DataProvider('segmentsOfACodec')]
    public function testAParameterMatchesTheSegmentsItsCodecAdmitsAndNoOther(
        string $pattern,
        string $segment,
        bool $matches,
    ): void {
        $route = self::route(
            OperationKind::FetchResource,
            codec: new DescribedCodec(new Schema(type: JsonType::String), $pattern),
        );
        $registered = (string) array_key_first((new RoutePatterns('acme/v1', [$route]))->all());

        self::assertSame($matches, preg_match('@^/acme/v1' . $registered . '$@i', '/acme/v1/pets/' . $segment) === 1);
    }

    /**
     * @return iterable<string, array{non-empty-string, string, bool}>
     */
    public static function segmentsOfACodec(): iterable
    {
        yield 'digits' => ['0|[1-9][0-9]*', '17', true];
        yield 'a leading zero the codec refuses' => ['0|[1-9][0-9]*', '017', false];
        yield 'a part the codec admits' => ['[0-9]+', '17a', false];
        yield 'a pattern that would span two segments' => ['.+', '17/tags', false];
        yield 'a line feed after the segment' => ['[0-9]+', "17\n", false];
        yield 'letters in another case' => ['[a-f]+', 'ABC', true];
    }

    /**
     * a GET, which the first route of each path responds to
     *
     * @param ?array{OperationKind, ?string} $expected the kind and relationship, null for none
     */
    #[DataProvider('pathsOfTheApi')]
    public function testTheRouteOfAPathIsTheRouteOfTheLibraryItLiesOn(string $path, ?array $expected): void
    {
        $patterns = new RoutePatterns('acme/v1', [
            self::route(OperationKind::FetchCollection),
            self::route(OperationKind::FetchResource),
            self::route(OperationKind::DeleteResource),
            self::route(OperationKind::FetchRelated, 'tags'),
            self::route(OperationKind::FetchCollection, type: 'pet toys'),
        ]);

        $route = $patterns->routeFor($path, 'GET');

        self::assertSame(
            $expected,
            $route instanceof OperationRoute ? [$route->operationKind, $route->relationship] : null,
        );
    }

    /**
     * @return iterable<string, array{string, ?array{OperationKind, ?string}}>
     */
    public static function pathsOfTheApi(): iterable
    {
        yield 'the collection' => ['/acme/v1/pets', [OperationKind::FetchCollection, null]];
        yield 'a resource' => ['/acme/v1/pets/7', [OperationKind::FetchResource, null]];
        yield 'an id the codec refuses' => ['/acme/v1/pets/abc', [OperationKind::FetchResource, null]];
        yield 'an id as it was sent' => ['/acme/v1/pets/a%2Fb', [OperationKind::FetchResource, null]];
        yield 'an id percent-encoded' => ['/acme/v1/pets/%37', [OperationKind::FetchResource, null]];
        yield 'a literal percent-encoded' => ['/acme/v1/p%65ts/7', [OperationKind::FetchResource, null]];
        yield 'a literal decoded, as an index.php permalink routes it' => [
            '/acme/v1/pet toys',
            [OperationKind::FetchCollection, null],
        ];
        yield 'another case' => ['/ACME/v1/PETS/7', [OperationKind::FetchResource, null]];
        yield 'the related route' => ['/acme/v1/pets/7/tags', [OperationKind::FetchRelated, 'tags']];
        yield 'a segment more' => ['/acme/v1/pets/7/tags/1', null];
        yield 'a path on no route of the namespace' => ['/acme/v1/nothing', null];
        yield 'another namespace' => ['/other/v1/pets', null];
        yield 'a namespace that begins alike' => ['/acme/v10/pets', null];
        yield 'the namespace alone' => ['/acme/v1', null];
    }

    /**
     * the route of `GET` responds to `HEAD`, and a method no route of the path responds to
     * takes the first route of the path
     *
     * @param ?OperationKind $expected null for none
     */
    #[DataProvider('requestsOfTheApi')]
    public function testTheRouteOfARequestIsTheRouteOfItsMethodOnItsPath(
        string $method,
        string $path,
        ?OperationKind $expected,
    ): void {
        $patterns = new RoutePatterns('acme/v1', [
            self::route(OperationKind::FetchResource),
            self::route(OperationKind::DeleteResource),
        ]);

        $route = $patterns->routeFor($path, $method);

        self::assertSame($expected, $route instanceof OperationRoute ? $route->operationKind : null);
    }

    /**
     * @return iterable<string, array{string, string, ?OperationKind}>
     */
    public static function requestsOfTheApi(): iterable
    {
        yield 'a read' => ['GET', '/acme/v1/pets/7', OperationKind::FetchResource];
        yield 'a read without content' => ['HEAD', '/acme/v1/pets/7', OperationKind::FetchResource];
        yield 'a delete' => ['DELETE', '/acme/v1/pets/7', OperationKind::DeleteResource];
        yield 'a delete of an id its codec refuses' => ['DELETE', '/acme/v1/pets/abc', OperationKind::DeleteResource];
        yield 'a method no route responds to' => ['PUT', '/acme/v1/pets/7', OperationKind::FetchResource];
        yield 'a path on no route' => ['DELETE', '/acme/v1/nothing', null];
    }

    /**
     * `/Pets` and `/pets` are two patterns, and the path matches both, ignoring case
     */
    public function testTheRouteOfAMethodMayLieOnALaterPatternOfThePath(): void
    {
        $fetch = self::route(OperationKind::FetchCollection, type: 'Pets');
        $create = self::route(OperationKind::CreateResource, type: 'pets');
        $patterns = new RoutePatterns('acme/v1', [$fetch, $create]);

        self::assertSame($fetch, $patterns->routeFor('/acme/v1/pets', 'GET'));
        self::assertSame($create, $patterns->routeFor('/acme/v1/pets', 'POST'));
    }

    #[DataProvider('normalForms')]
    public function testTheNormalFormWritesEverySegmentBelowTheNamespaceOneWay(string $path, string $normal): void
    {
        $patterns = new RoutePatterns('acme/v1', [self::route(OperationKind::FetchCollection)]);

        self::assertSame($normal, $patterns->normalize($path));
        self::assertSame($normal, $patterns->normalize($normal));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function normalForms(): iterable
    {
        yield 'already normal' => ['/acme/v1/pets/7', '/acme/v1/pets/7'];
        yield 'an unreserved character percent-encoded' => ['/acme/v1/pets/%37', '/acme/v1/pets/7'];
        yield 'every unreserved character percent-encoded' => ['/acme/v1/%61%2D%2E%5F%7E', '/acme/v1/a-._~'];
        yield 'a reserved character percent-encoded' => ['/acme/v1/pets/a%2Fb', '/acme/v1/pets/a%2Fb'];
        yield 'lower-case hex digits' => ['/acme/v1/pets/a%2fb', '/acme/v1/pets/a%2Fb'];
        yield 'a reserved character as it is' => ['/acme/v1/users/a@b', '/acme/v1/users/a%40b'];
        yield 'a space as it is' => ['/acme/v1/pet toys', '/acme/v1/pet%20toys'];
        yield 'a character above U+007F as it is' => ["/acme/v1/caf\u{e9}", '/acme/v1/caf%C3%A9'];
        yield 'a percent sign that starts no percent-encoding' => ['/acme/v1/pets/100%', '/acme/v1/pets/100%25'];
        yield 'an encoded percent sign' => ['/acme/v1/pets/100%25', '/acme/v1/pets/100%25'];
        yield 'the namespace in another case, kept' => ['/ACME/v1/pets/%37', '/ACME/v1/pets/7'];
        yield 'the namespace alone' => ['/acme/v1', '/acme/v1'];
        yield 'another namespace' => ['/other/v1/pets/%37', '/other/v1/pets/%37'];
        yield 'a namespace that begins alike' => ['/acme/v10/pets/%37', '/acme/v10/pets/%37'];
    }

    public function testTheNormalFormIsTheRouteOnlyWhereItMatchesARouteOfTheLibrary(): void
    {
        $patterns = new RoutePatterns('acme/v1', [self::route(OperationKind::FetchResource)]);

        self::assertSame('/acme/v1/pets/7', $patterns->normalizedRoute('/acme/v1/pets/%37'));
        self::assertSame('/acme/v1/pets/7', $patterns->normalizedRoute('/acme/v1/pets/7'));
        self::assertNull($patterns->normalizedRoute('/acme/v1/pets/abc'));
        self::assertNull($patterns->normalizedRoute('/acme/v1/users/a@b'));
        self::assertNull($patterns->normalizedRoute('/other/v1/pets/7'));
    }

    /**
     * WordPress puts the namespace into every pattern unescaped,
     * and matches a path whose namespace it does not find by its text against every route,
     * so `.` of the namespace matches any character there
     */
    public function testANamespaceWithPatternSyntaxMatchesAsItsPatternsDo(): void
    {
        $collection = self::route(OperationKind::FetchCollection);
        $patterns = new RoutePatterns('acme.v1', [$collection]);

        self::assertSame($collection, $patterns->routeFor('/acmeXv1/pets', 'GET'));
        self::assertSame('/acmeXv1/pets', $patterns->normalizedRoute('/acmeXv1/pets'));
        self::assertNull($patterns->routeFor('/other/v1/pets', 'GET'));
    }

    /**
     * @param non-falsy-string $namespace
     */
    #[DataProvider('namespacesWordPressCannotServe')]
    public function testANamespaceWordPressCannotServeIsRefused(string $namespace, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new RoutePatterns($namespace, [self::route(OperationKind::FetchCollection)]);
    }

    /**
     * @return iterable<string, array{non-falsy-string, string}>
     */
    public static function namespacesWordPressCannotServe(): iterable
    {
        yield 'the delimiter of WordPress' => ['acme@v1', 'holds "@"'];
        yield 'no pattern PCRE compiles' => ['acme(v1', 'is no pattern PCRE compiles'];
    }

    public function testRoutesOfOneMethodThatOverlapIgnoringCaseAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the routes "/Pets" and "/pets" both respond to GET, and WordPress matches a route ignoring letter case,'
            . ' the first registered winning, so a path that matches both could never reach the second',
        );

        new RoutePatterns('acme/v1', [
            self::route(OperationKind::FetchCollection, type: 'Pets'),
            self::route(OperationKind::FetchCollection, type: 'pets'),
        ]);
    }

    public function testRoutesOfOtherMethodsMayOverlapIgnoringCase(): void
    {
        $patterns = new RoutePatterns('acme/v1', [
            self::route(OperationKind::FetchCollection, type: 'Pets'),
            self::route(OperationKind::CreateResource, type: 'pets'),
        ]);

        self::assertCount(2, $patterns->all());
    }

    public function testAnEndpointJoinsThePatternOfTheRoutesOfItsTemplate(): void
    {
        $read = self::route(OperationKind::FetchResource);
        $replace = new EndpointRoute(Samples::endpoint('replacePet', 'PUT', '/pets/{id}'));

        $patterns = new RoutePatterns('acme/v1', [$read, $replace]);

        self::assertSame(['/pets/(?P<id>' . self::DIGITS . ')' => [$read, $replace]], $patterns->all());
    }

    /**
     * the codec of the parameter refuses the literal, so no path matches both routes
     */
    public function testALiteralOfAnEndpointBesideAParameterWhoseCodecRefusesItIsAPatternOfItsOwn(): void
    {
        $read = self::route(OperationKind::FetchResource);
        $recent = new EndpointRoute(self::recentPets());

        $patterns = new RoutePatterns('acme/v1', [$read, $recent]);

        self::assertSame(['/pets/(?P<id>' . self::DIGITS . ')', '/pets/recent'], array_keys($patterns->all()));
        self::assertSame($recent, $patterns->routeFor('/acme/v1/pets/recent', 'GET'));
        self::assertSame($read, $patterns->routeFor('/acme/v1/pets/7', 'GET'));
    }

    public function testALiteralOfAnEndpointThatTheCodecOfAParameterAdmitsIsRefusedForOneMethod(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the routes "/pets/{id}" and "/pets/recent" both respond to GET');

        new RoutePatterns('acme/v1', [
            self::route(OperationKind::FetchResource, codec: new DescribedCodec(new Schema(type: JsonType::String))),
            new EndpointRoute(self::recentPets()),
        ]);
    }

    private static function recentPets(): EndpointMetadata
    {
        return Samples::endpoint(
            'recentPets',
            'GET',
            '/pets/recent',
            [],
            responses: [200 => new ResponseMetadata(ResponseShapeKind::Collection, 'pets', null, null, null, null)],
        );
    }

    private static function route(
        OperationKind $kind,
        ?string $relationship = null,
        string $type = 'pets',
        ?SegmentCodec $codec = null,
    ): Route {
        return new OperationRoute(
            $kind,
            $type,
            $relationship,
            $kind->route()->addressesId() ? new PathParameter('id', $codec ?? new DigitsCodec()) : null,
            [$type],
        );
    }
}
