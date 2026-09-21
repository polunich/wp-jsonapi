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

namespace Polunich\WpJsonApi\Tests\Unit\Rendering;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Document\DataDocument;
use Polunich\WpJsonApi\Document\DocumentEncoder;
use Polunich\WpJsonApi\Document\ResourceObject;
use Polunich\WpJsonApi\Execution\Result\AcceptedResult;
use Polunich\WpJsonApi\Execution\Result\AtomicResult;
use Polunich\WpJsonApi\Execution\Result\CollectionResult;
use Polunich\WpJsonApi\Execution\Result\ContentResult;
use Polunich\WpJsonApi\Execution\Result\MetaResult;
use Polunich\WpJsonApi\Execution\Result\NoContentResult;
use Polunich\WpJsonApi\Execution\Result\NotModifiedResult;
use Polunich\WpJsonApi\Execution\Result\Page;
use Polunich\WpJsonApi\Execution\Result\RecordGraph;
use Polunich\WpJsonApi\Execution\Result\RecordLinkage;
use Polunich\WpJsonApi\Execution\Result\RelationshipResult;
use Polunich\WpJsonApi\Execution\Result\ResourceRecord;
use Polunich\WpJsonApi\Execution\Result\ResourceResult;
use Polunich\WpJsonApi\Execution\Result\Result;
use Polunich\WpJsonApi\Http\Content;
use Polunich\WpJsonApi\Http\EntityTag;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseShapeKind;
use Polunich\WpJsonApi\Metadata\EndpointMetadata;
use Polunich\WpJsonApi\Metadata\LanguageSourceMetadata;
use Polunich\WpJsonApi\Metadata\LanguageSources;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Metadata\RouteExclusion;
use Polunich\WpJsonApi\Metadata\RouteOwner;
use Polunich\WpJsonApi\Metadata\RouteTable;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\NamingPolicy;
use Polunich\WpJsonApi\Query\Pagination\Strategy;
use Polunich\WpJsonApi\Rendering\DocumentBuilder;
use Polunich\WpJsonApi\Rendering\LinkQuery;
use Polunich\WpJsonApi\Rendering\PaginationWriter;
use Polunich\WpJsonApi\Rendering\ResourceSerializer;
use Polunich\WpJsonApi\Rendering\ResponseBuilder;
use Polunich\WpJsonApi\Rendering\RouteUrls;
use Polunich\WpJsonApi\Routing\RouteKind;
use Polunich\WpJsonApi\Routing\RouteTarget;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use Polunich\WpJsonApi\Tests\Fixtures\ParameterNames;
use Polunich\WpJsonApi\Tests\Fixtures\Routing\PlainUrls;
use ReflectionClass;
use ReflectionMethod;

/**
 * @internal
 */
#[CoversClass(ResponseBuilder::class)]
final class ResponseBuilderTest extends TestCase
{
    private const CURSOR_PROFILE = 'https://jsonapi.org/profiles/ethanresnick/cursor-pagination/';

    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(ResponseBuilder::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testTheConstructorAndTheMethodsKeepTheirParametersAndTypes(): void
    {
        $constructor = (new ReflectionMethod(ResponseBuilder::class, '__construct'))->getParameters();
        $response = (new ReflectionMethod(ResponseBuilder::class, 'response'))->getParameters();

        self::assertSame(['documents', 'encoder', 'languageSources'], ParameterNames::of($constructor));
        self::assertSame(DocumentBuilder::class, (string) $constructor[0]->getType());
        self::assertSame(DocumentEncoder::class, (string) $constructor[1]->getType());
        self::assertSame(LanguageSources::class, (string) $constructor[2]->getType());
        self::assertSame(
            ['result', 'target', 'request', 'contentType', 'contentLanguage'],
            ParameterNames::of($response),
        );
        self::assertSame(Result::class, (string) $response[0]->getType());
        self::assertSame(RouteTarget::class, (string) $response[1]->getType());
        self::assertSame(ServerRequest::class, (string) $response[2]->getType());
        self::assertSame(MediaType::class, (string) $response[3]->getType());
        self::assertSame('?string', (string) $response[4]->getType());
    }

    #[DataProvider('results')]
    public function testTheStatusIsTheOneOfTheResult(Result $result, RouteKind $kind, int $status): void
    {
        $response = self::builder()->response(
            $result,
            self::target($kind),
            self::request(),
            MediaType::jsonApi(),
            null,
        );

        self::assertSame($status, $response->status);
    }

    /**
     * @return iterable<string, array{Result, RouteKind, int}>
     */
    public static function results(): iterable
    {
        yield 'a fetched resource' => [self::resourceResult(200), RouteKind::Resource, 200];
        yield 'a created resource' => [self::resourceResult(201), RouteKind::Collection, 201];
        yield 'a collection' => [self::collectionResult(), RouteKind::Collection, 200];
        yield 'a relationship' => [self::relationshipResult(), RouteKind::Relationship, 200];
        yield 'an accepted write' => [self::acceptedResult(), RouteKind::Resource, 202];
        yield 'a write that returned nothing' => [new NoContentResult(), RouteKind::Resource, 204];
        yield 'a condition that did not hold' => [new NotModifiedResult(null), RouteKind::Resource, 304];
        yield 'an atomic request with a document' => [
            new AtomicResult([self::resourceResult(200)]),
            RouteKind::Atomic,
            200,
        ];
        yield 'an atomic request without one' => [
            new AtomicResult([new NoContentResult()]),
            RouteKind::Atomic,
            204,
        ];
    }

    public function testWritesTheHeaderFieldsOfTheTableInTheirOrder(): void
    {
        $response = self::builder()->response(
            self::resourceResult(201, new EntityTag('v1')),
            self::target(RouteKind::Collection),
            self::request(),
            MediaType::jsonApi(),
            'de',
        );

        self::assertSame(
            ['content-type', 'vary', 'content-language', 'etag', 'location'],
            array_keys($response->headers->all()),
        );
    }

    public function testTheContentTypeIsTheNegotiatedMediaTypeWithItsExtAndProfile(): void
    {
        $contentType = MediaType::jsonApi([MediaType::ATOMIC_EXTENSION], [self::CURSOR_PROFILE]);

        $response = self::builder()->response(
            self::resourceResult(200),
            self::target(RouteKind::Resource),
            self::request(),
            $contentType,
            null,
        );

        self::assertSame($contentType->toHeaderValue(), $response->headers->line('Content-Type'));
        self::assertSame(
            'application/vnd.api+json;ext="https://jsonapi.org/ext/atomic";profile="'
            . self::CURSOR_PROFILE . '"',
            $response->headers->line('Content-Type'),
        );
    }

    #[DataProvider('results')]
    public function testVaryNamesAcceptAndTheDefaultLanguageFieldOnEveryResponse(
        Result $result,
        RouteKind $kind,
        int $status,
    ): void {
        $response = self::builder()->response(
            $result,
            self::target($kind),
            self::request(),
            MediaType::jsonApi(),
            'de',
        );

        self::assertSame(
            ['Accept, Accept-Language'],
            $response->headers->values('Vary'),
            'the ' . $status . ' response writes no vary field, or writes it on more than one line',
        );
    }

    /**
     * @param array<string, string> $expected
     */
    #[DataProvider('bodylessResults')]
    public function testAResponseWithoutADocumentCarriesNoBodyAndNoRepresentationMetadata(
        Result $result,
        RouteKind $kind,
        array $expected,
    ): void {
        $response = self::builder()->response(
            $result,
            self::target($kind),
            self::request(),
            MediaType::jsonApi(),
            'de',
        );

        self::assertNull($response->document);
        self::assertSame($expected, self::fields($response->headers));
    }

    /**
     * @return iterable<string, array{Result, RouteKind, array<string, string>}>
     */
    public static function bodylessResults(): iterable
    {
        yield 'a write that returned nothing' => [
            new NoContentResult(),
            RouteKind::Resource,
            ['vary' => 'Accept, Accept-Language'],
        ];
        yield 'an atomic request whose operations returned nothing' => [
            new AtomicResult([new NoContentResult()]),
            RouteKind::Atomic,
            ['vary' => 'Accept, Accept-Language'],
        ];
        yield 'a condition that did not hold' => [
            new NotModifiedResult(new EntityTag('v1')),
            RouteKind::Resource,
            ['vary' => 'Accept, Accept-Language', 'etag' => '"v1"'],
        ];
    }

    /**
     * @param array<string, string> $query
     */
    #[DataProvider('varyingResults')]
    public function testVaryDropsTheLanguageFieldWhereAParameterBeforeItDecided(
        bool $withBody,
        array $query,
        string $vary,
    ): void {
        $languageSources = new LanguageSources([
            LanguageSourceMetadata::queryParameter('uiLocale'),
            LanguageSourceMetadata::acceptLanguage(),
        ]);
        $result = $withBody ? self::resourceResult(200) : new NoContentResult();

        $response = self::builder($languageSources)->response(
            $result,
            self::target(RouteKind::Resource),
            self::request($query),
            MediaType::jsonApi(),
            'uk',
        );

        self::assertSame($vary, $response->headers->line('Vary'));
    }

    /**
     * @return iterable<string, array{bool, array<string, string>, string}>
     */
    public static function varyingResults(): iterable
    {
        yield 'a document the parameter decided' => [true, ['uiLocale' => 'uk'], 'Accept'];
        yield 'a document the field decided' => [true, [], 'Accept, Accept-Language'];
        yield 'no content the parameter decided' => [false, ['uiLocale' => 'uk'], 'Accept'];
        yield 'no content the field decided' => [false, [], 'Accept, Accept-Language'];
    }

    public function testAContentVariesAsADocumentDoes(): void
    {
        $languageSources = new LanguageSources([
            LanguageSourceMetadata::queryParameter('uiLocale'),
            LanguageSourceMetadata::acceptLanguage(),
        ]);

        $content = self::builder($languageSources)->endpointResponse(
            new ContentResult(Content::bytes('Rex'), new MediaType('text', 'plain'), null),
            Samples::endpoint(),
            new ServerRequest('POST', new Headers([]), ['uiLocale' => 'uk'], ['id' => '1'], ''),
            MediaType::jsonApi(),
            'uk',
        );

        self::assertSame('Accept', $content->headers->line('Vary'));
    }

    public function testVaryNamesAcceptAloneWhereTheApiNegotiatesNoLanguage(): void
    {
        $response = self::builder(new LanguageSources([]))->response(
            self::resourceResult(200),
            self::target(RouteKind::Resource),
            self::request(),
            MediaType::jsonApi(),
            null,
        );

        self::assertSame('Accept', $response->headers->line('Vary'));
    }

    public function testContentLocationCarriesTheLanguageParameterOfTheRequest(): void
    {
        $response = self::builder(new LanguageSources([LanguageSourceMetadata::queryParameter('uiLocale')]))->response(
            self::acceptedResult(),
            self::target(RouteKind::Resource),
            self::request(['include' => 'owner', 'uiLocale' => 'uk']),
            MediaType::jsonApi(),
            'uk',
        );

        self::assertSame('/jobs/9?uiLocale=uk', $response->headers->line('Content-Location'));
    }

    public function testContentLanguageIsWrittenOnlyWhereTheProjectResolvedATag(): void
    {
        $resolved = self::builder()->response(
            self::resourceResult(200),
            self::target(RouteKind::Resource),
            self::request(),
            MediaType::jsonApi(),
            'de-AT',
        );
        $unresolved = self::builder()->response(
            self::resourceResult(200),
            self::target(RouteKind::Resource),
            self::request(),
            MediaType::jsonApi(),
            null,
        );

        self::assertSame('de-AT', $resolved->headers->line('Content-Language'));
        self::assertFalse($unresolved->headers->has('Content-Language'));
    }

    public function testTheEntityTagOfTheResultIsWrittenAsETag(): void
    {
        $tagged = self::builder()->response(
            self::resourceResult(200, new EntityTag('7a1f')),
            self::target(RouteKind::Resource),
            self::request(),
            MediaType::jsonApi(),
            null,
        );
        $untagged = self::builder()->response(
            self::resourceResult(200),
            self::target(RouteKind::Resource),
            self::request(),
            MediaType::jsonApi(),
            null,
        );

        self::assertSame('"7a1f"', $tagged->headers->line('ETag'));
        self::assertFalse($untagged->headers->has('ETag'));
    }

    public function testA201WritesLocationEqualToTheSelfLinkOfThePrimaryResourceObject(): void
    {
        $result = self::resourceResult(201);
        $target = self::target(RouteKind::Collection);
        $request = self::request(['include' => 'owner']);

        $response = self::builder()->response($result, $target, $request, MediaType::jsonApi(), null);
        $document = self::documents(Samples::languageSources())->document(
            $result,
            $target,
            $request,
            MediaType::jsonApi(),
        );

        self::assertInstanceOf(DataDocument::class, $document);

        $resource = $document->data;

        self::assertInstanceOf(ResourceObject::class, $resource);

        $self = $resource->links->all()['self'] ?? null;

        self::assertNotNull($self);
        self::assertSame($self->href, $response->headers->line('Location'));
        self::assertSame('/pets/1', $response->headers->line('Location'));
    }

    public function testTheLocationOfA201CarriesNoQueryOfTheRequest(): void
    {
        $response = self::builder()->response(
            self::resourceResult(201),
            self::target(RouteKind::Collection),
            self::request(['include' => 'owner', 'fields' => ['pets' => 'name']]),
            MediaType::jsonApi(),
            null,
        );

        self::assertSame('/pets/1', $response->headers->line('Location'));
    }

    public function testA201OfATypeThatExcludesTheFetchOfItsResourcesWritesNoLocation(): void
    {
        $registry = new ResourceRegistry([
            Samples::resource(
                'pets',
                attributes: [Samples::attribute('name')],
                capabilities: [Capability::ResourceCreator],
                excluded: RouteExclusion::except(RouteOwner::Resource, [OperationKind::FetchResource], 'type "pets"'),
            ),
        ]);
        $routes = RouteTable::of($registry, [], []);
        $urls = new RouteUrls($routes, PlainUrls::httpQuery());
        $paginationWriter = new PaginationWriter($urls, NamingPolicy::SnakeCase);
        $builder = new ResponseBuilder(
            new DocumentBuilder(
                new ResourceSerializer($registry, $routes, $urls, $paginationWriter),
                $paginationWriter,
                $urls,
                $routes,
                new LinkQuery($registry, Samples::languageSources()),
            ),
            new DocumentEncoder(),
            Samples::languageSources(),
        );

        $response = $builder->response(
            self::resourceResult(201),
            self::target(RouteKind::Collection),
            self::request(),
            MediaType::jsonApi(),
            null,
        );

        self::assertSame(201, $response->status);
        self::assertFalse($response->headers->has('Location'));
    }

    public function testA200OfAResourceWritesNoLocation(): void
    {
        $response = self::builder()->response(
            self::resourceResult(200),
            self::target(RouteKind::Resource),
            self::request(),
            MediaType::jsonApi(),
            null,
        );

        self::assertFalse($response->headers->has('Location'));
    }

    public function testA202WritesContentLocationOfTheMonitorAndNoLocation(): void
    {
        $response = self::builder()->response(
            self::acceptedResult(),
            self::target(RouteKind::Resource),
            self::request(),
            MediaType::jsonApi(),
            null,
        );

        self::assertSame('/jobs/9', $response->headers->line('Content-Location'));
        self::assertFalse($response->headers->has('Location'));
    }

    public function testTheBodyIsTheDocumentOfTheResultAsTheEncoderWritesIt(): void
    {
        $result = self::collectionResult();
        $target = self::target(RouteKind::Collection);
        $request = self::request();

        $response = self::builder()->response($result, $target, $request, MediaType::jsonApi(), null);
        $document = self::documents(Samples::languageSources())->document(
            $result,
            $target,
            $request,
            MediaType::jsonApi(),
        );

        self::assertNotNull($document);
        self::assertEquals((new DocumentEncoder())->encode($document), $response->document);
        self::assertIsArray($response->document);
        self::assertSame(['jsonapi', 'links', 'data'], array_keys($response->document));
    }

    public function testTheEndpointResponseTakesTheEndpointInPlaceOfTheRoute(): void
    {
        $response = (new ReflectionMethod(ResponseBuilder::class, 'endpointResponse'))->getParameters();

        self::assertSame(
            ['result', 'endpoint', 'request', 'contentType', 'contentLanguage'],
            ParameterNames::of($response),
        );
        self::assertSame(EndpointMetadata::class, (string) $response[1]->getType());
    }

    /**
     * an endpoint's 201 is written as the create writes its own:
     * `Location` names the created resource, and `ETag` is the tag of the record the result carries
     */
    public function testTheCreatedResourceOfAnEndpointIsLocatedAndTagged(): void
    {
        $response = self::builder()->endpointResponse(
            self::resourceResult(201, new EntityTag('3')),
            Samples::endpoint(responses: [201 => new ResponseMetadata(
                ResponseShapeKind::Created,
                'pets',
                null,
                null,
                null,
                null,
            )]),
            new ServerRequest('POST', new Headers([]), ['include' => 'orders'], ['id' => '1'], ''),
            MediaType::jsonApi(),
            null,
        );

        self::assertSame(201, $response->status);
        self::assertSame('/pets/1', $response->headers->line('Location'));
        self::assertSame('"3"', $response->headers->line('ETag'));
    }

    public function testAnEndpointWritesTheEntityTagItsResultCarries(): void
    {
        $tagged = self::builder()->endpointResponse(
            self::resourceResult(200, new EntityTag('2')),
            Samples::endpoint(),
            self::endpointRequest(),
            MediaType::jsonApi(),
            null,
        );
        $untagged = self::builder()->endpointResponse(
            self::resourceResult(200),
            Samples::endpoint(),
            self::endpointRequest(),
            MediaType::jsonApi(),
            null,
        );

        self::assertSame('"2"', $tagged->headers->line('ETag'));
        self::assertFalse($untagged->headers->has('ETag'));
        self::assertFalse($tagged->headers->has('Location'));
    }

    public function testTheMonitorOfAnEndpointIsItsContentLocation(): void
    {
        $endpoint = Samples::endpoint(responses: [202 => new ResponseMetadata(
            ResponseShapeKind::Accepted,
            'jobs',
            null,
            null,
            null,
            null,
        )]);

        $response = self::builder()->endpointResponse(
            self::acceptedResult(),
            $endpoint,
            self::endpointRequest(),
            MediaType::jsonApi(),
            null,
        );

        self::assertSame(202, $response->status);
        self::assertSame('/jobs/9', $response->headers->line('Content-Location'));
    }

    public function testTheMetaOfAnEndpointIsABodyOfTheJsonApiObjectAndMeta(): void
    {
        $endpoint = Samples::endpoint(responses: [200 => new ResponseMetadata(
            ResponseShapeKind::Meta,
            null,
            null,
            null,
            null,
            new IntegerCodec(),
        )]);

        $response = self::builder()->endpointResponse(
            new MetaResult(['pets' => 3]),
            $endpoint,
            self::endpointRequest(),
            MediaType::jsonApi(),
            'uk',
        );

        self::assertSame(200, $response->status);
        self::assertSame(MediaType::jsonApi()->toHeaderValue(), $response->headers->line('Content-Type'));
        self::assertSame('uk', $response->headers->line('Content-Language'));
        self::assertIsArray($response->document);
        self::assertSame(['jsonapi', 'meta'], array_keys($response->document));
    }

    public function testNoContentOfAnEndpointCarriesNoBodyAndNoRepresentationMetadata(): void
    {
        $endpoint = Samples::endpoint(responses: [204 => new ResponseMetadata(
            ResponseShapeKind::NoContent,
            null,
            null,
            null,
            null,
            null,
        )]);

        $response = self::builder()->endpointResponse(
            new NoContentResult(),
            $endpoint,
            self::endpointRequest(),
            MediaType::jsonApi(),
            'uk',
        );

        self::assertSame(204, $response->status);
        self::assertNull($response->document);
        self::assertSame(['vary'], array_keys(self::fields($response->headers)));
    }

    public function testTheLibraryRespondsWithAContentInItsMediaTypeWithTheNameToSaveItUnder(): void
    {
        $content = Content::bytes("id,name\n1,Rex\n");
        $result = new ContentResult($content, new MediaType('text', 'csv', ['header' => 'present']), 'pets.csv');

        $response = self::builder()->endpointResponse(
            $result,
            Samples::endpoint(),
            self::endpointRequest(),
            MediaType::jsonApi(),
            'uk',
        );

        self::assertSame(200, $response->status);
        self::assertNull($response->document);
        self::assertSame($content, $response->content);
        self::assertSame(
            [
                'content-type' => 'text/csv;header=present',
                'vary' => 'Accept, Accept-Language',
                'content-language' => 'uk',
                'content-disposition' => 'attachment; filename="pets.csv"',
            ],
            self::fields($response->headers),
        );
    }

    public function testAContentWithoutANameOrALanguageCarriesNeither(): void
    {
        $result = new ContentResult(Content::bytes('Rex'), new MediaType('text', 'plain'), null);

        $response = self::builder()->endpointResponse(
            $result,
            Samples::endpoint(),
            self::endpointRequest(),
            MediaType::jsonApi(),
            null,
        );

        self::assertSame(
            ['content-type' => 'text/plain', 'vary' => 'Accept, Accept-Language'],
            self::fields($response->headers),
        );
    }

    /**
     * field name => its values joined, in written order; `Headers` lower-cases the names
     *
     * @return array<string, string>
     */
    private static function fields(Headers $headers): array
    {
        $written = [];

        foreach ($headers->all() as $name => $values) {
            $written[(string) $name] = implode(', ', $values);
        }

        return $written;
    }

    private static function builder(?LanguageSources $languageSources = null): ResponseBuilder
    {
        $languageSources ??= Samples::languageSources();

        return new ResponseBuilder(self::documents($languageSources), new DocumentEncoder(), $languageSources);
    }

    private static function documents(LanguageSources $languageSources): DocumentBuilder
    {
        $registry = self::registry();
        $routes = RouteTable::of($registry, [], []);
        $urls = new RouteUrls($routes, PlainUrls::httpQuery());
        $paginationWriter = new PaginationWriter($urls, NamingPolicy::SnakeCase);

        return new DocumentBuilder(
            new ResourceSerializer($registry, $routes, $urls, $paginationWriter),
            $paginationWriter,
            $urls,
            $routes,
            new LinkQuery($registry, $languageSources),
        );
    }

    /**
     * `jobs` is the type of the monitor a 202 carries;
     * `orders` of `pets` is the relationship a relationship result names,
     * declared so that its routes respond and the document links them
     */
    private static function registry(): ResourceRegistry
    {
        return new ResourceRegistry([
            Samples::resource(
                'pets',
                attributes: [Samples::attribute('name')],
                relationships: [Samples::toMany('orders', 'pets')],
                capabilities: [Capability::CollectionReader, Capability::RelatedLoader],
            ),
            Samples::resource('jobs'),
        ]);
    }

    /**
     * @param array<array-key, mixed> $query
     */
    private static function request(array $query = []): ServerRequest
    {
        return new ServerRequest('GET', new Headers([]), $query, [], '');
    }

    private static function endpointRequest(): ServerRequest
    {
        return new ServerRequest('POST', new Headers([]), [], ['id' => '1'], '');
    }

    private static function target(RouteKind $kind): RouteTarget
    {
        return match ($kind) {
            RouteKind::Collection => new RouteTarget($kind, 'pets', null, null),
            RouteKind::Resource => new RouteTarget($kind, 'pets', '1', null),
            RouteKind::Related => new RouteTarget($kind, 'pets', '1', 'owner'),
            RouteKind::Relationship => new RouteTarget($kind, 'pets', '1', 'orders'),
            RouteKind::Atomic => new RouteTarget($kind, null, null, null),
        };
    }

    private static function resourceResult(int $status, ?EntityTag $entityTag = null): ResourceResult
    {
        return new ResourceResult(
            'pets',
            self::record('pets', '1'),
            new RecordGraph(false),
            new SparseFieldsets([]),
            $status,
            $entityTag,
        );
    }

    private static function collectionResult(): CollectionResult
    {
        return CollectionResult::paged(
            'pets',
            self::page([self::record('pets', '1')]),
            new RecordGraph(false),
            new SparseFieldsets([]),
        );
    }

    private static function relationshipResult(): RelationshipResult
    {
        return new RelationshipResult(
            'pets',
            '1',
            'orders',
            'pets',
            RecordLinkage::toMany(self::page([self::record('pets', '2')])),
            new RecordGraph(false),
            new SparseFieldsets([]),
            null,
        );
    }

    private static function acceptedResult(): AcceptedResult
    {
        return new AcceptedResult(
            new ResourceRecord('jobs', '9', new Pet('9', 'the running write')),
            new RecordGraph(false),
            new SparseFieldsets([]),
        );
    }

    private static function record(string $type, string $id): ResourceRecord
    {
        return new ResourceRecord($type, $id, new Pet($id, 'the record ' . $id));
    }

    /**
     * @param list<ResourceRecord> $records
     */
    private static function page(array $records): Page
    {
        return new Page($records, Strategy::PageNumber, 2, 1, null, false, false, false, null, null, null, null);
    }
}
