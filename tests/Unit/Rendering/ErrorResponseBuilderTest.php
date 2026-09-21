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

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Authentication\Challenge;
use Polunich\WpJsonApi\Document\DocumentEncoder;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\ErrorFactory;
use Polunich\WpJsonApi\Error\ForbiddenException;
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Error\LibraryErrorCatalog;
use Polunich\WpJsonApi\Error\UnauthorizedException;
use Polunich\WpJsonApi\Error\UnsupportedMediaTypeException;
use Polunich\WpJsonApi\Http\FieldName;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Metadata\LanguageSourceMetadata;
use Polunich\WpJsonApi\Metadata\LanguageSources;
use Polunich\WpJsonApi\Rendering\ErrorResponseBuilder;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use Polunich\WpJsonApi\Tests\Fixtures\ParameterNames;
use ReflectionClass;
use ReflectionMethod;

/**
 * @internal
 */
#[CoversClass(ErrorResponseBuilder::class)]
final class ErrorResponseBuilderTest extends TestCase
{
    private const CURSOR_PROFILE = 'https://jsonapi.org/profiles/ethanresnick/cursor-pagination/';

    public function testTheClassIsFinalReadonlyAndInternal(): void
    {
        $class = new ReflectionClass(ErrorResponseBuilder::class);

        self::assertTrue($class->isFinal());
        self::assertTrue($class->isReadOnly());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getDocComment());
    }

    public function testTheConstructorAndTheMethodsKeepTheirParametersAndTypes(): void
    {
        $constructor = (new ReflectionMethod(ErrorResponseBuilder::class, '__construct'))->getParameters();
        $response = (new ReflectionMethod(ErrorResponseBuilder::class, 'response'))->getParameters();

        self::assertSame(['errors', 'encoder', 'languageSources'], ParameterNames::of($constructor));
        self::assertSame(ErrorFactory::class, (string) $constructor[0]->getType());
        self::assertSame(DocumentEncoder::class, (string) $constructor[1]->getType());
        self::assertSame(LanguageSources::class, (string) $constructor[2]->getType());
        self::assertSame(
            ['error', 'request', 'contentType', 'operationPosition', 'allowedMethods', 'acceptedMediaTypes'],
            ParameterNames::of($response),
        );
        self::assertSame(JsonApiException::class, (string) $response[0]->getType());
        self::assertSame(ServerRequest::class, (string) $response[1]->getType());
        self::assertSame('?' . MediaType::class, (string) $response[2]->getType());
        self::assertSame('?int', (string) $response[3]->getType());
        self::assertNull($response[3]->getDefaultValue());
        self::assertSame('array', (string) $response[4]->getType());
        self::assertSame([], $response[4]->getDefaultValue());
        self::assertSame('array', (string) $response[5]->getType());
        self::assertSame([], $response[5]->getDefaultValue());
    }

    #[DataProvider('failures')]
    public function testTheStatusIsTheOneTheExceptionCarries(JsonApiException $error, int $status): void
    {
        self::assertSame($status, self::builder()->response($error, self::request(), MediaType::jsonApi())->status);
    }

    /**
     * @return iterable<string, array{JsonApiException, int}>
     */
    public static function failures(): iterable
    {
        yield 'a forbidden request' => [new ForbiddenException([new ErrorDescription(ErrorCode::Forbidden)]), 403];
        yield 'a request without credentials' => [
            new UnauthorizedException([new ErrorDescription(ErrorCode::Unauthorized)]),
            401,
        ];
        yield 'a status without a library class' => [
            new JsonApiException(410, [new ErrorDescription('rest_no_route')]),
            410,
        ];
    }

    /**
     * the second description carries a status of its own, which its error object keeps
     */
    public function testTheBodyIsTheErrorDocumentOfEveryDescriptionInOrder(): void
    {
        $response = self::builder()->response(
            new JsonApiException(400, [
                new ErrorDescription(ErrorCode::InvalidQueryParameter, parameter: 'filter'),
                new ErrorDescription(ErrorCode::UnsupportedSort, parameter: 'sort', status: 422),
            ]),
            self::request(),
            MediaType::jsonApi(),
        );

        self::assertNotNull($response->document);
        self::assertSame(['jsonapi', 'errors'], array_keys($response->document));

        $errors = $response->document['errors'];

        self::assertIsArray($errors);
        self::assertCount(2, $errors);
        self::assertSame(
            ['invalid_query_parameter', 'unsupported_sort'],
            array_map(static function (mixed $error): mixed {
                return is_array($error) ? $error['code'] : null;
            }, $errors),
        );
        self::assertSame(
            ['400', '422'],
            array_map(static function (mixed $error): mixed {
                return is_array($error) ? $error['status'] : null;
            }, $errors),
        );
    }

    /**
     * @param array<string, string> $expected
     */
    #[DataProvider('atomicPositions')]
    public function testThePositionOfAFailedOperationIsHandedToTheFactory(
        ErrorDescription $description,
        ?int $position,
        array $expected,
    ): void {
        $response = self::builder()->response(
            new JsonApiException(422, [$description]),
            self::request(),
            MediaType::jsonApi(),
            $position,
        );

        self::assertNotNull($response->document);
        self::assertIsArray($response->document['errors']);

        $error = $response->document['errors'][0];

        self::assertIsArray($error);
        self::assertEquals($expected, $error['source']);
    }

    /**
     * @return iterable<string, array{ErrorDescription, ?int, array<string, string>}>
     */
    public static function atomicPositions(): iterable
    {
        yield 'a pointer of a single request' => [
            new ErrorDescription(ErrorCode::UnknownField, pointer: '/data/attributes/title'),
            null,
            ['pointer' => '/data/attributes/title'],
        ];
        yield 'a pointer below its operation' => [
            new ErrorDescription(ErrorCode::UnknownField, pointer: '/data/attributes/title'),
            2,
            ['pointer' => '/atomic:operations/2/data/attributes/title'],
        ];
        yield 'a source of another kind, replaced by the operation' => [
            new ErrorDescription(ErrorCode::InvalidQueryParameter, parameter: 'filter'),
            0,
            ['pointer' => '/atomic:operations/0'],
        ];
    }

    public function testANegativePositionIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the position of an atomic operation is zero or more, got -1');

        self::builder()->response(
            new JsonApiException(422, [new ErrorDescription(ErrorCode::UnknownField)]),
            self::request(),
            MediaType::jsonApi(),
            -1,
        );
    }

    public function testTheJsonApiObjectListsTheExtAndProfileOfTheResponseMediaType(): void
    {
        $applied = self::builder()->response(
            new ForbiddenException([new ErrorDescription(ErrorCode::Forbidden)]),
            self::request(),
            MediaType::jsonApi([MediaType::ATOMIC_EXTENSION], [self::CURSOR_PROFILE]),
        );
        $unnegotiated = self::builder()->response(
            new ForbiddenException([new ErrorDescription(ErrorCode::Forbidden)]),
            self::request(),
            null,
        );

        self::assertNotNull($applied->document);
        self::assertNotNull($unnegotiated->document);
        self::assertEquals(
            [
                'version' => '1.1',
                'ext' => [MediaType::ATOMIC_EXTENSION],
                'profile' => [self::CURSOR_PROFILE],
            ],
            $applied->document['jsonapi'],
        );
        self::assertEquals(['version' => '1.1'], $unnegotiated->document['jsonapi']);
    }

    public function testTheContentTypeIsTheNegotiatedMediaTypeOrTheBareOne(): void
    {
        $negotiated = MediaType::jsonApi([MediaType::ATOMIC_EXTENSION]);

        $applied = self::builder()->response(
            new ForbiddenException([new ErrorDescription(ErrorCode::Forbidden)]),
            self::request(),
            $negotiated,
        );
        $unnegotiated = self::builder()->response(
            new ForbiddenException([new ErrorDescription(ErrorCode::Forbidden)]),
            self::request(),
            null,
        );

        self::assertSame($negotiated->toHeaderValue(), $applied->headers->line('Content-Type'));
        self::assertSame('application/vnd.api+json', $unnegotiated->headers->line('Content-Type'));
    }

    public function testWritesTheContentTypeAndVaryAndNoOtherFieldOfASuccessfulResponse(): void
    {
        $response = self::builder()->response(
            new ForbiddenException([new ErrorDescription(ErrorCode::Forbidden)]),
            self::request(),
            MediaType::jsonApi(),
        );

        self::assertSame(['content-type', 'vary'], array_keys($response->headers->all()));
        self::assertSame('Accept, Accept-Language', $response->headers->line(FieldName::VARY));
    }

    /**
     * @param array<string, mixed>  $query
     * @param array<string, string> $headers
     */
    #[DataProvider('varyingRequests')]
    public function testVaryNamesTheLanguageFieldWhereNoSourceBeforeItStandsInTheRequest(
        array $query,
        array $headers,
        string $vary,
    ): void {
        $response = self::builder(new LanguageSources([
            LanguageSourceMetadata::queryParameter('uiLocale'),
            LanguageSourceMetadata::acceptLanguage(),
        ]))->response(
            new ForbiddenException([new ErrorDescription(ErrorCode::Forbidden)]),
            self::request($query, $headers),
            MediaType::jsonApi(),
        );

        self::assertSame($vary, $response->headers->line(FieldName::VARY));
    }

    /**
     * @return array<string, array{array<string, mixed>, array<string, string>, string}>
     */
    public static function varyingRequests(): array
    {
        return [
            'the parameter decides' => [['uiLocale' => 'uk'], ['Accept-Language' => 'en'], 'Accept'],
            'the parameter decides, empty as it is' => [['uiLocale' => ''], [], 'Accept'],
            'the field decides' => [[], ['Accept-Language' => 'en'], 'Accept, Accept-Language'],
            'the absent field decides' => [[], [], 'Accept, Accept-Language'],
        ];
    }

    public function testVaryNamesAcceptAloneWhereTheApiNegotiatesNoLanguage(): void
    {
        $response = self::builder(new LanguageSources([]))->response(
            new ForbiddenException([new ErrorDescription(ErrorCode::Forbidden)]),
            self::request([], ['Accept-Language' => 'en']),
            MediaType::jsonApi(),
        );

        self::assertSame('Accept', $response->headers->line(FieldName::VARY));
    }

    public function testWritesOneFieldLinePerChallengeOfA401InTheOrderTheExceptionCarriesThem(): void
    {
        $response = self::builder()->response(
            new UnauthorizedException(
                [new ErrorDescription(ErrorCode::Unauthorized)],
                [
                    new Challenge('Basic', ['realm' => 'WordPress']),
                    new Challenge('Bearer', ['realm' => 'api', 'error' => 'invalid_token'], ['error']),
                ],
            ),
            self::request(),
            MediaType::jsonApi(),
        );

        self::assertSame(
            ['Basic realm="WordPress"', 'Bearer realm="api", error="invalid_token"'],
            $response->headers->values(FieldName::WWW_AUTHENTICATE),
        );
        self::assertSame(['content-type', 'vary', 'www-authenticate'], array_keys($response->headers->all()));
    }

    #[DataProvider('failuresWithoutAChallenge')]
    public function testAFailureWithoutAChallengeWritesNoWwwAuthenticateField(JsonApiException $error): void
    {
        $response = self::builder()->response($error, self::request(), MediaType::jsonApi());

        self::assertFalse($response->headers->has(FieldName::WWW_AUTHENTICATE));
    }

    /**
     * @return iterable<string, array{JsonApiException}>
     */
    public static function failuresWithoutAChallenge(): iterable
    {
        yield 'a denial the policy turned into a 403' => [
            new ForbiddenException([new ErrorDescription(ErrorCode::AuthenticationRequired)]),
        ];
        yield 'a 401 whose provider returned no challenge' => [
            new UnauthorizedException([new ErrorDescription(ErrorCode::Unauthorized)]),
        ];
        yield 'a status without a library class' => [
            new JsonApiException(410, [new ErrorDescription('rest_no_route')]),
        ];
    }

    public function testAMethodNotAllowedWritesTheAllowFieldAsOneCommaSeparatedLine(): void
    {
        $response = self::builder()->response(
            self::methodNotAllowed(),
            self::request(),
            MediaType::jsonApi(),
            null,
            ['GET', 'HEAD', 'PATCH'],
        );

        self::assertSame(405, $response->status);
        self::assertSame(['GET, HEAD, PATCH'], $response->headers->values(FieldName::ALLOW));
    }

    public function testAMethodNotAllowedWithoutMethodsWritesTheEmptyAllowFieldValue(): void
    {
        $response = self::builder()->response(self::methodNotAllowed(), self::request(), MediaType::jsonApi());

        self::assertTrue($response->headers->has(FieldName::ALLOW));
        self::assertSame('', $response->headers->line(FieldName::ALLOW));
    }

    public function testAnUnsupportedMediaTypeWritesTheMediaTypesTheRouteReadsAsAccept(): void
    {
        $response = self::builder()->response(
            new UnsupportedMediaTypeException([new ErrorDescription(ErrorCode::UnsupportedMediaType)]),
            self::request(),
            MediaType::jsonApi(),
            null,
            [],
            [MediaType::jsonApi(), new MediaType('multipart', 'form-data')],
        );

        self::assertSame(415, $response->status);
        self::assertSame(
            ['application/vnd.api+json, multipart/form-data'],
            $response->headers->values(FieldName::ACCEPT),
        );
        self::assertSame(['content-type', 'vary', 'accept'], array_keys($response->headers->all()));
    }

    public function testAnUnsupportedMediaTypeOfARouteThatReadsNothingWritesNoAccept(): void
    {
        $response = self::builder()->response(
            new UnsupportedMediaTypeException([new ErrorDescription(ErrorCode::UnsupportedMediaType)]),
            self::request(),
            MediaType::jsonApi(),
        );

        self::assertFalse($response->headers->has(FieldName::ACCEPT));
    }

    public function testAcceptedMediaTypesOnAnyOtherStatusAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the Accept field is written on a 415 and on no other status, and this'
            . ' response carries 403 (RFC 9110 15.5.16)');

        self::builder()->response(
            new ForbiddenException([new ErrorDescription(ErrorCode::Forbidden)]),
            self::request(),
            MediaType::jsonApi(),
            null,
            [],
            [MediaType::jsonApi()],
        );
    }

    public function testAllowedMethodsOnAnyOtherStatusAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the Allow field is written on a 405 and on no other status');

        self::builder()->response(
            new ForbiddenException([new ErrorDescription(ErrorCode::Forbidden)]),
            self::request(),
            MediaType::jsonApi(),
            null,
            ['GET'],
        );
    }

    public function testAMethodThatIsNoTokenIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is no token');

        self::builder()->response(self::methodNotAllowed(), self::request(), MediaType::jsonApi(), null, ['GET,HEAD']);
    }

    public function testARepeatedMethodIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('stands twice');

        self::builder()->response(
            self::methodNotAllowed(),
            self::request(),
            MediaType::jsonApi(),
            null,
            ['GET', 'GET'],
        );
    }

    /**
     * a fixed field order: one failure always writes the same message
     */
    public function testTheFieldsAreWrittenInOneFixedOrder(): void
    {
        $response = self::builder()->response(
            new UnauthorizedException(
                [new ErrorDescription(ErrorCode::Unauthorized)],
                [new Challenge('Basic', ['realm' => 'WordPress'])],
            ),
            self::request(),
            MediaType::jsonApi(),
        );
        $refused = self::builder()->response(
            self::methodNotAllowed(),
            self::request(),
            MediaType::jsonApi(),
            null,
            ['GET'],
        );

        self::assertSame(['content-type', 'vary', 'www-authenticate'], array_keys($response->headers->all()));
        self::assertSame(['content-type', 'vary', 'allow'], array_keys($refused->headers->all()));
    }

    /**
     * 405 has no exception class of its own
     */
    private static function methodNotAllowed(): JsonApiException
    {
        return JsonApiException::ofStatus(405, [new ErrorDescription(ErrorCode::BadRequest)]);
    }

    private static function builder(?LanguageSources $languageSources = null): ErrorResponseBuilder
    {
        return new ErrorResponseBuilder(
            new ErrorFactory(new LibraryErrorCatalog()),
            new DocumentEncoder(),
            $languageSources ?? Samples::languageSources(),
        );
    }

    /**
     * @param array<string, mixed>  $query
     * @param array<string, string> $headers
     */
    private static function request(array $query = [], array $headers = []): ServerRequest
    {
        return new ServerRequest('GET', new Headers($headers), $query, [], '');
    }
}
