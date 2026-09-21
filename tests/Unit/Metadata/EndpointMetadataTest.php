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

namespace Polunich\WpJsonApi\Tests\Unit\Metadata;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Codec\DigitsCodec;
use Polunich\WpJsonApi\Codec\IntegerCodec;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Metadata\Cardinality;
use Polunich\WpJsonApi\Metadata\Endpoint\BodyMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\BodyShapeKind;
use Polunich\WpJsonApi\Metadata\Endpoint\MediaBodyMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\MediaBodyShapeKind;
use Polunich\WpJsonApi\Metadata\Endpoint\MediaResponseMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseMetadata;
use Polunich\WpJsonApi\Metadata\Endpoint\ResponseShapeKind;
use Polunich\WpJsonApi\Metadata\EndpointMetadata;
use Polunich\WpJsonApi\Metadata\PaginationPolicy;
use Polunich\WpJsonApi\Metadata\QueryParameterMetadata;
use Polunich\WpJsonApi\Query\Sort\Sort;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use ReflectionClass;

/**
 * @internal
 */
#[CoversClass(EndpointMetadata::class)]
final class EndpointMetadataTest extends TestCase
{
    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(EndpointMetadata::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testASecuritySchemeNamedTwiceIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the endpoint "adoptPet" declares the security scheme "restNonce" twice');

        Samples::endpoint(securitySchemes: [SecurityScheme::restNonce(), SecurityScheme::restNonce()]);
    }

    public function testAGetEndpointRespondsToHeadAndIsSafe(): void
    {
        $read = Samples::endpoint('petStats', 'GET', '/pets/{id}/stats');
        $write = Samples::endpoint();

        self::assertSame(['GET', 'HEAD'], $read->methods);
        self::assertTrue($read->isSafe());
        self::assertSame(['POST'], $write->methods);
        self::assertFalse($write->isSafe());
    }

    #[DataProvider('preconditionRecordWrites')]
    public function testAnEndpointWritesItsPreconditionRecordUnlessItReadsOrLeavesItUnchanged(
        string $method,
        ?string $preconditionParameter,
        bool $leavesUnchanged,
        bool $writes,
    ): void {
        $endpoint = Samples::endpoint(
            'touchPet',
            $method,
            '/pets/{id}/touch',
            responses: [200 => new ResponseMetadata(ResponseShapeKind::Record, 'pets', 'id', null, null, null)],
            preconditionParameter: $preconditionParameter,
            leavesUnchanged: $leavesUnchanged,
        );

        self::assertSame($writes, $endpoint->writesPreconditionRecord());
    }

    /**
     * @return iterable<string, array{string, ?string, bool, bool}>
     */
    public static function preconditionRecordWrites(): iterable
    {
        yield 'a write of its precondition record' => ['POST', 'id', false, true];
        yield 'a write without a precondition record' => ['POST', null, false, false];
        yield 'a write that leaves its record unchanged' => ['POST', 'id', true, false];
        yield 'a read of its precondition record' => ['GET', 'id', false, false];
    }

    #[DataProvider('taggedResponses')]
    public function testAResponseCarriesTheEntityTagOfThePreconditionRecordOrOfACreatedResourceButNeverToPut(
        string $method,
        ResponseMetadata $response,
        bool $tagged,
    ): void {
        $endpoint = Samples::endpoint(
            'savePet',
            $method,
            '/pets/{id}/{other}',
            bindings: ['id' => 'pets', 'other' => 'pets'],
            responses: [$response->kind->status() => $response],
            preconditionParameter: 'id',
        );

        self::assertSame($tagged, $endpoint->carriesEntityTag($response));
    }

    /**
     * @return iterable<string, array{string, ResponseMetadata, bool}>
     */
    public static function taggedResponses(): iterable
    {
        $record = new ResponseMetadata(ResponseShapeKind::Record, 'pets', 'id', null, null, null);
        $created = new ResponseMetadata(ResponseShapeKind::Created, 'pets', null, null, null, null);

        yield 'the precondition record' => ['PATCH', $record, true];
        yield 'another bound record' => [
            'PATCH',
            new ResponseMetadata(ResponseShapeKind::Record, 'pets', 'other', null, null, null),
            false,
        ];
        yield 'a created resource' => ['POST', $created, true];
        yield 'a resource that was not created' => [
            'POST',
            new ResponseMetadata(ResponseShapeKind::Resource, 'pets', null, null, null, null),
            false,
        ];
        yield 'the precondition record in a response to PUT' => ['PUT', $record, false];
        yield 'a resource PUT created' => ['PUT', $created, false];
    }

    public function testAResponseIsReadByItsStatus(): void
    {
        $endpoint = Samples::endpoint();

        self::assertSame(ResponseShapeKind::Record, $endpoint->response(200)?->kind);
        self::assertNull($endpoint->response(201));
    }

    public function testTheIncludePathsStartWhereTheResponsesOfResourcesStart(): void
    {
        $linkage = Samples::endpoint(responses: [200 => new ResponseMetadata(
            ResponseShapeKind::Linkage,
            'customers',
            'id',
            'owner',
            Cardinality::ToOne,
            null,
        )]);
        $meta = Samples::endpoint(responses: [200 => new ResponseMetadata(
            ResponseShapeKind::Meta,
            null,
            null,
            null,
            null,
            new IntegerCodec(),
        )]);
        $accepted = Samples::endpoint(responses: [
            202 => new ResponseMetadata(ResponseShapeKind::Accepted, 'jobs', null, null, null, null),
            201 => new ResponseMetadata(ResponseShapeKind::Created, 'photos', null, null, null, null),
        ]);

        self::assertSame(['pets', null], Samples::endpoint()->includeRoot());
        self::assertSame(['pets', 'owner'], $linkage->includeRoot());
        self::assertNull($meta->includeRoot());
        self::assertSame(['photos', null], $accepted->includeRoot());
    }

    #[DataProvider('refusedNames')]
    public function testANameThatIsNoOperationIdOfTheConventionsIsRefused(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('recommends to "follow common programming naming conventions"');

        Samples::endpoint($name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedNames(): iterable
    {
        yield 'empty' => [''];
        yield 'a leading digit' => ['1adopt'];
        yield 'a dot, which every generated operationId holds' => ['fetch_resource.pets'];
        yield 'a hyphen' => ['adopt-pet'];
    }

    public function testAMethodOfNoEndpointIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the endpoint "adoptPet" responds to OPTIONS, and an endpoint responds to one of'
            . ' GET, POST, PUT, PATCH, DELETE');

        Samples::endpoint(method: 'OPTIONS');
    }

    /**
     * @param array<string, string>         $bindings
     * @param list<QueryParameterMetadata>  $queryParameters
     * @param ?array<int, ResponseMetadata> $responses
     */
    #[DataProvider('refusedDeclarations')]
    public function testADeclarationThatDoesNotHoldTogetherIsRefused(
        string $method,
        array $bindings,
        array $queryParameters,
        ?BodyMetadata $body,
        ?array $responses,
        ?string $precondition,
        bool $leavesUnchanged,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Samples::endpoint(
            method: $method,
            path: '/pets/{id}/adopt',
            bindings: $bindings,
            codecs: $bindings === [] ? ['id' => new DigitsCodec()] : [],
            queryParameters: $queryParameters,
            body: $body,
            responses: $responses,
            preconditionParameter: $precondition,
            leavesUnchanged: $leavesUnchanged,
        );
    }

    /**
     * @return iterable<string, array{
     *     string,
     *     array<string, string>,
     *     list<QueryParameterMetadata>,
     *     ?BodyMetadata,
     *     ?array<int, ResponseMetadata>,
     *     ?string,
     *     bool,
     *     string,
     * }>
     */
    public static function refusedDeclarations(): iterable
    {
        $pet = [200 => new ResponseMetadata(ResponseShapeKind::Resource, 'pets', null, null, null, null)];

        yield 'a query parameter declared twice' => [
            'POST', ['id' => 'pets'],
            [
                new QueryParameterMetadata('adoptionNote', new StringCodec(), false),
                new QueryParameterMetadata('adoptionNote', new StringCodec(), true),
            ],
            null, null, null, false,
            'the endpoint "adoptPet" declares the query parameter "adoptionNote" twice',
        ];
        yield 'a body on GET' => [
            'GET', ['id' => 'pets'], [], new BodyMetadata(
                BodyShapeKind::ToOne,
                'customers',
                null,
                null,
            ), null, null, false,
            'the endpoint "adoptPet" responds to GET and reads a body, and "content received in a GET request has'
            . ' no generally defined semantics"',
        ];
        yield 'a record body of a parameter bound to no type' => [
            'POST', [], [], new BodyMetadata(BodyShapeKind::Record, 'pets', 'id', null), $pet, null, false,
            'the endpoint "adoptPet" names the parameter "id" in its body, and binds it to no type',
        ];
        yield 'no response' => [
            'POST', ['id' => 'pets'], [], null, [], null, false,
            'the endpoint "adoptPet" declares no response,'
                . ' and an endpoint declares the response of each status it gives',
        ];
        yield 'a response under another status' => [
            'POST', ['id' => 'pets'], [], null, [201 => new ResponseMetadata(
                ResponseShapeKind::Resource,
                'pets',
                null,
                null,
                null,
                null,
            )], null, false,
            'the endpoint "adoptPet" holds a resource response under the status 201,'
                . ' and that response has the status 200',
        ];
        yield 'a 201 on GET' => [
            'GET', ['id' => 'pets'], [], null, [201 => new ResponseMetadata(
                ResponseShapeKind::Created,
                'photos',
                null,
                null,
                null,
                null,
            )], null, false,
            'the endpoint "adoptPet" responds to GET with 201, and a safe method requests no state change',
        ];
        yield 'a record response of a parameter bound to no type' => [
            'POST', [], [], null, null, null, false,
            'the endpoint "adoptPet" names the parameter "id" in its response of 200, and binds it to no type',
        ];
        yield 'preconditions of a parameter bound to no type' => [
            'POST', [], [], null, $pet, 'id', false,
            'the endpoint "adoptPet" names the parameter "id" in its preconditions, and binds it to no type',
        ];
        yield 'an unchanged record where none is read' => [
            'POST', ['id' => 'pets'], [], null, $pet, null, true,
            'the endpoint "adoptPet" declares that it leaves its precondition record unchanged, and only a write'
            . ' on a record of a versioned type has one',
        ];
        yield 'an unchanged record on GET' => [
            'GET', ['id' => 'pets'], [], null, $pet, 'id', true,
            'the endpoint "adoptPet" declares that it leaves its precondition record unchanged',
        ];
    }

    public function testThePageTypeIsTheTypeOfAPageResponse(): void
    {
        self::assertSame('pets', Samples::pageEndpoint()->pageType());
        self::assertNull(Samples::endpoint()->pageType());
    }

    public function testAPageResponseKeepsTheDefaultSortAndThePoliciesDeclared(): void
    {
        $endpoint = Samples::pageEndpoint(
            defaultSort: Sort::fromString('-name'),
            paginationPolicy: new PaginationPolicy(5, 20),
        );

        self::assertSame('-name', $endpoint->defaultSort?->toString());
        self::assertEquals(new PaginationPolicy(5, 20), $endpoint->paginationPolicy);
    }

    public function testAPageRespondsToGetAlone(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the endpoint "searchPets" responds to POST with a page, and a client follows'
            . ' the pagination links of a page with GET, which does not reach this endpoint');

        Samples::pageEndpoint(method: 'POST');
    }

    #[DataProvider('pageDeclarationsWithoutAPage')]
    public function testADefaultSortOrAPolicyWithoutAPageIsRefused(
        ?Sort $defaultSort,
        ?PaginationPolicy $paginationPolicy,
        string $declares,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'the endpoint "adoptPet" declares %s and responds with no page, which alone reads it',
            $declares,
        ));

        Samples::endpoint(defaultSort: $defaultSort, paginationPolicy: $paginationPolicy);
    }

    /**
     * @return iterable<string, array{?Sort, ?PaginationPolicy, string}>
     */
    public static function pageDeclarationsWithoutAPage(): iterable
    {
        yield 'a default sort' => [Sort::fromString('name'), null, 'a default sort'];
        yield 'a pagination policy' => [null, new PaginationPolicy(5, 20), 'a pagination policy'];
    }

    public function testADefaultSortWithoutASortFieldIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the endpoint "searchPets" declares a default sort without a sort field, and a'
            . ' default sort that is not declared is null');

        Samples::pageEndpoint(defaultSort: new Sort([]));
    }

    public function testABindingOfNoParameterOfThePathIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the endpoint "adoptPet" binds the parameter "owner", which its path'
            . ' "/pets/{id}/adopt" does not hold');

        new EndpointMetadata(
            'adoptPet',
            'POST',
            Samples::endpoint()->template,
            ['id' => 'pets', 'owner' => 'customers'],
            [],
            null,
            [200 => new ResponseMetadata(ResponseShapeKind::Resource, 'pets', null, null, null, null)],
            Samples::implementation(),
            Samples::implementation(),
            null,
            null,
            null,
            false,
        );
    }

    public function testTheRepresentationsFollowTheDeclaration(): void
    {
        $csv = new MediaResponseMetadata(self::mediaType('text/csv'), null);
        $xlsx = new MediaResponseMetadata(
            self::mediaType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            null,
        );
        $collection = [200 => new ResponseMetadata(ResponseShapeKind::Collection, 'orders', null, null, null, null)];

        self::assertSame(
            ['application/vnd.api+json'],
            self::rendered(Samples::endpoint(method: 'GET')->representations()),
        );
        self::assertSame(
            [
                'text/csv',
                'application/vnd.api+json',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ],
            self::rendered(Samples::endpoint(
                method: 'GET',
                bindings: [],
                path: '/orders/export',
                responses: $collection,
                mediaResponses: [$csv, $xlsx],
                jsonApiRank: 1,
            )->representations()),
        );
        self::assertSame(
            ['text/csv'],
            self::rendered(Samples::endpoint(
                method: 'GET',
                bindings: [],
                path: '/orders/export',
                responses: [],
                mediaResponses: [$csv],
            )->representations()),
        );
        self::assertSame(
            ['text/csv'],
            self::rendered(Samples::endpoint(
                bindings: [],
                path: '/orders/export',
                responses: [204 => new ResponseMetadata(ResponseShapeKind::NoContent, null, null, null, null, null)],
                mediaResponses: [$csv],
            )->representations()),
            'no content writes no document',
        );
        self::assertSame(
            ['application/vnd.api+json'],
            self::rendered(Samples::endpoint(
                bindings: [],
                path: '/orders/export',
                responses: [204 => new ResponseMetadata(ResponseShapeKind::NoContent, null, null, null, null, null)],
            )->representations()),
            'errors are negotiated as JSON:API where nothing else is declared',
        );
    }

    public function testAMediaResponseIsFoundByItsMediaType(): void
    {
        $csv = new MediaResponseMetadata(self::mediaType('text/csv; header=present'), null);
        $endpoint = Samples::endpoint(
            method: 'GET',
            bindings: [],
            path: '/orders/export',
            responses: [],
            mediaResponses: [$csv],
        );

        self::assertSame($csv, $endpoint->mediaResponse(self::mediaType('text/csv;header=present')));
        self::assertNull($endpoint->mediaResponse(self::mediaType('text/csv')));
    }

    public function testTheReadableMediaTypesAreTheBodiesDeclared(): void
    {
        $json = new MediaBodyMetadata(self::mediaType('application/json'), MediaBodyShapeKind::Json, new StringCodec());
        $form = new MediaBodyMetadata(self::mediaType('multipart/form-data'), MediaBodyShapeKind::Form);
        $endpoint = Samples::endpoint(
            body: new BodyMetadata(BodyShapeKind::Record, 'pets', 'id', null),
            mediaBodies: [$json, $form],
        );

        self::assertSame(
            ['application/vnd.api+json', 'application/json', 'multipart/form-data'],
            self::rendered($endpoint->readableMediaTypes()),
        );
        self::assertSame($json, $endpoint->mediaBody(self::mediaType('application/json; charset=utf-8')));
        self::assertSame($form, $endpoint->mediaBody(self::mediaType('multipart/form-data; boundary=x')));
        self::assertNull($endpoint->mediaBody(self::mediaType('text/plain')));
        self::assertSame([], Samples::endpoint()->readableMediaTypes());
    }

    public function testAnEndpointOfMediaResponsesAloneResponds(): void
    {
        $csv = new MediaResponseMetadata(self::mediaType('text/csv'), null);

        self::assertSame(
            [$csv],
            Samples::endpoint(
                method: 'GET',
                bindings: [],
                path: '/orders/export',
                responses: [],
                mediaResponses: [$csv],
            )->mediaResponses,
        );
    }

    /**
     * @param list<MediaResponseMetadata> $mediaResponses
     * @param list<MediaBodyMetadata>     $bodies
     */
    #[DataProvider('refusedMediaResponses')]
    public function testMediaResponsesAndBodiesAreRefusedWhereTheyCannotBeTold(
        string $method,
        array $mediaResponses,
        int $rank,
        array $bodies,
        string $message,
    ): void {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        Samples::endpoint(method: $method, mediaResponses: $mediaResponses, jsonApiRank: $rank, mediaBodies: $bodies);
    }

    /**
     * @return iterable<string, array{
     *     string,
     *     list<MediaResponseMetadata>,
     *     int,
     *     list<MediaBodyMetadata>,
     *     string,
     * }>
     */
    public static function refusedMediaResponses(): iterable
    {
        $csv = new MediaResponseMetadata(self::mediaType('text/csv'), null);
        $json = new MediaBodyMetadata(self::mediaType('application/json'), MediaBodyShapeKind::Json, new StringCodec());
        $charset = new MediaBodyMetadata(
            self::mediaType('application/json; charset=utf-8'),
            MediaBodyShapeKind::Json,
            new StringCodec(),
        );
        $multipart = new MediaBodyMetadata(self::mediaType('multipart/form-data'), MediaBodyShapeKind::Form);

        yield 'a media type twice' => [
            'POST',
            [$csv, $csv],
            0,
            [],
            'the endpoint "adoptPet" responds with 200 in "text/csv" twice, and each representation is declared once',
        ];
        yield 'JSON:API before the first media response' => [
            'POST',
            [$csv],
            -1,
            [],
            'the endpoint "adoptPet" places JSON:API after -1 of its 1 media responses',
        ];
        yield 'JSON:API after more media responses than there are' => [
            'POST',
            [$csv],
            2,
            [],
            'the endpoint "adoptPet" places JSON:API after 2 of its 1 media responses',
        ];
        yield 'a body of GET' => ['GET', [], 0, [$json], 'the endpoint "adoptPet" responds to GET and reads a body'];
        yield 'multipart of PUT' => [
            'PUT',
            [],
            0,
            [$multipart],
            'the endpoint "adoptPet" reads multipart/form-data with PUT, and PHP stores the fields and files of'
            . ' such a body for POST alone',
        ];
        yield 'two bodies one request names' => [
            'POST',
            [],
            0,
            [$json, $charset],
            'the endpoint "adoptPet" reads "application/json" and "application/json;charset=utf-8", and a request'
            . ' of one of them would name both',
        ];
    }

    #[DataProvider('emptyTexts')]
    public function testAnEmptyTextIsRefused(?string $tag, ?string $description, string $part): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(
            'the endpoint "adoptPet" declares an empty %s, and a declared text is never empty',
            $part,
        ));

        Samples::endpoint(tag: $tag, description: $description);
    }

    /**
     * @return iterable<string, array{?string, ?string, string}>
     */
    public static function emptyTexts(): iterable
    {
        yield 'tag' => ['', null, 'tag'];
        yield 'description' => [null, '', 'description'];
    }

    /**
     * @param list<MediaType> $mediaTypes
     *
     * @return list<string>
     */
    private static function rendered(array $mediaTypes): array
    {
        return array_map(static function (MediaType $mediaType): string {
            return $mediaType->toHeaderValue();
        }, $mediaTypes);
    }

    private static function mediaType(string $text): MediaType
    {
        return MediaType::parse($text) ?? throw new InvalidArgumentException($text);
    }
}
