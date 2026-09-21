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

namespace Polunich\WpJsonApi\Tests\Unit;

use ArrayObject;
use Closure;
use DomainException;
use InvalidArgumentException;
use OutOfBoundsException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Access\AllowAny;
use Polunich\WpJsonApi\Access\DenialPolicy;
use Polunich\WpJsonApi\Access\OperationAttempt;
use Polunich\WpJsonApi\Access\OperationPermission;
use Polunich\WpJsonApi\Api;
use Polunich\WpJsonApi\ApiBuilder;
use Polunich\WpJsonApi\Authentication\Challenge;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Codec\DigitsCodec;
use Polunich\WpJsonApi\Codec\StringCodec;
use Polunich\WpJsonApi\Declaration\AtomicRoute as AtomicRouteDeclaration;
use Polunich\WpJsonApi\Declaration\Endpoint;
use Polunich\WpJsonApi\Declaration\Implementation;
use Polunich\WpJsonApi\Declaration\LanguageSource;
use Polunich\WpJsonApi\Declaration\Operator;
use Polunich\WpJsonApi\Declaration\ResourceType;
use Polunich\WpJsonApi\Declaration\ResponseShape;
use Polunich\WpJsonApi\Error\ConflictException;
use Polunich\WpJsonApi\Error\ErrorCatalog;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\ExceptionMapper;
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\Response;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\JsonApi;
use Polunich\WpJsonApi\Localization\ContentLanguage;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Localization\LanguageRange;
use Polunich\WpJsonApi\Metadata\ApiMetadata;
use Polunich\WpJsonApi\Metadata\AtomicRoute;
use Polunich\WpJsonApi\Metadata\EndpointRoute;
use Polunich\WpJsonApi\Metadata\OperationRoute;
use Polunich\WpJsonApi\Metadata\Route;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Query\Filter\Operators;
use Polunich\WpJsonApi\Query\NamingPolicy;
use Polunich\WpJsonApi\Routing\PathParameter;
use Polunich\WpJsonApi\Server\Server;
use Polunich\WpJsonApi\Tests\Fixtures\Api\PetsApi;
use Polunich\WpJsonApi\Tests\Fixtures\Api\RecordingLogger;
use Polunich\WpJsonApi\Tests\Fixtures\Store\PetStore;
use Polunich\WpJsonApi\Tests\Fixtures\Store\StoredPet;
use Polunich\WpJsonApi\WordPress\ApplicationPasswordsChallengeProvider;
use Polunich\WpJsonApi\WordPress\CurrentUserIdentity;
use Polunich\WpJsonApi\WordPress\ErrorLogLogger;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * `Api` keeps its `Server` private; the tests read it by reflection and ask it directly,
 * since no WordPress is loaded to serve a request
 *
 * @internal
 */
#[CoversClass(ApiBuilder::class)]
final class ApiBuilderTest extends TestCase
{
    public function testTheClassIsAPublicFinalBuilderStartedByTheFacade(): void
    {
        $class = new ReflectionClass(ApiBuilder::class);

        self::assertTrue($class->isFinal());
        self::assertFalse($class->isReadOnly());
        self::assertDoesNotMatchRegularExpression('/@internal\b/', (string) $class->getDocComment());
        self::assertMatchesRegularExpression('/@internal\b/', (string) $class->getConstructor()?->getDocComment());
    }

    public function testTheContractDeclaresTheResources(): void
    {
        self::assertSame(['/pets', '/pets/{id}'], array_keys(self::contractMember(self::builder()->build(), 'paths')));
    }

    public function testTheDefaultSchemesAreTheWaysWordPressCoreAuthenticates(): void
    {
        $api = self::builder()->build();

        self::assertSame(['restNonce', 'applicationPasswords'], array_keys(self::securitySchemes($api)));
        self::assertInstanceOf(ApplicationPasswordsChallengeProvider::class, self::denialsOf($api)['challenges']);
    }

    public function testTheSchemesOfTheProjectReplaceTheDefault(): void
    {
        $bearer = new SecurityScheme('bearerAuth', ['type' => 'http', 'scheme' => 'bearer']);
        $both = self::builder()->securitySchemes(SecurityScheme::applicationPasswords(), $bearer)->build();
        $bearerOnly = self::builder()->securitySchemes($bearer)->build();
        $none = self::builder()->securitySchemes()->build();

        self::assertSame(['applicationPasswords', 'bearerAuth'], array_keys(self::securitySchemes($both)));
        self::assertSame(['bearerAuth'], array_keys(self::securitySchemes($bearerOnly)));
        self::assertSame([], self::securitySchemes($none));
    }

    public function testACustomOperatorFollowsTheBuiltInOnesAndOneWrittenTwiceIsRefused(): void
    {
        $metadata = (new ReflectionProperty(Api::class, 'metadata'))
            ->getValue(self::builder()->operator(Operator::make('@near', new StringCodec()))->build());

        self::assertInstanceOf(ApiMetadata::class, $metadata);
        self::assertSame([...Operators::names(), '@near'], array_keys($metadata->operators));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('declares the operator "@near" twice');

        self::builder()
            ->operator(Operator::make('@near', new StringCodec()))
            ->operator(Operator::make('@near', new StringCodec()))
            ->build();
    }

    public function testADeclarationWhoseRoutesCollideIsRefusedWhenTheApiIsBuilt(): void
    {
        $operations = ResourceType::make('operations')
            ->id(new DigitsCodec(), static function (StoredPet $pet): string {
                return $pet->id;
            })
            ->loader(Implementation::service('loader'))
            ->creator(Implementation::service('creator'))
            ->permission(OperationKind::FetchResource, Implementation::service('permission'))
            ->permission(OperationKind::CreateResource, Implementation::service('permission'));

        $builder = JsonApi::api(PetsApi::NAME, PetsApi::VERSION, PetsApi::REST_NAMESPACE)
            ->resource($operations)
            ->transactionBoundary(Implementation::service('boundary'));
        $builder->atomic('/operations')->name('atomicOperations');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the create_resource route of type "operations" on "/operations" and the atomic route'
            . ' "atomicOperations" on "/operations" both respond to POST',
        );

        $builder->build();
    }

    /**
     * @param Closure(ApiBuilder): ApiBuilder $write
     */
    #[DataProvider('partsOfOneValue')]
    public function testAPartOfOneValueIsWrittenOnce(Closure $write, string $part): void
    {
        $builder = $write(self::builder());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('declares ' . $part . ' twice, and each part of a declaration is written once');

        $write($builder);
    }

    /**
     * @return iterable<string, array{Closure(ApiBuilder): ApiBuilder, string}>
     */
    public static function partsOfOneValue(): iterable
    {
        yield 'security schemes' => [static function (ApiBuilder $builder): ApiBuilder {
            return $builder->securitySchemes();
        }, 'its security schemes'];
        yield 'transaction boundary' => [static function (ApiBuilder $builder): ApiBuilder {
            return $builder->transactionBoundary(Implementation::instance(PetsApi::store()));
        }, 'a transaction boundary'];
        yield 'container' => [static function (ApiBuilder $builder): ApiBuilder {
            return $builder->container(self::container([]));
        }, 'a container'];
        yield 'logger' => [static function (ApiBuilder $builder): ApiBuilder {
            return $builder->logger(new RecordingLogger());
        }, 'a logger'];
        yield 'content language' => [static function (ApiBuilder $builder): ApiBuilder {
            return $builder->contentLanguage(self::contentLanguage(new ArrayObject()));
        }, 'a content language'];
        yield 'error catalog' => [static function (ApiBuilder $builder): ApiBuilder {
            return $builder->errorCatalog(self::catalog('x'));
        }, 'an error catalog'];
        yield 'challenge provider' => [static function (ApiBuilder $builder): ApiBuilder {
            return $builder->challengeProvider(PetsApi::challengeProvider([]));
        }, 'a challenge provider'];
        yield 'realm' => [static function (ApiBuilder $builder): ApiBuilder {
            return $builder->realm('My Site');
        }, 'a realm'];
        yield 'identity' => [static function (ApiBuilder $builder): ApiBuilder {
            return $builder->identity(PetsApi::identity(true));
        }, 'an identity'];
        yield 'debug output' => [static function (ApiBuilder $builder): ApiBuilder {
            return $builder->debugOutput(true);
        }, 'its debug output'];
        yield 'language sources' => [static function (ApiBuilder $builder): ApiBuilder {
            return $builder->languageSources(LanguageSource::acceptLanguage());
        }, 'its language sources'];
        yield 'naming policy' => [static function (ApiBuilder $builder): ApiBuilder {
            return $builder->namingPolicy(NamingPolicy::CamelCase);
        }, 'a naming policy'];
        yield 'strict query parameter names' => [static function (ApiBuilder $builder): ApiBuilder {
            return $builder->strictQueryParameterNames(false);
        }, 'its rule of query parameter names'];
    }

    public function testARealmAndAChallengeProviderRefuseEachOther(): void
    {
        foreach ([
            static function (): ApiBuilder {
                return self::builder()->realm('My Site')->challengeProvider(PetsApi::challengeProvider([]));
            },
            static function (): ApiBuilder {
                return self::builder()->challengeProvider(PetsApi::challengeProvider([]))->realm('My Site');
            },
        ] as $write) {
            try {
                $write();
                self::fail('a realm and a challenge provider were both accepted');
            } catch (InvalidArgumentException $refusal) {
                self::assertStringContainsString('declares a realm and a challenge provider', $refusal->getMessage());
            }
        }
    }

    public function testTheRealmReachesTheDefaultChallenge(): void
    {
        $provider = self::denialsOf(self::builder()->realm('My Site')->build())['challenges'];

        self::assertInstanceOf(ApplicationPasswordsChallengeProvider::class, $provider);

        $challenge = (new ReflectionProperty(
            ApplicationPasswordsChallengeProvider::class,
            'challenge',
        ))->getValue($provider);

        self::assertInstanceOf(Challenge::class, $challenge);
        self::assertSame('Basic realm="My Site"', $challenge->toHeaderValue());
    }

    public function testTheLibraryNamesAreInSnakeCaseUnlessTheCamelCasePolicyIsWritten(): void
    {
        $snake = self::builder()->build()->openApiJson();
        $camel = self::builder()->namingPolicy(NamingPolicy::CamelCase)->build()->openApiJson();

        self::assertStringContainsString('"page[with_count]"', $snake);
        self::assertStringNotContainsString('"page[withCount]"', $snake);
        self::assertStringContainsString('"page[withCount]"', $camel);
        self::assertStringNotContainsString('"page[with_count]"', $camel);
    }

    public function testTheDefaultsAreWordPresss(): void
    {
        $api = self::builder()->build();
        $denials = self::denialsOf($api);

        self::assertInstanceOf(CurrentUserIdentity::class, $denials['identity']);
        self::assertInstanceOf(ApplicationPasswordsChallengeProvider::class, $denials['challenges']);
        self::assertInstanceOf(
            ErrorLogLogger::class,
            (new ReflectionProperty(Server::class, 'logger'))->getValue(self::serverOf($api)),
        );
    }

    public function testTheIdentityAndTheChallengeProviderOfTheProjectDecideADenial(): void
    {
        $response = self::refusal(
            self::builder(PetsApi::permission(false))
                ->identity(PetsApi::identity(false))
                ->challengeProvider(PetsApi::challengeProvider([new Challenge('Bearer')]))
                ->build(),
        );

        self::assertSame(401, $response->status);
        self::assertSame(['Bearer'], $response->headers->values('WWW-Authenticate'));
    }

    public function testTheLoggerOfTheProjectIsWrittenTo(): void
    {
        $logger = new RecordingLogger();
        $failure = new RuntimeException('the permission broke');

        $response = self::refusal(self::builder(self::throwing(
            $failure,
        ))->identity(PetsApi::identity(true))->logger($logger)->build());

        self::assertSame(500, $response->status);
        self::assertSame([$failure], $logger->throwables());
    }

    public function testDebugOutputPutsTheDetailsOfAFiveHundredInItsMeta(): void
    {
        $failure = new RuntimeException('the permission broke');
        $quiet = self::refusal(self::builder(self::throwing(
            $failure,
        ))->identity(PetsApi::identity(true))->logger(new RecordingLogger())->build());
        $debug = self::refusal(self::builder(self::throwing(
            $failure,
        ))->identity(PetsApi::identity(true))->logger(new RecordingLogger())->debugOutput(true)->build());

        $meta = self::firstError($debug)['meta'] ?? null;

        self::assertArrayNotHasKey('meta', self::firstError($quiet));
        self::assertInstanceOf(stdClass::class, $meta);
        self::assertObjectHasProperty('exceptions', $meta);
    }

    public function testTheErrorCatalogOfTheProjectWordsTheTitle(): void
    {
        $response = self::refusal(
            self::builder(PetsApi::permission(
                false,
            ))->identity(PetsApi::identity(true))->errorCatalog(self::catalog('not for you'))->build(),
        );

        self::assertSame(403, $response->status);
        self::assertSame('not for you', self::firstError($response)['title'] ?? null);
    }

    public function testAnExceptionMapperOfTheProjectMapsItsThrowable(): void
    {
        $mapper = new readonly class implements ExceptionMapper {
            #[Override]
            public function exceptionClass(): string
            {
                return DomainException::class;
            }

            #[Override]
            public function map(Throwable $exception): JsonApiException
            {
                return new ConflictException([new ErrorDescription(ErrorCode::Conflict)]);
            }
        };

        $response = self::refusal(
            self::builder(self::throwing(new DomainException(
                'taken',
            )))->identity(PetsApi::identity(true))->exceptionMapper($mapper)->build(),
        );

        self::assertSame(409, $response->status);
    }

    public function testTheContentLanguageOfTheProjectIsAsked(): void
    {
        $asked = new ArrayObject();
        $api = self::builder()->contentLanguage(self::contentLanguage($asked))->build();

        $response = self::serverOf($api)->handle(self::deleteRoute(), self::deleteRequest());

        self::assertSame(204, $response->status);
        self::assertCount(1, $asked);
    }

    public function testWithoutDeclaredSourcesTheLanguageComesFromAcceptLanguageAlone(): void
    {
        $asked = self::asked();
        $api = self::builder()->contentLanguage(self::contentLanguage($asked))->build();

        $response = self::serverOf($api)->handle(self::deleteRoute(), self::deleteRequest(['uiLocale' => 'de'], 'uk'));

        self::assertSame(400, $response->status);
        self::assertSame('Accept, Accept-Language', $response->headers->line('Vary'));

        $response = self::serverOf($api)->handle(self::deleteRoute(), self::deleteRequest([], 'uk'));

        self::assertSame(204, $response->status);
        self::assertSame(['uk'], self::ranges($asked[0]));
    }

    public function testTheFirstDeclaredSourceThatTheRequestCarriesDecides(): void
    {
        $asked = self::asked();
        $build = static function () use ($asked): Api {
            return self::builder()
                ->languageSources(LanguageSource::queryParameter('uiLocale'), LanguageSource::acceptLanguage())
                ->contentLanguage(self::contentLanguage($asked))
                ->build();
        };
        $api = $build();

        // each request deletes pet 1 of its own store
        $parameter = self::serverOf($api)->handle(self::deleteRoute(), self::deleteRequest(['uiLocale' => 'de'], 'uk'));
        $field = self::serverOf($build())->handle(self::deleteRoute(), self::deleteRequest([], 'uk'));

        self::assertSame(204, $parameter->status);
        self::assertSame('Accept', $parameter->headers->line('Vary'));
        self::assertSame(204, $field->status);
        self::assertSame('Accept, Accept-Language', $field->headers->line('Vary'));
        self::assertSame(['de'], self::ranges($asked[0]));
        self::assertSame(['uk'], self::ranges($asked[1]));
        self::assertSame(
            ['uiLocale.query', 'Accept-Language.header'],
            array_keys(self::arrayMember(self::contractMember($api, 'components'), 'parameters')),
        );
    }

    public function testAnApiWithoutSourcesNegotiatesNoLanguage(): void
    {
        $asked = self::asked();
        $api = self::builder()->languageSources()->contentLanguage(self::contentLanguage($asked))->build();

        $response = self::serverOf($api)->handle(self::deleteRoute(), self::deleteRequest([], 'uk'));

        self::assertSame(204, $response->status);
        self::assertSame('Accept', $response->headers->line('Vary'));
        self::assertSame([], self::ranges($asked[0]));
        self::assertArrayNotHasKey('parameters', self::contractMember($api, 'components'));
    }

    public function testALanguageParameterOfAToZAloneIsRefusedWhenTheApiIsBuilt(): void
    {
        $builder = self::builder()->languageSources(LanguageSource::queryParameter('locale'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the language parameter "locale" holds a-z alone');

        $builder->build();
    }

    public function testWithoutTheStrictRuleALanguageParameterOfAToZAloneDecidesTheLanguage(): void
    {
        $asked = self::asked();
        $api = self::builder()
            ->strictQueryParameterNames(false)
            ->languageSources(LanguageSource::queryParameter('locale'), LanguageSource::acceptLanguage())
            ->contentLanguage(self::contentLanguage($asked))
            ->build();

        $response = self::serverOf($api)->handle(self::deleteRoute(), self::deleteRequest(['locale' => 'de'], 'uk'));

        self::assertSame(204, $response->status);
        self::assertSame(['de'], self::ranges($asked[0]));
    }

    public function testASourceNamedTwiceIsRefusedWhereItIsWritten(): void
    {
        $builder = self::builder();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the language source "uiLocale" is declared twice');

        $builder->languageSources(
            LanguageSource::queryParameter('uiLocale'),
            LanguageSource::queryParameter('uiLocale'),
        );
    }

    public function testAnImplementationOfTheContainerComesFromIt(): void
    {
        $api = self::builder(null, Implementation::service('deletion'))
            ->container(self::container(['deletion' => PetsApi::permission(false)]))
            ->identity(PetsApi::identity(true))
            ->build();

        self::assertSame(403, self::refusal($api)->status);
    }

    /**
     * the atomic route comes last in the table
     */
    public function testAnAtomicRouteIsDeclaredOnTheApiAndReadWhenItIsBuilt(): void
    {
        $plain = self::serverOf(self::builder()->build())->routes();
        $builder = self::builder()->transactionBoundary(Implementation::instance(PetsApi::store()));
        $route = $builder->atomic('/pets/operations');

        self::assertInstanceOf(AtomicRouteDeclaration::class, $route);

        $route->name('petOperations')->types('pets')->tag('atomic')->description('the operations on pets');
        $atomic = self::serverOf($builder->build())->routes();

        $lastPlain = end($plain);
        $lastAtomic = end($atomic);

        self::assertInstanceOf(OperationRoute::class, $lastPlain);
        self::assertInstanceOf(AtomicRoute::class, $lastAtomic);
        self::assertSame('/pets/operations', $lastAtomic->template->path);
        self::assertSame('petOperations', $lastAtomic->metadata->name);
        self::assertSame(['pets'], $lastAtomic->metadata->types);
        self::assertSame('atomic', $lastAtomic->metadata->tag);
        self::assertSame('the operations on pets', $lastAtomic->metadata->description);
    }

    public function testAnAtomicRouteWithoutTypesTakesEveryTypeOfTheApi(): void
    {
        $builder = self::builder()
            ->resource(self::tags())
            ->transactionBoundary(Implementation::instance(PetsApi::store()));
        $builder->atomic('/operations')->name('atomicOperations');

        $routes = self::serverOf($builder->build())->routes();
        $last = end($routes);

        self::assertInstanceOf(AtomicRoute::class, $last);
        self::assertSame(['pets', 'tags'], $last->metadata->types);
    }

    public function testAtomicRoutesAreListedInTheOrderOfTheirDeclaration(): void
    {
        $builder = self::builder()->transactionBoundary(Implementation::instance(PetsApi::store()));
        $builder->atomic('/operations')->name('atomicOperations');
        $builder->atomic('/pets/operations')->name('petOperations')->types('pets');

        $routes = array_slice(self::serverOf($builder->build())->routes(), -2);

        self::assertSame(['/operations', '/pets/operations'], array_map(static function (Route $route): string {
            return $route->template->path;
        }, $routes));
    }

    public function testAnAtomicRouteWithoutATransactionBoundaryIsRefusedWhenTheApiIsBuilt(): void
    {
        $builder = self::builder();
        $builder->atomic('/operations')->name('atomicOperations');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the atomic route "atomicOperations" runs its operations in one'
            . ' transaction, and the API declares no transaction boundary to provide it');

        $builder->build();
    }

    /**
     * @param 'get'|'post'|'put'|'patch'|'delete' $method
     */
    #[DataProvider('endpointMethods')]
    public function testAnEndpointIsDeclaredOnTheApiAndReadWhenItIsBuilt(string $method, string $served): void
    {
        $builder = self::builder();
        $endpoint = $builder->{$method}('/stats', Implementation::service('stats'));

        self::assertInstanceOf(Endpoint::class, $endpoint);

        $endpoint->name('stats');
        $endpoint->responds(ResponseShape::noContent())->permission(Implementation::instance(new AllowAny()));

        $routes = self::serverOf($builder->build())->routes();
        $route = end($routes);

        self::assertInstanceOf(EndpointRoute::class, $route);
        self::assertSame('stats', $route->endpoint->name);
        self::assertSame($served, $route->methods[0]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function endpointMethods(): iterable
    {
        yield 'get' => ['get', 'GET'];
        yield 'post' => ['post', 'POST'];
        yield 'put' => ['put', 'PUT'];
        yield 'patch' => ['patch', 'PATCH'];
        yield 'delete' => ['delete', 'DELETE'];
    }

    public function testAnEndpointMayNameATypeDeclaredAfterIt(): void
    {
        $builder = JsonApi::api(PetsApi::NAME, PetsApi::VERSION, PetsApi::REST_NAMESPACE);
        $builder->get('/pets/{id}/card', Implementation::service('card'))
            ->name('petCard')
            ->bind('id', 'pets')
            ->responds(ResponseShape::record('id'))
            ->permission(Implementation::instance(new AllowAny()));
        $builder->resource(PetsApi::pets(PetsApi::store(), Implementation::instance(PetsApi::permission(true))));

        self::assertStringContainsString('"operationId": "petCard"', $builder->build()->openApiJson());
    }

    public function testAnEndpointTheBuildRefusesFailsTheBuild(): void
    {
        $builder = self::builder();
        $builder->get(
            '/stats',
            Implementation::service('stats'),
        )->responds(ResponseShape::noContent())->permission(Implementation::instance(new AllowAny()));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('the endpoint GET /stats declares no name');

        $builder->build();
    }

    /**
     * `ApiMetadata` refuses only an empty name, so a message escapes it
     */
    public function testAPartWrittenTwiceNamesTheApiEscaped(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'the API "pets\\napi" declares a naming policy twice, and each part of a declaration is written once',
        );

        JsonApi::api("pets\napi", PetsApi::VERSION, PetsApi::REST_NAMESPACE)
            ->namingPolicy(NamingPolicy::CamelCase)
            ->namingPolicy(NamingPolicy::SnakeCase);
    }

    private static function builder(
        ?OperationPermission $deletion = null,
        ?Implementation $deletionImplementation = null,
    ): ApiBuilder {
        return JsonApi::api(PetsApi::NAME, PetsApi::VERSION, PetsApi::REST_NAMESPACE)
            ->resource(PetsApi::pets(
                PetsApi::store(),
                $deletionImplementation ?? Implementation::instance($deletion ?? PetsApi::permission(true)),
            ));
    }

    /**
     * a second type, which reads its resources alone
     */
    private static function tags(): ResourceType
    {
        return ResourceType::make('tags')
            ->id(new DigitsCodec(), static function (StoredPet $pet): string {
                return $pet->id;
            })
            ->loader(Implementation::service('loader'))
            ->permission(OperationKind::FetchResource, Implementation::service('permission'));
    }

    private static function serverOf(Api $api): Server
    {
        $server = (new ReflectionProperty(Api::class, 'server'))->getValue($api);

        self::assertInstanceOf(Server::class, $server);

        return $server;
    }

    /**
     * @return array{identity: mixed, challenges: mixed}
     */
    private static function denialsOf(Api $api): array
    {
        $denials = (new ReflectionProperty(Server::class, 'denials'))->getValue(self::serverOf($api));

        self::assertInstanceOf(DenialPolicy::class, $denials);

        return [
            'identity' => (new ReflectionProperty(DenialPolicy::class, 'identity'))->getValue($denials),
            'challenges' => (new ReflectionProperty(DenialPolicy::class, 'challenges'))->getValue($denials),
        ];
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function securitySchemes(Api $api): array
    {
        $components = self::contractMember($api, 'components');
        $schemes = $components['securitySchemes'] ?? [];

        self::assertIsArray($schemes);

        return $schemes;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function contractMember(Api $api, string $member): array
    {
        $contract = json_decode($api->openApiJson(), true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($contract);

        $value = $contract[$member] ?? null;

        self::assertIsArray($value);

        return $value;
    }

    /**
     * the refusal `authorize()` returns for deleting pet 1
     */
    private static function refusal(Api $api): Response
    {
        $response = self::serverOf($api)->authorize(self::deleteRoute(), self::deleteRequest());

        self::assertNotNull($response);

        return $response;
    }

    private static function deleteRoute(): Route
    {
        return new OperationRoute(
            OperationKind::DeleteResource,
            PetStore::TYPE,
            null,
            new PathParameter('id', new DigitsCodec()),
            [PetStore::TYPE],
        );
    }

    /**
     * @param array<string, string> $query
     */
    private static function deleteRequest(array $query = [], ?string $acceptLanguage = null): ServerRequest
    {
        return new ServerRequest(
            'DELETE',
            new Headers($acceptLanguage === null ? [] : ['Accept-Language' => $acceptLanguage]),
            $query,
            ['id' => '1'],
            '',
        );
    }

    /**
     * the preferences a content language port is asked with, in order
     *
     * @return ArrayObject<int, LanguagePreferences>
     */
    private static function asked(): ArrayObject
    {
        return new ArrayObject();
    }

    /**
     * @return list<string>
     */
    private static function ranges(mixed $preferences): array
    {
        self::assertInstanceOf(LanguagePreferences::class, $preferences);

        return array_map(static function (LanguageRange $range): string {
            return $range->range;
        }, $preferences->ranges());
    }

    /**
     * @param array<array-key, mixed> $holder
     *
     * @return array<array-key, mixed>
     */
    private static function arrayMember(array $holder, string $member): array
    {
        $value = $holder[$member] ?? null;

        self::assertIsArray($value);

        return $value;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function firstError(Response $response): array
    {
        $errors = $response->document['errors'] ?? null;

        self::assertIsArray($errors);
        self::assertIsArray($errors[0] ?? null);

        return $errors[0];
    }

    private static function throwing(Throwable $failure): OperationPermission
    {
        return new readonly class ($failure) implements OperationPermission {
            public function __construct(private Throwable $failure) {}

            #[Override]
            public function allows(OperationAttempt $attempt): bool
            {
                throw $this->failure;
            }
        };
    }

    private static function catalog(string $title): ErrorCatalog
    {
        return new readonly class ($title) implements ErrorCatalog {
            public function __construct(private string $title) {}

            #[Override]
            public function title(string $code): string
            {
                return $this->title;
            }

            #[Override]
            public function detail(string $code, array $parameters): ?string
            {
                return null;
            }
        };
    }

    /**
     * @param ArrayObject<int, LanguagePreferences> $asked
     */
    private static function contentLanguage(ArrayObject $asked): ContentLanguage
    {
        return new readonly class ($asked) implements ContentLanguage {
            /**
             * @param ArrayObject<int, LanguagePreferences> $asked
             */
            public function __construct(private ArrayObject $asked) {}

            #[Override]
            public function negotiate(LanguagePreferences $preferences): ?string
            {
                $this->asked->append($preferences);

                return null;
            }
        };
    }

    /**
     * @param array<string, object> $entries
     */
    private static function container(array $entries): ContainerInterface
    {
        return new readonly class ($entries) implements ContainerInterface {
            /**
             * @param array<string, object> $entries
             */
            public function __construct(private array $entries) {}

            #[Override]
            public function get(string $id): mixed
            {
                return $this->entries[$id] ?? throw new OutOfBoundsException($id);
            }

            #[Override]
            public function has(string $id): bool
            {
                return array_key_exists($id, $this->entries);
            }
        };
    }
}
