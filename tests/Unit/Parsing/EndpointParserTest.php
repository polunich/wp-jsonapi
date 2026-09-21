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

namespace Polunich\WpJsonApi\Tests\Unit\Parsing;

use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Codec\DateTimeCodec;
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Codec\ObjectCodec;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Declaration\BodyShape;
use Polunich\WpJsonApi\Declaration\Endpoint;
use Polunich\WpJsonApi\Declaration\ResponseShape;
use Polunich\WpJsonApi\Endpoint\BytesBody;
use Polunich\WpJsonApi\Endpoint\FormBody;
use Polunich\WpJsonApi\Endpoint\JsonBody;
use Polunich\WpJsonApi\Endpoint\MetaBody;
use Polunich\WpJsonApi\Endpoint\ResourceBody;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Error\StatusSelector;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Http\UploadedFile;
use Polunich\WpJsonApi\Metadata\ApiMetadata;
use Polunich\WpJsonApi\Metadata\EndpointMetadata;
use Polunich\WpJsonApi\Operation\Identifier;
use Polunich\WpJsonApi\Operation\Linkage;
use Polunich\WpJsonApi\Operation\Values;
use Polunich\WpJsonApi\Parsing\CollectionReadingParser;
use Polunich\WpJsonApi\Parsing\CursorConditions;
use Polunich\WpJsonApi\Parsing\CursorProfile;
use Polunich\WpJsonApi\Parsing\DocumentParser;
use Polunich\WpJsonApi\Parsing\EndpointParser;
use Polunich\WpJsonApi\Parsing\EndpointQuery;
use Polunich\WpJsonApi\Parsing\FieldsetParser;
use Polunich\WpJsonApi\Parsing\FilterParser;
use Polunich\WpJsonApi\Parsing\IncludeParser;
use Polunich\WpJsonApi\Parsing\JsonBodyDecoder;
use Polunich\WpJsonApi\Parsing\Negotiation;
use Polunich\WpJsonApi\Parsing\Negotiator;
use Polunich\WpJsonApi\Parsing\PageParser;
use Polunich\WpJsonApi\Parsing\QueryParameterGuard;
use Polunich\WpJsonApi\Parsing\SortParser;
use Polunich\WpJsonApi\Query\FieldPath;
use Polunich\WpJsonApi\Query\Filter\Condition;
use Polunich\WpJsonApi\Query\NamingPolicy;
use Polunich\WpJsonApi\Query\Pagination\Cursor;
use Polunich\WpJsonApi\Query\Pagination\CursorText;
use Polunich\WpJsonApi\Query\Pagination\Strategy;
use Polunich\WpJsonApi\Tests\Fixtures\Endpoint\EndpointSamples;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use ReflectionClass;
use RuntimeException;

/**
 * @internal
 */
#[CoversClass(EndpointParser::class)]
final class EndpointParserTest extends TestCase
{
    private const JSON_API = 'application/vnd.api+json';

    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(EndpointParser::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testTheResponseIsThePlainJsonApiMediaType(): void
    {
        $negotiation = self::parser()->negotiate(self::request('GET', [], [], ''), EndpointSamples::card());

        self::assertSame(self::JSON_API, $negotiation->contentType->toHeaderValue());
    }

    public function testContentOnAnEndpointThatReadsNoneIs415(): void
    {
        $refusal = self::refusal(static function (): mixed {
            return self::parser()->negotiate(
                self::request('GET', [], [], '{}', self::JSON_API),
                EndpointSamples::card(),
            );
        });

        self::assertSame(415, $refusal->status());
        self::assertSame([['unsupported_media_type', null]], self::problems($refusal));
    }

    public function testContentOfTheBodyAnEndpointDeclaresIsRead(): void
    {
        $negotiation = self::parser()->negotiate(
            self::request('POST', [], [], '{"data": null}', self::JSON_API),
            self::adoption(),
        );

        self::assertSame(self::JSON_API, $negotiation->contentType->toHeaderValue());
    }

    /**
     * a parameter bound to `orders` is decoded by the `UuidCodec` of its ids, which lower-cases
     */
    public function testThePathValuesAreDecodedByTheirCodecs(): void
    {
        $endpoint = EndpointSamples::endpoint(
            'GET',
            '/orders/{order}/receipt',
            static function (Endpoint $endpoint): void {
                $endpoint->bind('order', 'orders')->responds(ResponseShape::record('order'));
            },
        );

        self::assertSame(
            ['order' => '3f2504e0-4f89-41d3-9a0c-0305e82c3301'],
            self::parser()->pathValues(
                $endpoint,
                self::request('GET', ['order' => '3F2504E0-4F89-41D3-9A0C-0305E82C3301']),
            ),
        );
    }

    #[DataProvider('segmentsNoRecordHas')]
    public function testASegmentTheCodecRefusesAddressesNothing(string $segment): void
    {
        $refusal = self::refusal(static function () use ($segment): mixed {
            return self::parser()->pathValues(EndpointSamples::card(), self::request('GET', ['id' => $segment]));
        });

        self::assertSame(404, $refusal->status());
        self::assertSame([['not_found', null]], self::problems($refusal));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function segmentsNoRecordHas(): iterable
    {
        yield 'no digits' => ['rex'];
        yield 'a leading zero' => ['01'];
    }

    public function testAPathValueTheAdapterDidNotHandOverIsAFaultOfTheAdapter(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the endpoint "probe" names the parameter "id", and the request carries no'
            . ' such route parameter');

        self::parser()->pathValues(EndpointSamples::card(), self::request('GET', []));
    }

    public function testTheDeclaredQueryParametersAreDecodedByTheirCodecs(): void
    {
        $endpoint = self::report();

        $query = self::query($endpoint, self::request('GET', [], [
            'placedAfter' => '2026-01-01T00:00:00Z',
            'priceRange' => ['min' => '10', 'max' => '50'],
        ]));

        self::assertEquals(new DateTimeImmutable('2026-01-01T00:00:00Z'), $query->queryParameters->get('placedAfter'));
        self::assertSame(['min' => 10, 'max' => 50], $query->queryParameters->get('priceRange'));
        self::assertFalse($query->queryParameters->has('customerName'));
        self::assertNull($query->include);
    }

    public function testAMissingRequiredParameterAndARefusedValueAreEachOneProblem(): void
    {
        $refusal = self::refusal(static function (): mixed {
            return self::query(
                self::report(),
                self::request('GET', [], ['priceRange' => ['min' => 'ten'], 'sort' => 'name']),
            );
        });

        self::assertSame(400, $refusal->status());
        self::assertSame(
            [
                ['unsupported_query_parameter', 'sort'],
                ['missing_query_parameter', 'placedAfter'],
                ['invalid_type', 'priceRange[min]'],
            ],
            self::problems($refusal),
        );
    }

    public function testIncludeAndFieldsAreReadAgainstTheResponse(): void
    {
        $query = self::query(
            EndpointSamples::card(),
            self::request('GET', [], ['include' => 'owner', 'fields' => ['pets' => 'name']]),
        );

        self::assertSame('owner', $query->include?->toString());
        self::assertSame(['name'], $query->fields->fields('pets'));
    }

    public function testAnIncludeTheResponseCannotCarryIsRefused(): void
    {
        $refusal = self::refusal(static function (): mixed {
            return self::query(EndpointSamples::card(), self::request('GET', [], ['include' => 'vets']));
        });

        self::assertSame([['unsupported_include', 'include']], self::problems($refusal));
    }

    public function testAPageReadsFilterSortAndPageAgainstItsType(): void
    {
        $query = self::query(EndpointSamples::search(), self::request('GET', [], [
            'filter' => ['name' => ['@eq' => 'Rex']],
            'sort' => 'name',
            'page' => ['size' => '3'],
        ]));

        self::assertSame('pets', $query->reading?->type);
        self::assertEquals(new Condition(new FieldPath(['name']), '@eq', 'Rex'), $query->reading->filter);
        self::assertSame('name', $query->reading->sort->toString());
        self::assertSame('name,id', $query->reading->readingSort->toString());
        self::assertSame(3, $query->reading->page->size);
    }

    public function testAPageReadsByTheDefaultSortAndWithinThePolicyOfItsEndpoint(): void
    {
        $query = self::query(EndpointSamples::search(), self::request('GET', [], []));

        self::assertSame('-name,id', $query->reading?->readingSort->toString());
        self::assertSame(
            [['max_page_size_exceeded', 'page[size]']],
            self::problems(self::refusal(static function (): mixed {
                return self::query(EndpointSamples::search(), self::request('GET', [], ['page' => ['size' => '6']]));
            })),
        );
    }

    public function testAPageForWhichTheCursorProfileIsAskedPagesByCursor(): void
    {
        $query = self::query(
            EndpointSamples::search(),
            self::request('GET', [], []),
            new Negotiation(MediaType::jsonApi([], [CursorProfile::URI]), [CursorProfile::URI]),
        );

        self::assertSame(Strategy::Cursor, $query->reading?->page->strategy);
    }

    public function testAnEndpointWithoutAPageReadsNoCollection(): void
    {
        self::assertNull(self::query(EndpointSamples::card(), self::request('GET', [], []))->reading);
    }

    public function testAPageOfCursorsNamesTheCursorProfileWhereOtherResponsesDoNot(): void
    {
        $query = ['page' => ['size' => '2', 'after' => CursorText::encode(new Cursor(['name' => 'a'], '1'))]];

        self::assertSame(
            [CursorProfile::URI],
            self::parser()->negotiate(self::request('GET', [], $query), EndpointSamples::search())
                ->contentType->profile(),
        );
        self::assertSame(
            [],
            self::parser()->negotiate(self::request('GET', ['id' => '1'], $query), EndpointSamples::card())
                ->contentType->profile(),
        );
    }

    public function testAFamilyTheEndpointDoesNotProcessIsRefused(): void
    {
        $meta = EndpointSamples::endpoint('GET', '/stats', static function (Endpoint $endpoint): void {
            $endpoint->responds(ResponseShape::meta(new IntegerCodec()));
        });

        $refusal = self::refusal(static function () use ($meta): mixed {
            return self::query(
                $meta,
                self::request(
                    'GET',
                    [],
                    ['include' => 'owner', 'page' => ['size' => '1'], 'filter' => ['name' => 'x']],
                ),
            );
        });

        self::assertSame(
            [
                ['unsupported_query_parameter', 'include'],
                ['unsupported_query_parameter', 'page[size]'],
                ['unsupported_query_parameter', 'filter[name]'],
            ],
            self::problems($refusal),
        );
    }

    public function testAnEndpointWithoutABodyReadsNone(): void
    {
        $read = self::parser()->body(EndpointSamples::card(), self::request('GET', ['id' => '1']), ['id' => '1']);

        self::assertNull($read->value);
        self::assertNull($read->mediaType);
    }

    /**
     * @param callable(Endpoint): mixed $declare
     */
    #[DataProvider('bodies')]
    public function testTheBodyIsReadInItsDeclaredShape(
        callable $declare,
        string $body,
        ResourceBody|Linkage|MetaBody $expected,
    ): void {
        $endpoint = EndpointSamples::endpoint(
            'POST',
            '/pets/{id}/act',
            static function (Endpoint $endpoint) use ($declare): void {
                $endpoint->bind('id', 'pets')->responds(ResponseShape::noContent());
                $declare($endpoint);
            },
        );

        $read = self::parser()->body(
            $endpoint,
            self::request('POST', ['id' => '1'], [], $body, self::JSON_API),
            ['id' => '1'],
        );

        self::assertEquals($expected, $read->value);
        self::assertSame(self::JSON_API, $read->mediaType);
    }

    /**
     * @return iterable<string, array{
     *     callable(Endpoint): mixed,
     *     string,
     *     ResourceBody|Linkage|MetaBody,
     * }>
     */
    public static function bodies(): iterable
    {
        yield 'a new resource' => [
            static function (Endpoint $endpoint): mixed {
                return $endpoint->body(BodyShape::resource('pets'));
            },
            '{"data": {"type": "pets", "attributes": {"name": "Rex"}, "relationships": {"owner": {"data": {"type":'
            . ' "customers", "id": "2"}}}}}',
            new ResourceBody(
                'pets',
                null,
                new Values(['name' => 'Rex']),
                new Values(['owner' => Linkage::toOne(new Identifier('customers', '2'))]),
            ),
        ];
        yield 'the bound record' => [
            static function (Endpoint $endpoint): mixed {
                return $endpoint->body(BodyShape::record('id'));
            },
            '{"data": {"type": "pets", "id": "1", "attributes": {"name": "Max"}}}',
            new ResourceBody('pets', '1', new Values(['name' => 'Max']), new Values([])),
        ];
        yield 'one identifier' => [
            static function (Endpoint $endpoint): mixed {
                return $endpoint->body(BodyShape::toOne('customers'));
            },
            '{"data": {"type": "customers", "id": "2"}}',
            Linkage::toOne(new Identifier('customers', '2')),
        ];
        yield 'no identifier' => [
            static function (Endpoint $endpoint): mixed {
                return $endpoint->body(BodyShape::toOne('customers'));
            },
            '{"data": null}',
            Linkage::toOne(null),
        ];
        yield 'a list of identifiers' => [
            static function (Endpoint $endpoint): mixed {
                return $endpoint->body(BodyShape::toMany('customers'));
            },
            '{"data": [{"type": "customers", "id": "2"}, {"type": "customers", "id": "3"}]}',
            Linkage::toMany([new Identifier('customers', '2'), new Identifier('customers', '3')]),
        ];
        yield 'meta' => [
            static function (Endpoint $endpoint): mixed {
                return $endpoint->body(BodyShape::meta(new ObjectCodec(['note' => new StringCodec()], ['note'])));
            },
            '{"meta": {"note": "second visit"}}',
            new MetaBody(['note' => 'second visit']),
        ];
    }

    /**
     * @param callable(Endpoint): mixed    $declare
     * @param list<array{string, ?string}> $problems code and pointer
     */
    #[DataProvider('refusedBodies')]
    public function testABodyOutsideItsShapeIsRefused(
        callable $declare,
        string $body,
        int $status,
        array $problems,
    ): void {
        $endpoint = EndpointSamples::endpoint(
            'POST',
            '/pets/{id}/act',
            static function (Endpoint $endpoint) use ($declare): void {
                $endpoint->bind('id', 'pets')->responds(ResponseShape::noContent());
                $declare($endpoint);
            },
        );

        $refusal = self::refusal(static function () use ($endpoint, $body): mixed {
            return self::parser()->body(
                $endpoint,
                self::request('POST', ['id' => '1'], [], $body, self::JSON_API),
                ['id' => '1'],
            );
        });

        self::assertSame($status, $refusal->status());
        self::assertSame($problems, array_map(static function (ErrorDescription $description): array {
            return [
                $description->code instanceof ErrorCode ? $description->code->value : $description->code,
                $description->pointer,
            ];
        }, $refusal->descriptions()));
    }

    /**
     * @return iterable<string, array{
     *     callable(Endpoint): mixed,
     *     string,
     *     int,
     *     list<array{string, ?string}>,
     * }>
     */
    public static function refusedBodies(): iterable
    {
        $resource = static function (Endpoint $endpoint): mixed {
            return $endpoint->body(BodyShape::resource('pets'));
        };
        $record = static function (Endpoint $endpoint): mixed {
            return $endpoint->body(BodyShape::record('id'));
        };
        $meta = static function (Endpoint $endpoint): mixed {
            return $endpoint->body(BodyShape::meta(new ObjectCodec(['note' => new StringCodec()], ['note'])));
        };

        yield 'no JSON' => [$resource, '{"data": ', 400, [['invalid_json', null]]];
        yield 'another type' => [
            $resource,
            '{"data": {"type": "customers", "attributes": {"name": "x"}}}',
            409,
            [['type_mismatch', '/data/type']],
        ];
        yield 'another record' => [
            $record,
            '{"data": {"type": "pets", "id": "2", "attributes": {"name": "Max"}}}',
            409,
            [['id_mismatch', '/data/id']],
        ];
        yield 'meta that is no object of its codec' => [
            $meta,
            '{"meta": {"note": 1}}',
            422,
            [['invalid_type', '/meta/note']],
        ];
        yield 'meta beside primary data' => [
            $meta,
            '{"meta": {"note": "x"}, "data": null}',
            400,
            [['bad_request', '/data']],
        ];
        yield 'no meta' => [$meta, '{"jsonapi": {}}', 400, [['bad_request', '']]];
    }

    public function testAJsonBodyIsReadByItsCodec(): void
    {
        $read = self::parser()->body(
            self::webhook(),
            self::request('POST', [], [], '{"amount": 12}', 'application/json; charset=utf-8'),
            [],
        );

        self::assertInstanceOf(JsonBody::class, $read->value);
        self::assertSame(['amount' => 12], $read->value->value);
        self::assertSame('application/json', $read->mediaType);
    }

    public function testAJsonBodyThatIsNoJsonIs400AndAValueTheCodecRefusesIs422WithItsPointer(): void
    {
        $broken = self::refusal(static function (): void {
            self::parser()->body(self::webhook(), self::request('POST', [], [], '{', 'application/json'), []);
        });
        $refused = self::refusal(static function (): void {
            self::parser()->body(
                self::webhook(),
                self::request('POST', [], [], '{"amount": "twelve"}', 'application/json'),
                [],
            );
        });

        self::assertSame(400, $broken->status());
        self::assertSame([['invalid_json', null]], self::problems($broken));
        self::assertSame(422, $refused->status());
        self::assertSame('/amount', $refused->descriptions()[0]->pointer);
    }

    public function testTheBytesOfAnotherMediaTypeAreReadAsTheyCame(): void
    {
        $endpoint = EndpointSamples::endpoint('POST', '/pets/{id}/photo', static function (Endpoint $endpoint): void {
            $endpoint->bind('id', 'pets')->body(BodyShape::bytes('image/png'))->responds(ResponseShape::noContent());
        });

        $read = self::parser()->body(
            $endpoint,
            self::request('POST', ['id' => '1'], [], "\x89PNG", 'image/png'),
            ['id' => '1'],
        );

        self::assertInstanceOf(BytesBody::class, $read->value);
        self::assertSame("\x89PNG", $read->value->bytes);
        self::assertSame('image/png', $read->mediaType);
    }

    public function testTheContentTypePicksTheBodyAmongTheDeclaredOnes(): void
    {
        $endpoint = EndpointSamples::endpoint('POST', '/pets/{id}/notes', static function (Endpoint $endpoint): void {
            $endpoint->bind('id', 'pets')
                ->body(BodyShape::meta(new StringCodec()))
                ->body(BodyShape::bytes('text/plain'))
                ->responds(ResponseShape::noContent());
        });

        $meta = self::parser()->body(
            $endpoint,
            self::request('POST', ['id' => '1'], [], '{"meta": "calm"}', self::JSON_API),
            ['id' => '1'],
        );
        $text = self::parser()->body(
            $endpoint,
            self::request('POST', ['id' => '1'], [], 'calm', 'text/plain'),
            ['id' => '1'],
        );

        self::assertInstanceOf(MetaBody::class, $meta->value);
        self::assertSame(self::JSON_API, $meta->mediaType);
        self::assertInstanceOf(BytesBody::class, $text->value);
        self::assertSame('text/plain', $text->mediaType);
    }

    public function testARequestWithoutContentIsReadAsTheEmptyContentOfTheFirstBody(): void
    {
        $empty = self::refusal(static function (): void {
            self::parser()->body(
                self::upload(),
                new ServerRequest('POST', new Headers(), [], ['id' => '1'], ''),
                ['id' => '1'],
            );
        });
        $webhook = self::refusal(static function (): void {
            self::parser()->body(self::webhook(), new ServerRequest('POST', new Headers(), [], [], ''), []);
        });

        self::assertSame(422, $empty->status());
        self::assertSame([
            'missing_form_field',
            'missing_form_field',
        ], array_map(static function (ErrorDescription $description): string {
            return $description->code instanceof ErrorCode ? $description->code->value : $description->code;
        }, $empty->descriptions()));
        self::assertSame([['invalid_json', null]], self::problems($webhook));
    }

    public function testAFormReadsItsFieldsByTheirCodecsAndItsFiles(): void
    {
        $photo = new UploadedFile('rex.jpg', 'image/jpeg', 2048, '/tmp/phpA', UPLOAD_ERR_OK);
        $request = self::formRequest(['caption' => 'Rex', 'rating' => '5'], ['photo' => $photo]);

        $read = self::parser()->body(self::upload(), $request, ['id' => '1']);

        self::assertInstanceOf(FormBody::class, $read->value);
        self::assertSame('Rex', $read->value->fields->get('caption'));
        self::assertSame(5, $read->value->fields->get('rating'));
        self::assertSame($photo, $read->value->file('photo'));
        self::assertFalse($read->value->hasFile('thumbnail'));
    }

    public function testAnOptionalFieldOrFileTheFormLacksIsAbsent(): void
    {
        $photo = new UploadedFile('rex.jpg', 'image/jpeg', 2048, '/tmp/phpA', UPLOAD_ERR_OK);
        $empty = new UploadedFile('', '', 0, '', UPLOAD_ERR_NO_FILE);

        $read = self::parser()->body(
            self::upload(),
            self::formRequest(['caption' => 'Rex'], ['photo' => $photo, 'thumbnail' => $empty]),
            ['id' => '1'],
        );

        self::assertInstanceOf(FormBody::class, $read->value);
        self::assertFalse($read->value->fields->has('rating'));
        self::assertFalse($read->value->hasFile('thumbnail'));
    }

    public function testEveryProblemOfAFormNamesItsField(): void
    {
        $several = [new UploadedFile('a.jpg', 'image/jpeg', 1, '/tmp/a', UPLOAD_ERR_OK)];
        $refusal = self::refusal(static function () use ($several): void {
            self::parser()->body(
                self::upload(),
                self::formRequest(['rating' => 'five', 'colour' => 'brown'], ['photo' => $several]),
                ['id' => '1'],
            );
        });

        self::assertSame(422, $refusal->status());
        self::assertSame(
            [
                ['missing_form_field', ['field' => 'caption']],
                ['invalid_type', ['field' => 'rating']],
                ['invalid_form_file', ['field' => 'photo']],
                ['unknown_form_field', ['field' => 'colour']],
            ],
            array_map(static function (ErrorDescription $description): array {
                return [
                    $description->code instanceof ErrorCode ? $description->code->value : $description->code,
                    $description->meta,
                ];
            }, $refusal->descriptions()),
        );

        foreach ($refusal->descriptions() as $description) {
            self::assertNull($description->pointer);
            self::assertNull($description->parameter);
        }
    }

    /**
     * @param int $error an `UPLOAD_ERR_*` code
     */
    #[DataProvider('refusedUploads')]
    public function testAFilePhpRefusedGetsTheErrorOfItsCause(int $error, int $status, string $code): void
    {
        $photo = new UploadedFile('rex.jpg', 'image/jpeg', 0, '', $error);
        $refusal = self::refusal(static function () use ($photo): void {
            self::parser()->body(
                self::upload(),
                self::formRequest(['caption' => 'Rex'], ['photo' => $photo]),
                ['id' => '1'],
            );
        });

        self::assertSame($status, $refusal->status());
        self::assertSame(
            $code,
            $refusal->descriptions()[0]->code instanceof ErrorCode ? $refusal->descriptions()[0]->code->value : '',
        );
        self::assertSame(['field' => 'photo'], $refusal->descriptions()[0]->meta);
    }

    /**
     * @return iterable<string, array{int, int, string}>
     */
    public static function refusedUploads(): iterable
    {
        yield 'larger than upload_max_filesize' => [UPLOAD_ERR_INI_SIZE, 413, 'content_too_large'];
        yield 'larger than MAX_FILE_SIZE' => [UPLOAD_ERR_FORM_SIZE, 413, 'content_too_large'];
        yield 'cut' => [UPLOAD_ERR_PARTIAL, 400, 'upload_incomplete'];
        yield 'missing where required' => [UPLOAD_ERR_NO_FILE, 422, 'missing_form_field'];
    }

    #[DataProvider('storeFailures')]
    public function testAFilePhpCouldNotStoreIsAFaultOfTheSite(int $error, string $reason): void
    {
        $photo = new UploadedFile('rex.jpg', 'image/jpeg', 0, '', $error);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(sprintf(
            'PHP could not store the file of the form field "photo", error %d: %s',
            $error,
            $reason,
        ));

        self::parser()->body(
            self::upload(),
            self::formRequest(['caption' => 'Rex'], ['photo' => $photo]),
            ['id' => '1'],
        );
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function storeFailures(): iterable
    {
        yield 'no temporary directory' => [UPLOAD_ERR_NO_TMP_DIR, 'the temporary directory is missing'];
        yield 'no write' => [UPLOAD_ERR_CANT_WRITE, 'the file could not be written to disk'];
        yield 'an extension' => [UPLOAD_ERR_EXTENSION, 'an extension stopped the upload'];
    }

    public function testAFormPhpDroppedAsTooLargeIs413(): void
    {
        $request = new ServerRequest(
            'POST',
            new Headers(['Content-Type' => 'multipart/form-data; boundary=x', 'Content-Length' => '99999999']),
            [],
            ['id' => '1'],
            '',
            [],
            [],
            true,
        );
        $refusal = self::refusal(static function () use ($request): void {
            self::parser()->body(self::upload(), $request, ['id' => '1']);
        });

        self::assertSame(413, $refusal->status());
        self::assertSame([['content_too_large', null]], self::problems($refusal));
        self::assertSame('Content-Length', $refusal->descriptions()[0]->header);
    }

    private static function webhook(): EndpointMetadata
    {
        return EndpointSamples::endpoint('POST', '/webhooks/payments', static function (Endpoint $endpoint): void {
            $endpoint->body(BodyShape::json(
                'application/json',
                new ObjectCodec(['amount' => new IntegerCodec()], ['amount']),
            ))->responds(ResponseShape::noContent());
        });
    }

    private static function upload(): EndpointMetadata
    {
        return EndpointSamples::endpoint('POST', '/pets/{id}/photos', static function (Endpoint $endpoint): void {
            $endpoint->bind('id', 'pets')
                ->body(BodyShape::form('multipart/form-data')
                    ->field('caption', new StringCodec(), required: true)
                    ->field('rating', new IntegerCodec())
                    ->file('photo', required: true)
                    ->file('thumbnail'))
                ->responds(ResponseShape::noContent());
        });
    }

    /**
     * @param array<array-key, mixed> $form
     * @param array<array-key, mixed> $files
     */
    private static function formRequest(array $form, array $files): ServerRequest
    {
        return new ServerRequest(
            'POST',
            new Headers(['Content-Type' => 'multipart/form-data; boundary=x', 'Content-Length' => '4812']),
            [],
            ['id' => '1'],
            '',
            $form,
            $files,
        );
    }

    /**
     * @throws JsonApiException
     */
    private static function query(
        EndpointMetadata $endpoint,
        ServerRequest $request,
        ?Negotiation $negotiation = null,
    ): EndpointQuery {
        return self::parser()->query($endpoint, $request, $negotiation ?? new Negotiation(MediaType::jsonApi()));
    }

    private static function parser(): EndpointParser
    {
        $registry = EndpointSamples::registry();
        $api = new ApiMetadata(
            'pets api',
            '1.0',
            'acme/v1',
            $registry,
            [],
            [],
            Samples::operators(),
            [],
            null,
            Samples::languageSources(),
            NamingPolicy::SnakeCase,
            true,
        );

        return new EndpointParser(
            $registry,
            new Negotiator(),
            new QueryParameterGuard([]),
            new IncludeParser($registry),
            new FieldsetParser($registry),
            new CollectionReadingParser(
                new FilterParser($api),
                new SortParser($registry),
                new PageParser(NamingPolicy::SnakeCase),
                new CursorConditions($registry, NamingPolicy::SnakeCase),
            ),
            new JsonBodyDecoder(),
            new DocumentParser($registry),
            new StatusSelector(),
        );
    }

    private static function adoption(): EndpointMetadata
    {
        return EndpointSamples::endpoint('POST', '/pets/{id}/adopt', static function (Endpoint $endpoint): void {
            $endpoint->bind('id', 'pets')->body(BodyShape::toOne('customers'))->responds(ResponseShape::record('id'));
        });
    }

    private static function report(): EndpointMetadata
    {
        return EndpointSamples::endpoint('GET', '/orders/report', static function (Endpoint $endpoint): void {
            $endpoint->queryParameter('placedAfter', new DateTimeCodec(), required: true)
                ->queryParameter(
                    'priceRange',
                    new ObjectCodec(['min' => new IntegerCodec(), 'max' => new IntegerCodec()]),
                )
                ->queryParameter('customerName', new StringCodec())
                ->responds(ResponseShape::collection('orders'));
        });
    }

    /**
     * @param array<string, string>   $path
     * @param array<array-key, mixed> $query
     */
    private static function request(
        string $method,
        array $path,
        array $query = [],
        string $body = '',
        ?string $contentType = null,
    ): ServerRequest {
        $fields = ['Accept' => self::JSON_API];

        if ($contentType !== null) {
            $fields['Content-Type'] = $contentType;
        }

        return new ServerRequest($method, new Headers($fields), $query, $path, $body);
    }

    /**
     * @param callable(): mixed $call
     */
    private static function refusal(callable $call): JsonApiException
    {
        try {
            $call();
        } catch (JsonApiException $exception) {
            return $exception;
        }

        self::fail('the request is refused');
    }

    /**
     * @return list<array{string, ?string}> code and the parameter named
     */
    private static function problems(JsonApiException $exception): array
    {
        return array_map(static function (ErrorDescription $description): array {
            return [
                $description->code instanceof ErrorCode ? $description->code->value : $description->code,
                $description->parameter,
            ];
        }, $exception->descriptions());
    }
}
