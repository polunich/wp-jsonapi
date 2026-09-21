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

namespace Polunich\WpJsonApi\Tests\Unit\Execution;

use Closure;
use Generator;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Access\DenialPolicy;
use Polunich\WpJsonApi\Access\EndpointAttempt;
use Polunich\WpJsonApi\Access\EndpointPermission;
use Polunich\WpJsonApi\Access\Gate;
use Polunich\WpJsonApi\Authentication\Identity;
use Polunich\WpJsonApi\Codec\Codec;
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Codec\ObjectCodec;
use Polunich\WpJsonApi\Codec\Pointer;
use Polunich\WpJsonApi\Codec\Schema\JsonType;
use Polunich\WpJsonApi\Codec\Schema\Schema;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Declaration\Endpoint;
use Polunich\WpJsonApi\Declaration\ResponseShape;
use Polunich\WpJsonApi\Endpoint\Handler;
use Polunich\WpJsonApi\Endpoint\Request;
use Polunich\WpJsonApi\Endpoint\Response;
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Error\NotFoundException;
use Polunich\WpJsonApi\Error\PreconditionFailedException;
use Polunich\WpJsonApi\Execution\EndpointExecutor;
use Polunich\WpJsonApi\Execution\ExecutionContext;
use Polunich\WpJsonApi\Execution\IncludeLoader;
use Polunich\WpJsonApi\Execution\Paginator;
use Polunich\WpJsonApi\Execution\Preconditions;
use Polunich\WpJsonApi\Execution\Result\AcceptedResult;
use Polunich\WpJsonApi\Execution\Result\CollectionResult;
use Polunich\WpJsonApi\Execution\Result\ContentResult;
use Polunich\WpJsonApi\Execution\Result\MetaResult;
use Polunich\WpJsonApi\Execution\Result\NoContentResult;
use Polunich\WpJsonApi\Execution\Result\RelationshipResult;
use Polunich\WpJsonApi\Execution\Result\ResourceRecord;
use Polunich\WpJsonApi\Execution\Result\ResourceResult;
use Polunich\WpJsonApi\Execution\StoreGateway;
use Polunich\WpJsonApi\Http\Content;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Metadata\EndpointMetadata;
use Polunich\WpJsonApi\Metadata\ImplementationReference;
use Polunich\WpJsonApi\Metadata\ImplementationResolver;
use Polunich\WpJsonApi\Operation\Precondition;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Query\CollectionReading;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\Pagination\Cursor;
use Polunich\WpJsonApi\Query\Pagination\PageRequest;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Store\CollectionQuery;
use Polunich\WpJsonApi\Store\LoadRequest;
use Polunich\WpJsonApi\Store\RelatedLoader;
use Polunich\WpJsonApi\Store\RelatedLoadRequest;
use Polunich\WpJsonApi\Store\ResourceLoader;
use Polunich\WpJsonApi\Store\Slice;
use Polunich\WpJsonApi\Store\VersionMismatchException;
use Polunich\WpJsonApi\Tests\Fixtures\Authentication\NoChallenges;
use Polunich\WpJsonApi\Tests\Fixtures\Endpoint\EndpointSamples;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use ReflectionClass;
use RuntimeException;
use UnexpectedValueException;

/**
 * @internal
 */
#[CoversClass(EndpointExecutor::class)]
final class EndpointExecutorTest extends TestCase
{
    private const ORDER = '3f2504e0-4f89-41d3-9a0c-0305e82c3301';

    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(EndpointExecutor::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testTheBoundRecordsAreLoadedAndThePermissionIsAskedWithThem(): void
    {
        $store = self::store();
        $asked = [];
        $endpoint = EndpointSamples::endpoint(
            'POST',
            '/customers/{customer}/pets/{id}/adopt',
            static function (Endpoint $endpoint): void {
                $endpoint->bind(
                    'customer',
                    'customers',
                )->bind('id', 'pets')->preconditionRecord('id')->responds(ResponseShape::noContent());
            },
        );
        $endpoint = self::withPermission($endpoint, static function (EndpointAttempt $attempt) use (&$asked): bool {
            $asked[] = $attempt->records;

            return true;
        });

        $queryParameters = new Values(['adoptionNote' => 'gently']);
        $records = self::executor($store)->loadRecords(
            $endpoint,
            ['customer' => '2', 'id' => '1'],
            new LanguagePreferences([]),
            $queryParameters,
            self::context(self::request('POST')),
        );

        self::assertSame(
            ['customer' => $store->records['customers']['2'], 'id' => $store->records['pets']['1']],
            $records,
        );
        self::assertSame([$records], $asked);
        self::assertSame([
            ['customers', ['2'], true, $queryParameters],
            ['pets', ['1'], true, $queryParameters],
        ], array_map(static function (LoadRequest $request): array {
            return [$request->type, $request->ids, $request->fields->hasEveryField(), $request->queryParameters];
        }, $store->loads));
    }

    /**
     * the second level asks with the bound records,
     * so an endpoint that binds none has no second level
     */
    public function testAnEndpointThatBindsNoRecordIsNotAskedASecondTime(): void
    {
        $asked = [];
        $endpoint = self::withPermission(
            EndpointSamples::endpoint('POST', '/stats/reset', static function (Endpoint $endpoint): void {
                $endpoint->responds(ResponseShape::noContent());
            }),
            static function (EndpointAttempt $attempt) use (&$asked): bool {
                $asked[] = $attempt->records;

                return true;
            },
        );

        $records = self::executor(self::store())->loadRecords(
            $endpoint,
            [],
            new LanguagePreferences([]),
            new Values([]),
            self::context(self::request('POST')),
        );

        self::assertSame([], $records);
        self::assertSame([], $asked);
    }

    public function testAMissingRecordIsNotFoundAndNoPermissionIsAsked(): void
    {
        $asked = false;
        $endpoint = self::withPermission(EndpointSamples::card(), static function () use (&$asked): bool {
            $asked = true;

            return true;
        });

        try {
            self::executor(self::store())->loadRecords(
                $endpoint,
                ['id' => '99'],
                new LanguagePreferences([]),
                new Values([]),
                self::context(self::request('GET')),
            );
            self::fail('a missing record was loaded');
        } catch (NotFoundException $missing) {
            self::assertSame(404, $missing->status());
        }

        self::assertFalse($asked);
    }

    public function testASecondLevelThatDeniesIsRefused(): void
    {
        $endpoint = self::withPermission(EndpointSamples::card(), static function (): bool {
            return false;
        });

        $this->expectException(JsonApiException::class);

        self::executor(self::store())->loadRecords(
            $endpoint,
            ['id' => '1'],
            new LanguagePreferences([]),
            new Values([]),
            self::context(self::request('GET')),
        );
    }

    /**
     * the response of GET is the precondition record, so it carries that record's tag
     */
    public function testTheBoundRecordIsSentWithItsTag(): void
    {
        $store = self::store();
        $result = self::respond(
            EndpointSamples::card(),
            $store,
            Response::resource($store->records['pets']['1']),
            ['id' => $store->records['pets']['1']],
        );

        self::assertInstanceOf(ResourceResult::class, $result);
        self::assertSame(200, $result->status());
        self::assertSame('1', $result->resource?->id);
        self::assertNotNull($result->entityTag());
    }

    public function testAResponseToPutCarriesNoTag(): void
    {
        $store = self::store();
        $endpoint = EndpointSamples::endpoint('PUT', '/pets/{id}/profile', static function (Endpoint $endpoint): void {
            $endpoint->bind('id', 'pets')->responds(ResponseShape::record('id'));
        });

        $result = self::respond(
            $endpoint,
            $store,
            Response::resource($store->records['pets']['1']),
            ['id' => $store->records['pets']['1']],
        );

        self::assertInstanceOf(ResourceResult::class, $result);
        self::assertNull($result->entityTag());
    }

    public function testAnotherRecordWhereTheBoundOneIsDeclaredIsAFaultOfTheHandler(): void
    {
        $store = self::store();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the endpoint "probe" declares that it responds with the record bound to "id",'
            . ' of id "1", and its handler returns the pets resource "2"');

        self::respond(
            EndpointSamples::card(),
            $store,
            Response::resource($store->records['pets']['2']),
            ['id' => $store->records['pets']['1']],
        );
    }

    public function testNoRelatedResourceIsSentAsNullData(): void
    {
        $store = self::store();
        $endpoint = EndpointSamples::endpoint('GET', '/pets/{id}/keeper', static function (Endpoint $endpoint): void {
            $endpoint->bind('id', 'pets')->responds(ResponseShape::related('id', 'owner'));
        });

        $result = self::respond($endpoint, $store, Response::resource(null), ['id' => $store->records['pets']['1']]);

        self::assertInstanceOf(ResourceResult::class, $result);
        self::assertNull($result->resource);
        self::assertSame('customers', $result->type);
    }

    public function testNoResourceWhereOneIsDeclaredIsAFaultOfTheHandler(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the handler of the endpoint "probe" returns no resource with 200, and the'
            . ' endpoint declares a record response for it');

        self::respond(
            EndpointSamples::card(),
            self::store(),
            Response::resource(null),
            ['id' => self::store()->records['pets']['1']],
        );
    }

    public function testACollectionIsAWholeListWithoutPages(): void
    {
        $store = self::store();
        $endpoint = EndpointSamples::endpoint('GET', '/pets/recent', static function (Endpoint $endpoint): void {
            $endpoint->responds(ResponseShape::collection('pets'));
        });

        $result = self::respond(
            $endpoint,
            $store,
            Response::collection([$store->records['pets']['2'], $store->records['pets']['1']]),
            [],
        );

        self::assertInstanceOf(CollectionResult::class, $result);
        self::assertNull($result->page);
        self::assertSame(['2', '1'], [$result->records[0]->id, $result->records[1]->id]);
    }

    public function testAPageIsPagedFromTheSliceTheHandlerReadForItsQuery(): void
    {
        $store = self::store();
        $handler = new class ($store->records['pets']['2'], $store->records['pets']['1']) implements Handler {
            public ?CollectionQuery $query = null;

            public function __construct(private readonly object $first, private readonly object $second) {}

            #[Override]
            public function handle(Request $request): Response
            {
                $this->query = $request->collectionQuery;

                return Response::page(new Slice([$this->first, $this->second], 7));
            }
        };

        $result = self::respond(
            EndpointSamples::search(),
            $store,
            $handler,
            [],
            reading: self::reading(PageRequest::pageNumber(1, 1)),
        );

        self::assertInstanceOf(CollectionResult::class, $result);
        self::assertSame(['2'], array_map(static function (ResourceRecord $record): string {
            return $record->id;
        }, $result->records));
        self::assertSame(1, $result->page?->size);
        self::assertTrue($result->page->hasNext);
        self::assertSame(7, $result->page->total);
        self::assertSame('pets', $handler->query?->type);
        self::assertSame('-name,id', $handler->query->sort->toString());
        self::assertSame([0, 2], [$handler->query->window->offset, $handler->query->window->limit]);
    }

    public function testASliceBeyondTheWindowOfItsQueryIsAFaultOfTheHandler(): void
    {
        $store = self::store();
        $pets = $store->records['pets'];

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('the handler of the endpoint "probe" returned 3 records for a window of 2, and a'
            . ' reading returns the records of the page and the one probe record past it at most');

        self::respond(
            EndpointSamples::search(),
            $store,
            Response::page(new Slice([$pets['1'], $pets['2'], $pets['1']])),
            [],
            reading: self::reading(PageRequest::pageNumber(1, 1)),
        );
    }

    public function testACollectionWhereAPageIsDeclaredIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the handler of the endpoint "probe" returns a collection with 200, and the'
            . ' endpoint declares a page response for it');

        self::respond(
            EndpointSamples::search(),
            self::store(),
            Response::collection([]),
            [],
            reading: self::reading(PageRequest::pageNumber()),
        );
    }

    public function testAPageWhereACollectionIsDeclaredIsRefused(): void
    {
        $endpoint = EndpointSamples::endpoint('GET', '/pets/recent', static function (Endpoint $endpoint): void {
            $endpoint->responds(ResponseShape::collection('pets'));
        });

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the handler of the endpoint "probe" returns a page with 200, and the endpoint'
            . ' declares a collection response for it');

        self::respond($endpoint, self::store(), Response::page(new Slice([])), []);
    }

    public function testTheQueryOfAPageBeforeACursorRunsInTheReversedSort(): void
    {
        $query = self::executor(self::store())->collectionQuery(
            EndpointSamples::search(),
            self::reading(PageRequest::cursor(before: new Cursor(['name' => 'Tom'], '2'))),
            new SparseFieldsets([]),
            new LanguagePreferences([]),
            new Values([]),
        );

        self::assertSame('name,-id', $query?->sort->toString());
    }

    public function testAReadingComesExactlyWithAPage(): void
    {
        $executor = self::executor(self::store());
        $fields = new SparseFieldsets([]);
        $languages = new LanguagePreferences([]);

        self::assertNull(
            $executor->collectionQuery(EndpointSamples::card(), null, $fields, $languages, new Values([])),
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the request of the endpoint "probe" carries no reading of a collection, and a'
            . ' request carries the reading of a collection exactly where its endpoint responds with a page');

        $executor->collectionQuery(EndpointSamples::search(), null, $fields, $languages, new Values([]));
    }

    public function testACreatedResourceIsSentWith201AndItsTag(): void
    {
        $store = self::store();
        $endpoint = EndpointSamples::endpoint('POST', '/pets/{id}/litter', static function (Endpoint $endpoint): void {
            $endpoint->bind('id', 'pets')->responds(ResponseShape::created('pets'));
        });

        $result = self::respond(
            $endpoint,
            $store,
            Response::created(new Pet('7', 'Pup')),
            ['id' => $store->records['pets']['1']],
        );

        self::assertInstanceOf(ResourceResult::class, $result);
        self::assertSame(201, $result->status());
        self::assertNotNull($result->entityTag());
    }

    public function testALinkageIsTheWholeListOfTheRelatedRecordsOfTheBoundRecord(): void
    {
        $store = self::store();
        $endpoint = EndpointSamples::endpoint(
            'GET',
            '/pets/{id}/order-ids',
            static function (Endpoint $endpoint): void {
                $endpoint->bind('id', 'pets')->responds(ResponseShape::linkage('id', 'orders'));
            },
        );

        $result = self::respond(
            $endpoint,
            $store,
            Response::linkage([$store->records['orders'][self::ORDER]]),
            ['id' => $store->records['pets']['1']],
        );

        self::assertInstanceOf(RelationshipResult::class, $result);
        self::assertSame(
            ['pets', '1', 'orders', 'orders'],
            [$result->parentType, $result->parentId, $result->relationship, $result->relatedType],
        );
        self::assertFalse($result->linkage->isPaged());
        self::assertSame([self::ORDER], [$result->linkage->records()[0]->id]);
        self::assertNull($result->entityTag());
    }

    public function testMetaIsEncodedByItsCodecIntoTheMembersOfAnObject(): void
    {
        $endpoint = EndpointSamples::endpoint('GET', '/stats', static function (Endpoint $endpoint): void {
            $endpoint->responds(ResponseShape::meta(new ObjectCodec(['pets' => new IntegerCodec()], ['pets'])));
        });

        $result = self::respond($endpoint, self::store(), Response::meta(['pets' => 3]), []);

        self::assertInstanceOf(MetaResult::class, $result);
        self::assertSame(['pets' => 3], $result->meta);
    }

    /**
     * @param array<array-key, mixed> $meta
     */
    #[DataProvider('arraysOfMembers')]
    public function testMetaEncodedAsAnArrayOfMembersIsAnObject(array $meta): void
    {
        $codec = new class implements Codec {
            #[Override]
            public function decode(mixed $input, Pointer $pointer): mixed
            {
                return $input;
            }

            #[Override]
            public function encode(mixed $value): mixed
            {
                return $value;
            }

            #[Override]
            public function schema(): Schema
            {
                return new Schema(type: JsonType::Object);
            }
        };
        $endpoint = EndpointSamples::endpoint('GET', '/stats', static function (Endpoint $endpoint) use ($codec): void {
            $endpoint->responds(ResponseShape::meta($codec));
        });

        $result = self::respond($endpoint, self::store(), Response::meta($meta), []);

        self::assertInstanceOf(MetaResult::class, $result);
        self::assertSame($meta, $result->meta);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function arraysOfMembers(): iterable
    {
        yield 'members' => [['pets' => 3]];
        yield 'no member' => [[]];
    }

    public function testMetaThatIsNoObjectIsAFaultOfTheCodec(): void
    {
        $endpoint = EndpointSamples::endpoint('GET', '/stats', static function (Endpoint $endpoint): void {
            $endpoint->responds(ResponseShape::meta(new StringCodec()));
        });

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the meta codec of the endpoint "probe" encodes string, and the meta of a'
            . ' document is an object');

        self::respond($endpoint, self::store(), Response::meta('three'), []);
    }

    public function testAMonitorIsSentWith202(): void
    {
        $store = self::store();
        $endpoint = EndpointSamples::endpoint(
            'POST',
            '/customers/{id}/export',
            static function (Endpoint $endpoint): void {
                $endpoint->bind('id', 'customers')->responds(ResponseShape::accepted('orders'));
            },
        );

        $result = self::respond(
            $endpoint,
            $store,
            Response::accepted($store->records['orders'][self::ORDER]),
            ['id' => $store->records['customers']['2']],
        );

        self::assertInstanceOf(AcceptedResult::class, $result);
        self::assertSame(self::ORDER, $result->monitor->id);
    }

    public function testNoContentIsSentAs204(): void
    {
        $endpoint = EndpointSamples::endpoint('POST', '/stats/reset', static function (Endpoint $endpoint): void {
            $endpoint->responds(ResponseShape::noContent());
        });

        self::assertInstanceOf(
            NoContentResult::class,
            self::respond($endpoint, self::store(), Response::noContent(), []),
        );
    }

    public function testAStatusTheEndpointDoesNotDeclareIsAFaultOfTheHandler(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the handler of the endpoint "probe" responds with 204, which the endpoint'
            . ' does not declare');

        self::respond(
            EndpointSamples::card(),
            self::store(),
            Response::noContent(),
            ['id' => self::store()->records['pets']['1']],
        );
    }

    public function testAShapeOfAnotherKindUnderADeclaredStatusIsAFaultOfTheHandler(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the handler of the endpoint "probe" returns a collection with 200, and the'
            . ' endpoint declares a record response for it');

        self::respond(
            EndpointSamples::card(),
            self::store(),
            Response::collection([]),
            ['id' => self::store()->records['pets']['1']],
        );
    }

    public function testAFailedPreconditionOfTheWriteIs412(): void
    {
        $handler = self::failing();

        try {
            self::respond(
                EndpointSamples::card(),
                self::store(),
                $handler,
                ['id' => self::store()->records['pets']['1']],
                Precondition::listedVersions(['1']),
            );
            self::fail('the executor returned a result for a failed precondition');
        } catch (PreconditionFailedException $failure) {
            self::assertSame(412, $failure->status());
            self::assertSame('If-Match', $failure->descriptions()[0]->header);
        }
    }

    public function testAFailedPreconditionTheRequestNeverCarriedIsAFaultOfTheHandler(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('the handler of the endpoint "probe" reports a failed precondition, and its'
            . ' request carries none');

        self::respond(
            EndpointSamples::card(),
            self::store(),
            self::failing(),
            ['id' => self::store()->records['pets']['1']],
        );
    }

    public function testAFailedPreconditionUnderIfMatchStarIsAFaultOfTheHandler(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('the handler of the endpoint "probe" reports a failed precondition, and its'
            . ' request carries If-Match: *, which admits every stored version');

        self::respond(
            EndpointSamples::card(),
            self::store(),
            self::failing(),
            ['id' => self::store()->records['pets']['1']],
            Precondition::anyVersion(),
        );
    }

    public function testAContentIsOpenedAndSentInTheDeclaredMediaType(): void
    {
        $ran = [];
        $content = Content::chunks((static function () use (&$ran): Generator {
            $ran[] = 'header';

            yield "id,name\n";
        })());

        $result = self::respond(
            self::export(),
            self::store(),
            Response::content($content, 'TEXT/CSV', 'pets.csv'),
            [],
            null,
            'text/csv',
        );

        self::assertInstanceOf(ContentResult::class, $result);
        self::assertSame($content, $result->content);
        self::assertSame('text/csv', $result->mediaType->toHeaderValue());
        self::assertSame('pets.csv', $result->attachmentName);
        self::assertSame(200, $result->status());
        self::assertNull($result->entityTag());
        self::assertSame(['header'], $ran, 'the content is opened before the status is sent');
    }

    public function testAJsonValueIsWrittenByItsCodec(): void
    {
        $result = self::respond(
            self::export(),
            self::store(),
            Response::json(['count' => 2], 'application/json'),
            [],
            null,
            'application/json',
        );

        self::assertInstanceOf(ContentResult::class, $result);
        self::assertSame('{"count":2}', implode('', iterator_to_array($result->content->pieces(), false)));
    }

    public function testAFileThatCannotBeOpenedFailsBeforeTheStatusIsSent(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the content file "/nonexistent/pets.csv" cannot be opened for reading');

        self::respond(
            self::export(),
            self::store(),
            Response::content(Content::file('/nonexistent/pets.csv'), 'text/csv'),
            [],
            null,
            'text/csv',
        );
    }

    /**
     * @param callable(): Response $response
     */
    #[DataProvider('undeclaredMediaResponses')]
    public function testAMediaResponseTheEndpointDoesNotDeclareIsAFaultOfTheHandler(
        callable $response,
        string $picked,
        string $message,
    ): void {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);

        self::respond(self::export(), self::store(), $response(), [], null, $picked);
    }

    /**
     * @return iterable<string, array{callable(): Response, string, string}>
     */
    public static function undeclaredMediaResponses(): iterable
    {
        yield 'a media type not declared' => [
            static function (): Response {
                return Response::content(Content::bytes('Rex'), 'text/plain');
            },
            MediaType::JSON_API,
            'the handler of the endpoint "probe" responds with 200 in "text/plain", and the request picked'
            . ' "application/vnd.api+json", which 200 offers',
        ];
        yield 'bytes where JSON is declared' => [
            static function (): Response {
                return Response::content(Content::bytes('{}'), 'application/json');
            },
            'application/json',
            'the handler of the endpoint "probe" responds with 200 and content in "application/json", which the'
            . ' endpoint does not declare',
        ];
        yield 'another than the one picked' => [
            static function (): Response {
                return Response::content(Content::bytes('Rex'), 'text/csv');
            },
            'application/json',
            'the handler of the endpoint "probe" responds with 200 in "text/csv", and the request picked'
            . ' "application/json", which 200 offers',
        ];
        yield 'JSON:API where a content was picked' => [
            static function (): Response {
                return Response::collection([]);
            },
            'text/csv',
            'the handler of the endpoint "probe" responds with 200 in "application/vnd.api+json", and the request'
            . ' picked "text/csv", which 200 offers',
        ];
        yield 'a text that is no media type' => [
            static function (): Response {
                return Response::content(Content::bytes('Rex'), 'csv');
            },
            'text/csv',
            'the handler of the endpoint "probe" responds with 200 in "csv", and the request picked "text/csv",'
            . ' which 200 offers',
        ];
    }

    private static function export(): EndpointMetadata
    {
        return EndpointSamples::endpoint('GET', '/orders/export', static function (Endpoint $endpoint): void {
            $endpoint->responds(ResponseShape::collection('pets'))
                ->responds(ResponseShape::content('text/csv'))
                ->responds(ResponseShape::json('application/json', new ObjectCodec(['count' => new IntegerCodec()])));
        });
    }

    /**
     * @param array<string, object> $records
     */
    private static function respond(
        EndpointMetadata $endpoint,
        object $store,
        Response|Handler $response,
        array $records,
        ?Precondition $precondition = null,
        string $mediaType = MediaType::JSON_API,
        ?CollectionReading $reading = null,
    ): mixed {
        $handler = $response instanceof Handler ? $response : new class ($response) implements Handler {
            public function __construct(private readonly Response $response) {}

            #[Override]
            public function handle(Request $request): Response
            {
                return $this->response;
            }
        };
        $path = [];

        foreach ($endpoint->template->parameters() as $parameter) {
            $path[$parameter->name] = '1';
        }

        $executor = self::executor($store, $handler);
        $fields = new SparseFieldsets([]);
        $languages = new LanguagePreferences([]);

        return $executor->run(
            $endpoint,
            new Request(
                'probe',
                $path,
                $records,
                new Values([]),
                $executor->collectionQuery($endpoint, $reading, $fields, $languages, new Values([])),
                null,
                $languages,
                $precondition,
                self::request($endpoint->method),
                $mediaType,
                null,
            ),
            null,
            $fields,
            $reading,
            self::context(self::request($endpoint->method)),
        );
    }

    /**
     * a reading of pets in the default sort of `EndpointSamples::search()`
     */
    private static function reading(PageRequest $page): CollectionReading
    {
        return new CollectionReading('pets', null, new Sort([]), Sort::fromString('-name,id'), $page);
    }

    private static function context(ServerRequest $request): ExecutionContext
    {
        return new ExecutionContext($request, MediaType::jsonApi(), null, false, false, []);
    }

    private static function failing(): Handler
    {
        return new class implements Handler {
            #[Override]
            public function handle(Request $request): Response
            {
                throw new VersionMismatchException();
            }
        };
    }

    private static function executor(object $store, ?Handler $handler = null): EndpointExecutor
    {
        $registry = EndpointSamples::registry();
        $resolver = self::resolver($store, $handler);
        $gateway = new StoreGateway($registry, $resolver);

        return new EndpointExecutor(
            $registry,
            $gateway,
            new Gate($registry, $resolver, new DenialPolicy(self::identity(), new NoChallenges())),
            $resolver,
            new IncludeLoader($registry, $gateway, new Paginator($registry, $gateway)),
            new Preconditions($registry),
            new Paginator($registry, $gateway),
        );
    }

    private static function resolver(object $store, ?Handler $handler): ImplementationResolver
    {
        return new class ($store, $handler) implements ImplementationResolver {
            public function __construct(private readonly object $store, private readonly ?Handler $handler) {}

            /**
             * @template T of object
             *
             * @param class-string<T> $port
             *
             * @return T
             */
            #[Override]
            public function resolve(ImplementationReference $reference, string $port): object
            {
                $implementation = $reference->instance
                    ?? ($reference->factory !== null ? ($reference->factory)() : null)
                    ?? ($reference->entryId === 'store' ? $this->store : $this->handler);

                if (!$implementation instanceof $port) {
                    throw new UnexpectedValueException('the implementation is no instance of the port');
                }

                return $implementation;
            }
        };
    }

    /**
     * @param Closure(EndpointAttempt): bool $response
     */
    private static function withPermission(EndpointMetadata $endpoint, Closure $response): EndpointMetadata
    {
        return new EndpointMetadata(
            $endpoint->name,
            $endpoint->method,
            $endpoint->template,
            $endpoint->bindings,
            $endpoint->queryParameters,
            $endpoint->body,
            $endpoint->responses,
            $endpoint->handler,
            ImplementationReference::instance(new class ($response) implements EndpointPermission {
                /**
                 * @param Closure(EndpointAttempt): bool $response
                 */
                public function __construct(private readonly Closure $response) {}

                #[Override]
                public function allows(EndpointAttempt $attempt): bool
                {
                    return ($this->response)($attempt);
                }
            }),
            $endpoint->tag,
            $endpoint->description,
            $endpoint->preconditionParameter,
            $endpoint->leavesUnchanged,
            $endpoint->mediaResponses,
            $endpoint->jsonApiRank,
            $endpoint->mediaBodies,
        );
    }

    /**
     * @return ResourceLoader<object>&RelatedLoader<object>&object{
     *     records: array<string, array<int|string, Pet>>,
     *     loads: list<LoadRequest>,
     * }
     */
    private static function store(): object
    {
        return new class implements ResourceLoader, RelatedLoader {
            /**
             * an id of digits is an int key
             *
             * @var array<string, array<int|string, Pet>>
             */
            public array $records;

            /** @var list<LoadRequest> */
            public array $loads = [];

            public function __construct()
            {
                $this->records = [
                    'pets' => ['1' => new Pet('1', 'Rex', '2'), '2' => new Pet('2', 'Tom', '2', '4')],
                    'customers' => ['2' => new Pet('2', 'Ada')],
                    'orders' => ['3f2504e0-4f89-41d3-9a0c-0305e82c3301' => new Pet(
                        '3f2504e0-4f89-41d3-9a0c-0305e82c3301',
                        'order',
                    )],
                ];
            }

            /**
             * @return list<object|null>
             */
            #[Override]
            public function load(LoadRequest $request): array
            {
                $this->loads[] = $request;

                return array_map(function (string $id) use ($request): ?object {
                    return $this->records[$request->type][$id] ?? null;
                }, $request->ids);
            }

            /**
             * @return list<list<object>>
             */
            #[Override]
            public function loadRelated(RelatedLoadRequest $request): array
            {
                return array_map(static function (): array {
                    return [];
                }, $request->parentIds);
            }
        };
    }

    private static function identity(): Identity
    {
        return new class implements Identity {
            #[Override]
            public function isAuthenticated(): bool
            {
                return true;
            }
        };
    }

    private static function request(string $method): ServerRequest
    {
        return new ServerRequest($method, new Headers(), [], ['id' => '1'], '');
    }
}
