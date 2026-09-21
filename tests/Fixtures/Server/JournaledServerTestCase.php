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

namespace Polunich\WpJsonApi\Tests\Fixtures\Server;

use ArrayObject;
use Closure;
use DomainException;
use LogicException;
use Override;
use PHPUnit\Framework\TestCase;
use Polunich\WpJsonApi\Access\DenialPolicy;
use Polunich\WpJsonApi\Access\EndpointAttempt;
use Polunich\WpJsonApi\Access\EndpointPermission;
use Polunich\WpJsonApi\Access\Gate;
use Polunich\WpJsonApi\Access\OperationAttempt;
use Polunich\WpJsonApi\Access\OperationPermission;
use Polunich\WpJsonApi\Authentication\Challenge;
use Polunich\WpJsonApi\Authentication\ChallengeProvider;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Codec\DigitsCodec;
use Polunich\WpJsonApi\Codec\UuidCodec;
use Polunich\WpJsonApi\Document\DocumentEncoder;
use Polunich\WpJsonApi\Endpoint\Handler;
use Polunich\WpJsonApi\Endpoint\Request;
use Polunich\WpJsonApi\Endpoint\Response as EndpointResponse;
use Polunich\WpJsonApi\Error\ConflictException;
use Polunich\WpJsonApi\Error\DefaultExceptionMapper;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\ErrorFactory;
use Polunich\WpJsonApi\Error\ExceptionMapper;
use Polunich\WpJsonApi\Error\ExceptionMappers;
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Error\LibraryErrorCatalog;
use Polunich\WpJsonApi\Error\StatusSelector;
use Polunich\WpJsonApi\Execution\Atomic\AtomicExecutor;
use Polunich\WpJsonApi\Execution\EndpointExecutor;
use Polunich\WpJsonApi\Execution\ExecutionContext;
use Polunich\WpJsonApi\Execution\Executor;
use Polunich\WpJsonApi\Execution\IncludeLoader;
use Polunich\WpJsonApi\Execution\Paginator;
use Polunich\WpJsonApi\Execution\Preconditions;
use Polunich\WpJsonApi\Execution\Processor\OperationProcessor;
use Polunich\WpJsonApi\Execution\Result\CollectionResult;
use Polunich\WpJsonApi\Execution\Result\NoContentResult;
use Polunich\WpJsonApi\Execution\Result\Page;
use Polunich\WpJsonApi\Execution\Result\RecordGraph;
use Polunich\WpJsonApi\Execution\Result\RecordLinkage;
use Polunich\WpJsonApi\Execution\Result\RelationshipResult;
use Polunich\WpJsonApi\Execution\Result\ResourceRecord;
use Polunich\WpJsonApi\Execution\Result\ResourceResult;
use Polunich\WpJsonApi\Execution\Result\Result;
use Polunich\WpJsonApi\Execution\StoreGateway;
use Polunich\WpJsonApi\Http\Content;
use Polunich\WpJsonApi\Http\Headers;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\Response;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Localization\AcceptLanguageParser;
use Polunich\WpJsonApi\Localization\ContentLanguage;
use Polunich\WpJsonApi\Localization\LanguagePreferences;
use Polunich\WpJsonApi\Metadata\ApiMetadata;
use Polunich\WpJsonApi\Metadata\AtomicRoute;
use Polunich\WpJsonApi\Metadata\Capability;
use Polunich\WpJsonApi\Metadata\Endpoint\MediaResponseMetadata;
use Polunich\WpJsonApi\Metadata\EndpointMetadata;
use Polunich\WpJsonApi\Metadata\GeneratedRoute;
use Polunich\WpJsonApi\Metadata\IdMetadata;
use Polunich\WpJsonApi\Metadata\LanguageSourceMetadata;
use Polunich\WpJsonApi\Metadata\LanguageSources;
use Polunich\WpJsonApi\Metadata\OperationRoute;
use Polunich\WpJsonApi\Metadata\RelationshipUpdate;
use Polunich\WpJsonApi\Metadata\ResourceMetadata;
use Polunich\WpJsonApi\Metadata\ResourceRegistry;
use Polunich\WpJsonApi\Metadata\Route;
use Polunich\WpJsonApi\Metadata\RouteExclusion;
use Polunich\WpJsonApi\Metadata\VersionPolicy;
use Polunich\WpJsonApi\OpenApi\OpenApiBuilder;
use Polunich\WpJsonApi\Operation\Operation;
use Polunich\WpJsonApi\Operation\OperationKind;
use Polunich\WpJsonApi\Parsing\AtomicParser;
use Polunich\WpJsonApi\Parsing\CollectionReadingParser;
use Polunich\WpJsonApi\Parsing\CursorConditions;
use Polunich\WpJsonApi\Parsing\DocumentParser;
use Polunich\WpJsonApi\Parsing\EndpointParser;
use Polunich\WpJsonApi\Parsing\FieldsetParser;
use Polunich\WpJsonApi\Parsing\FilterParser;
use Polunich\WpJsonApi\Parsing\IncludeParser;
use Polunich\WpJsonApi\Parsing\JsonBodyDecoder;
use Polunich\WpJsonApi\Parsing\LanguageReader;
use Polunich\WpJsonApi\Parsing\Negotiator;
use Polunich\WpJsonApi\Parsing\OperationParser;
use Polunich\WpJsonApi\Parsing\PageParser;
use Polunich\WpJsonApi\Parsing\QueryParameterGuard;
use Polunich\WpJsonApi\Parsing\SortParser;
use Polunich\WpJsonApi\Query\Fieldset\SparseFieldsets;
use Polunich\WpJsonApi\Query\NamingPolicy;
use Polunich\WpJsonApi\Query\Pagination\Cursor;
use Polunich\WpJsonApi\Query\Pagination\CursorText;
use Polunich\WpJsonApi\Query\Pagination\Strategy;
use Polunich\WpJsonApi\Rendering\DocumentBuilder;
use Polunich\WpJsonApi\Rendering\ErrorResponseBuilder;
use Polunich\WpJsonApi\Rendering\LinkQuery;
use Polunich\WpJsonApi\Rendering\PaginationWriter;
use Polunich\WpJsonApi\Rendering\ResourceSerializer;
use Polunich\WpJsonApi\Rendering\ResponseBuilder;
use Polunich\WpJsonApi\Rendering\RouteUrls;
use Polunich\WpJsonApi\Routing\PathParameter;
use Polunich\WpJsonApi\Server\AtomicPipeline;
use Polunich\WpJsonApi\Server\EndpointPipeline;
use Polunich\WpJsonApi\Server\OperationPipeline;
use Polunich\WpJsonApi\Server\Server;
use Polunich\WpJsonApi\Store\LoadRequest;
use Polunich\WpJsonApi\Store\ResourceLoader;
use Polunich\WpJsonApi\Store\TransactionBoundary;
use Polunich\WpJsonApi\Tests\Fixtures\Authentication\FixedChallenges;
use Polunich\WpJsonApi\Tests\Fixtures\Authentication\FixedIdentity;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\BoundImplementations;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Pet;
use Polunich\WpJsonApi\Tests\Fixtures\Metadata\Samples;
use Polunich\WpJsonApi\Tests\Fixtures\Routing\PlainUrls;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use ReflectionParameter;
use stdClass;
use Stringable;
use Throwable;

/**
 * the collaborators of the server are final classes,
 * so the tests compose the real ones and double only the ports beneath them:
 * the permissions, the identity, the challenges, the resource loader, the transaction boundary,
 * the content language, the URL generator, the processors, the endpoint handler and the logger.
 *
 * two types are declared.
 * `pets` has the digits id codec, which keeps the id as the text the client wrote;
 * it binds every store port,
 * its to-many `related` declares every update and its to-one `owner` none.
 * `uuids` has a UUID id codec, which refuses what is no UUID and lower-cases what is one.
 *
 * every double writes into one journal, one entry per call,
 * so a test reads the order of the whole request:
 * `language` is the content language, `level1` and `level2` the two permission levels,
 * `endpointLevel1` and `endpointLevel2` those of an endpoint, `load` the resource loader,
 * `process` a processor, `handle` the endpoint handler,
 * `begin`, `commit` and `rollback` the transaction, and `log` the logger
 *
 * @internal
 */
abstract class JournaledServerTestCase extends TestCase
{
    protected const TYPE = 'pets';

    protected const UUIDS = 'uuids';

    protected const RELATED = 'related';

    protected const OWNER = 'owner';

    protected const ID = '1';

    /** the id parameter of every type here, the one a type gets without naming its own */
    protected const ID_PARAMETER = 'id';

    /** as the codec decodes it */
    protected const UUID = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

    /** as a client may write it; no store ever sees this spelling */
    protected const WRITTEN_UUID = 'AAAAAAAA-BBBB-4CCC-8DDD-EEEEEEEEEEEE';

    protected const JSON_API = 'application/vnd.api+json';

    protected const ATOMIC = 'application/vnd.api+json;ext="https://jsonapi.org/ext/atomic"';

    protected const CURSOR_ACCEPT = 'application/vnd.api+json;'
        . 'profile="https://jsonapi.org/profiles/ethanresnick/cursor-pagination"';

    /**
     * the profile may be asked for on the atomic route too:
     * the extensions are the route's, the profiles the client's
     */
    protected const ATOMIC_CURSOR_ACCEPT = 'application/vnd.api+json;ext="https://jsonapi.org/ext/atomic";'
        . 'profile="https://jsonapi.org/profiles/ethanresnick/cursor-pagination"';

    /**
     * @param ArrayObject<int, array{string, mixed}> $journal
     * @param array<value-of<OperationKind>, Closure(Operation, ExecutionContext): Result> $results
     *        override the default result of a kind's processor
     * @param ?array<array-key, object>              $records         null for `records()`
     * @param ?Closure(OperationAttempt): bool       $permits         null grants at both levels
     * @param list<Challenge>                        $challenges
     *        of a provider that ignores the schemes
     * @param ?ChallengeProvider                     $challengeProvider in place of that provider
     * @param array<value-of<OperationKind>, list<SecurityScheme>> $securitySchemes
     *        those the resource routes of the pets declare, by kind
     * @param array<string, Throwable>               $failing
     *        what each call of the transaction boundary throws, by call name
     * @param list<ExceptionMapper<Throwable>>       $mappers
     * @param list<EndpointMetadata>                 $endpoints
     * @param ?Closure(EndpointAttempt): bool        $endpointPermits
     *        null grants the endpoints at both levels
     * @param ?Closure(Request): EndpointResponse              $handles
     *        null returns the record bound to the id
     */
    protected static function server(
        ArrayObject $journal,
        array $results = [],
        ?array $records = null,
        ?Closure $permits = null,
        bool $authenticated = true,
        array $challenges = [],
        ?string $language = null,
        bool $resolvesLanguage = true,
        ?VersionPolicy $versions = null,
        bool $deletable = true,
        bool $boundaryDeclared = true,
        array $failing = [],
        array $mappers = [],
        ?RouteExclusion $excluded = null,
        ?RouteExclusion $ownerExcluded = null,
        array $endpoints = [],
        ?Closure $endpointPermits = null,
        ?Closure $handles = null,
        ?LanguageSources $languageSources = null,
        ?ChallengeProvider $challengeProvider = null,
        array $securitySchemes = [],
    ): Server {
        $resources = self::registry($versions, $deletable, $excluded, $ownerExcluded, $securitySchemes);
        $api = self::api($resources, $boundaryDeclared, $endpoints, $languageSources);
        $resolver = BoundImplementations::of([
            OperationPermission::class => self::permission($journal, $permits),
            ResourceLoader::class => self::loader($journal, $records ?? self::records()),
            TransactionBoundary::class => self::transactionBoundary($journal, $failing),
            EndpointPermission::class => self::endpointPermission($journal, $endpointPermits),
            Handler::class => self::handler($journal, $handles),
        ]);
        $denials = new DenialPolicy(
            new FixedIdentity($authenticated),
            $challengeProvider ?? new FixedChallenges($challenges),
        );
        $gate = new Gate($resources, $resolver, $denials);
        $preconditions = new Preconditions($resources);
        $executor = new Executor(
            $resources,
            new StoreGateway($resources, $resolver),
            $gate,
            self::processors($journal, $results, $preconditions),
        );
        $urls = new RouteUrls($api->routes, PlainUrls::httpQuery());
        $paginationWriter = new PaginationWriter($urls, NamingPolicy::SnakeCase);
        $languageReader = new LanguageReader($api->languageSources, new AcceptLanguageParser());
        $contentLanguage = $resolvesLanguage ? self::contentLanguage($journal, $language) : null;
        $responses = new ResponseBuilder(
            new DocumentBuilder(
                new ResourceSerializer($resources, $api->routes, $urls, $paginationWriter),
                $paginationWriter,
                $urls,
                $api->routes,
                new LinkQuery($resources, $api->languageSources),
            ),
            new DocumentEncoder(),
            $api->languageSources,
        );
        $store = new StoreGateway($resources, $resolver);

        return new Server(
            $api,
            $gate,
            new OperationPipeline(
                new Negotiator(),
                self::parser($resources, $api),
                $languageReader,
                $contentLanguage,
                $preconditions,
                $executor,
                $responses,
            ),
            new AtomicPipeline(
                new Negotiator(),
                self::atomicParser($resources, $api),
                $languageReader,
                $contentLanguage,
                $preconditions,
                new AtomicExecutor($api, $resolver, $gate, $preconditions, $executor),
                $responses,
            ),
            new EndpointPipeline(
                self::endpointParser($resources, $api),
                $languageReader,
                $contentLanguage,
                $preconditions,
                new EndpointExecutor(
                    $resources,
                    $store,
                    $gate,
                    $resolver,
                    new IncludeLoader($resources, $store, new Paginator($resources, $store)),
                    $preconditions,
                    new Paginator($resources, $store),
                ),
                $responses,
            ),
            new ErrorResponseBuilder(
                new ErrorFactory(new LibraryErrorCatalog()),
                new DocumentEncoder(),
                $api->languageSources,
            ),
            new ExceptionMappers($mappers, new DefaultExceptionMapper()),
            $denials,
            new OpenApiBuilder($api),
            self::logger($journal),
        );
    }

    protected static function endpointParser(ResourceRegistry $resources, ApiMetadata $api): EndpointParser
    {
        return new EndpointParser(
            $resources,
            new Negotiator(),
            new QueryParameterGuard($api->languageSources->queryParameters()),
            new IncludeParser($resources),
            new FieldsetParser($resources),
            self::collectionReadingParser($resources, $api),
            new JsonBodyDecoder(),
            new DocumentParser($resources),
            new StatusSelector(),
        );
    }

    /**
     * @param list<EndpointMetadata> $endpoints
     */
    protected static function api(
        ResourceRegistry $resources,
        bool $boundaryDeclared,
        array $endpoints = [],
        ?LanguageSources $languageSources = null,
    ): ApiMetadata {
        $boundary = $boundaryDeclared ? Samples::implementation() : null;

        return new ApiMetadata(
            'the test api',
            '1.0',
            'acme/v1',
            $resources,
            $endpoints,
            Samples::atomicRoutes($resources, $boundary),
            Samples::operators(),
            [],
            $boundary,
            $languageSources ?? Samples::languageSources(),
            NamingPolicy::SnakeCase,
            true,
        );
    }

    /**
     * `$excluded` names the excluded routes of the pets,
     * `$ownerExcluded` the excluded reads of their owner
     *
     * @param array<value-of<OperationKind>, list<SecurityScheme>> $securitySchemes
     */
    protected static function registry(
        ?VersionPolicy $versions = null,
        bool $deletable = true,
        ?RouteExclusion $excluded = null,
        ?RouteExclusion $ownerExcluded = null,
        array $securitySchemes = [],
    ): ResourceRegistry {
        $capabilities = [
            Capability::CollectionReader,
            Capability::RelatedLoader,
            Capability::ResourceCreator,
            Capability::ResourceUpdater,
            Capability::RelationshipReplacer,
            Capability::RelationshipAdder,
            Capability::RelationshipRemover,
        ];

        if ($deletable) {
            $capabilities[] = Capability::ResourceDeleter;
        }

        $pets = Samples::resource(
            self::TYPE,
            [Samples::attribute('name'), Samples::attribute('description', readable: false)],
            [
                Samples::toMany(self::RELATED, self::TYPE, updates: [
                    RelationshipUpdate::Replace,
                    RelationshipUpdate::Add,
                    RelationshipUpdate::Remove,
                ]),
                Samples::toOne(self::OWNER, self::TYPE, excluded: $ownerExcluded),
            ],
            $capabilities,
            excluded: $excluded,
            securitySchemes: $securitySchemes,
        );
        $uuids = Samples::resource(
            self::UUIDS,
            [Samples::attribute('name'), Samples::attribute('description', readable: false)],
            [],
            [Capability::ResourceUpdater],
            id: new IdMetadata(new UuidCodec(), static function (Pet $pet): string {
                return $pet->id;
            }, false),
        );

        return new ResourceRegistry([self::withVersions($pets, $versions), $uuids]);
    }

    protected static function withVersions(ResourceMetadata $resource, ?VersionPolicy $versions): ResourceMetadata
    {
        return new ResourceMetadata(
            $resource->type,
            $resource->id,
            $resource->idParameterName,
            $resource->attributes,
            $resource->relationships,
            $resource->capabilities,
            $resource->permissions,
            $resource->monitorTypes,
            $resource->excluded,
            $resource->clientIdPolicy,
            $versions,
            $resource->defaultSort,
            $resource->paginationPolicy,
            $resource->maxIncludeDepth,
            $resource->description,
            securitySchemes: $resource->securitySchemes,
            path: $resource->path,
        );
    }

    protected static function versions(bool $preconditionsRequired): VersionPolicy
    {
        return new VersionPolicy(static function (Pet $pet): string {
            return $pet->version;
        }, $preconditionsRequired);
    }

    protected static function parser(ResourceRegistry $resources, ApiMetadata $api): OperationParser
    {
        return new OperationParser(
            $resources,
            new QueryParameterGuard($api->languageSources->queryParameters()),
            self::collectionReadingParser($resources, $api),
            new PageParser(NamingPolicy::SnakeCase),
            new IncludeParser($resources),
            new FieldsetParser($resources),
            new JsonBodyDecoder(),
            new DocumentParser($resources),
            new StatusSelector(),
        );
    }

    protected static function collectionReadingParser(
        ResourceRegistry $resources,
        ApiMetadata $api,
    ): CollectionReadingParser {
        return new CollectionReadingParser(
            new FilterParser($api),
            new SortParser($resources),
            new PageParser(NamingPolicy::SnakeCase),
            new CursorConditions($resources, NamingPolicy::SnakeCase),
        );
    }

    protected static function atomicParser(ResourceRegistry $resources, ApiMetadata $api): AtomicParser
    {
        return new AtomicParser(
            $resources,
            new QueryParameterGuard($api->languageSources->queryParameters()),
            new FieldsetParser($resources),
            new JsonBodyDecoder(),
            new DocumentParser($resources),
            new StatusSelector(),
        );
    }

    /**
     * the route of the kind, or for none the atomic route that takes the type
     */
    protected static function route(
        ?OperationKind $kind,
        ?string $relationship = null,
        string $type = self::TYPE,
    ): GeneratedRoute {
        if ($kind === null) {
            return new AtomicRoute(Samples::atomicRoute([$type]));
        }

        return new OperationRoute(
            $kind,
            $type,
            $relationship,
            $kind->route()->addressesId() ? new PathParameter(self::ID_PARAMETER, new DigitsCodec()) : null,
            [$type],
        );
    }

    /**
     * `GET /pets/{id}/photo` responding with the pet as JSON:API or as a PNG image
     */
    protected static function photoEndpoint(): EndpointMetadata
    {
        return Samples::endpoint(
            'petPhoto',
            'GET',
            '/pets/{id}/photo',
            mediaResponses: [new MediaResponseMetadata(new MediaType('image', 'png'), null)],
            jsonApiRank: 1,
            preconditionParameter: self::ID_PARAMETER,
        );
    }

    /**
     * a body brings a JSON:API Content-Type with it
     *
     * @param array<string, string>   $path
     * @param array<array-key, mixed> $query
     * @param array<string, string>   $headers
     */
    protected static function request(
        string $method,
        array $path = [],
        array $query = [],
        string $body = '',
        array $headers = [],
    ): ServerRequest {
        $fields = $body === '' ? [] : ['Content-Type' => self::JSON_API];

        return new ServerRequest($method, new Headers([...$fields, ...$headers]), $query, $path, $body);
    }

    /**
     * @param array<array-key, mixed> $query
     * @param array<string, string>   $headers
     */
    protected static function atomicRequest(string $body, array $query = [], array $headers = []): ServerRequest
    {
        return new ServerRequest(
            'POST',
            new Headers(['Content-Type' => self::ATOMIC, ...$headers]),
            $query,
            [],
            $body,
        );
    }

    protected static function removeDocument(): string
    {
        return '{"atomic:operations": [{"op": "remove", "ref": {"type": "pets", "id": "1"}}]}';
    }

    protected static function createBody(string $type): string
    {
        return '{"data": {"type": "' . $type . '", "attributes": {"description": "a description"}}}';
    }

    protected static function updateBody(string $type, string $id): string
    {
        return '{"data": {"type": "'
            . $type
            . '", "id": "'
            . $id
            . '", "attributes": {"description": "a description"}}}';
    }

    /**
     * over the id alone, the sort of a type that declares no default sort
     */
    protected static function cursor(): string
    {
        return CursorText::encode(new Cursor([], self::ID));
    }

    /**
     * @return array<array-key, object>
     */
    protected static function records(): array
    {
        return [
            self::ID => new Pet(self::ID, 'a name'),
            self::UUID => new Pet(self::UUID, 'a name'),
        ];
    }

    /**
     * @return ArrayObject<int, array{string, mixed}>
     */
    protected static function journal(): ArrayObject
    {
        return new ArrayObject();
    }

    /**
     * records each attempt as `level1` or `level2`, told apart by whether it carries a record
     *
     * @param ArrayObject<int, array{string, mixed}> $journal
     * @param ?Closure(OperationAttempt): bool       $permits
     */
    protected static function permission(ArrayObject $journal, ?Closure $permits): OperationPermission
    {
        return new class ($journal, $permits ?? static function (): bool {
            return true;
        }) implements OperationPermission {
            /**
             * @param ArrayObject<int, array{string, mixed}> $journal
             * @param Closure(OperationAttempt): bool        $permits
             */
            public function __construct(
                private readonly ArrayObject $journal,
                private readonly Closure $permits,
            ) {}

            #[Override]
            public function allows(OperationAttempt $attempt): bool
            {
                $this->journal[] = [$attempt->record === null ? 'level1' : 'level2', $attempt];

                return ($this->permits)($attempt);
            }
        };
    }

    /**
     * records each attempt as `endpointLevel1` or `endpointLevel2`,
     * told apart by whether it carries records
     *
     * @param ArrayObject<int, array{string, mixed}> $journal
     * @param ?Closure(EndpointAttempt): bool        $permits
     */
    protected static function endpointPermission(ArrayObject $journal, ?Closure $permits): EndpointPermission
    {
        return new class ($journal, $permits ?? static function (): bool {
            return true;
        }) implements EndpointPermission {
            /**
             * @param ArrayObject<int, array{string, mixed}> $journal
             * @param Closure(EndpointAttempt): bool         $permits
             */
            public function __construct(
                private readonly ArrayObject $journal,
                private readonly Closure $permits,
            ) {}

            #[Override]
            public function allows(EndpointAttempt $attempt): bool
            {
                $this->journal[] = [$attempt->records === null ? 'endpointLevel1' : 'endpointLevel2', $attempt];

                return ($this->permits)($attempt);
            }
        };
    }

    /**
     * @param ArrayObject<int, array{string, mixed}> $journal
     * @param ?Closure(Request): EndpointResponse              $handles
     */
    protected static function handler(ArrayObject $journal, ?Closure $handles): Handler
    {
        return new class ($journal, $handles ?? static function (Request $request): EndpointResponse {
            return EndpointResponse::resource($request->record(self::ID_PARAMETER));
        }) implements Handler {
            /**
             * @param ArrayObject<int, array{string, mixed}> $journal
             * @param Closure(Request): EndpointResponse               $handles
             */
            public function __construct(
                private readonly ArrayObject $journal,
                private readonly Closure $handles,
            ) {}

            #[Override]
            public function handle(Request $request): EndpointResponse
            {
                $this->journal[] = ['handle', $request];

                return ($this->handles)($request);
            }
        };
    }

    /**
     * @param ArrayObject<int, array{string, mixed}> $journal
     * @param array<array-key, object>               $records
     *
     * @return ResourceLoader<object>
     */
    protected static function loader(ArrayObject $journal, array $records): ResourceLoader
    {
        return new class ($journal, $records) implements ResourceLoader {
            /**
             * @param ArrayObject<int, array{string, mixed}> $journal
             * @param array<array-key, object>               $records
             */
            public function __construct(
                private readonly ArrayObject $journal,
                private readonly array $records,
            ) {}

            /**
             * @return list<?object>
             */
            #[Override]
            public function load(LoadRequest $request): array
            {
                $this->journal[] = ['load', $request];

                return array_map(function (string $id): ?object {
                    return $this->records[$id] ?? null;
                }, $request->ids);
            }
        };
    }

    /**
     * @param ArrayObject<int, array{string, mixed}> $journal
     * @param array<string, Throwable>               $failing
     */
    protected static function transactionBoundary(ArrayObject $journal, array $failing): TransactionBoundary
    {
        return new class ($journal, $failing) implements TransactionBoundary {
            /**
             * @param ArrayObject<int, array{string, mixed}> $journal
             * @param array<string, Throwable>               $failing
             */
            public function __construct(
                private readonly ArrayObject $journal,
                private readonly array $failing,
            ) {}

            /**
             * @throws Throwable
             */
            #[Override]
            public function begin(): void
            {
                $this->call('begin');
            }

            /**
             * @throws Throwable
             */
            #[Override]
            public function commit(): void
            {
                $this->call('commit');
            }

            /**
             * @throws Throwable
             */
            #[Override]
            public function rollBack(): void
            {
                $this->call('rollback');
            }

            /**
             * @throws Throwable
             */
            private function call(string $name): void
            {
                $this->journal[] = [$name, null];
                $failure = $this->failing[$name] ?? null;

                if ($failure !== null) {
                    throw $failure;
                }
            }
        };
    }

    /**
     * @param ArrayObject<int, array{string, mixed}> $journal
     * @param array<value-of<OperationKind>, Closure(Operation, ExecutionContext): Result> $results
     * @param Preconditions                          $preconditions
     *        writes the entity tag of each resource result
     *
     * @return array<value-of<OperationKind>, OperationProcessor>
     */
    protected static function processors(ArrayObject $journal, array $results, Preconditions $preconditions): array
    {
        $results = [...self::defaultResults($preconditions), ...$results];
        $processors = [];

        foreach (OperationKind::cases() as $kind) {
            $processors[$kind->value] = new class ($journal, $results[$kind->value]) implements OperationProcessor {
                /**
                 * @param ArrayObject<int, array{string, mixed}>       $journal
                 * @param Closure(Operation, ExecutionContext): Result $result
                 */
                public function __construct(
                    private readonly ArrayObject $journal,
                    private readonly Closure $result,
                ) {}

                #[Override]
                public function process(Operation $operation, ExecutionContext $context): Result
                {
                    $this->journal[] = ['process', [$operation, $context]];

                    return ($this->result)($operation, $context);
                }
            };
        }

        return $processors;
    }

    /**
     * @return array<value-of<OperationKind>, Closure(Operation, ExecutionContext): Result>
     */
    protected static function defaultResults(Preconditions $preconditions): array
    {
        $collection = static function (): Result {
            return CollectionResult::paged(
                self::TYPE,
                self::page(),
                new RecordGraph(false),
                new SparseFieldsets([]),
            );
        };
        $relationship = static function (): Result {
            return new RelationshipResult(
                self::TYPE,
                self::ID,
                self::RELATED,
                self::TYPE,
                RecordLinkage::toMany(self::page()),
                new RecordGraph(false),
                new SparseFieldsets([]),
                null,
            );
        };
        $noContent = static function (): Result {
            return new NoContentResult();
        };

        return [
            OperationKind::FetchCollection->value => $collection,
            OperationKind::FetchResource->value => static function (
                Operation $operation,
                ExecutionContext $context,
            ) use ($preconditions): Result {
                return self::resourceResult($operation, $context, 200, $preconditions);
            },
            OperationKind::FetchRelated->value => $collection,
            OperationKind::FetchRelationship->value => $relationship,
            OperationKind::CreateResource->value => static function (
                Operation $operation,
                ExecutionContext $context,
            ) use ($preconditions): Result {
                return self::resourceResult($operation, $context, 201, $preconditions);
            },
            OperationKind::UpdateResource->value => static function (
                Operation $operation,
                ExecutionContext $context,
            ) use ($preconditions): Result {
                return self::resourceResult($operation, $context, 200, $preconditions);
            },
            OperationKind::DeleteResource->value => $noContent,
            OperationKind::ReplaceRelationship->value => $noContent,
            OperationKind::AddToRelationship->value => $noContent,
            OperationKind::RemoveFromRelationship->value => $noContent,
        ];
    }

    protected static function resourceResult(
        Operation $operation,
        ExecutionContext $context,
        int $status,
        Preconditions $preconditions,
    ): ResourceResult {
        $record = $context->record ?? new Pet(self::ID, 'a name');
        $id = $record instanceof Pet ? $record->id : self::ID;

        return new ResourceResult(
            $operation->type(),
            new ResourceRecord($operation->type(), $id, $record),
            new RecordGraph(false),
            new SparseFieldsets([]),
            $status,
            $preconditions->entityTag($operation->type(), $record, $context),
        );
    }

    protected static function page(): Page
    {
        return new Page(
            [new ResourceRecord(self::TYPE, self::ID, new Pet(self::ID, 'a name'))],
            Strategy::PageNumber,
            2,
            1,
            null,
            false,
            false,
            false,
            null,
            null,
            null,
            null,
        );
    }

    protected static function uiLocaleThenField(): LanguageSources
    {
        return new LanguageSources([
            LanguageSourceMetadata::queryParameter('uiLocale'),
            LanguageSourceMetadata::acceptLanguage(),
        ]);
    }

    /**
     * @param ArrayObject<int, array{string, mixed}> $journal
     */
    protected static function contentLanguage(ArrayObject $journal, ?string $tag): ContentLanguage
    {
        return new class ($journal, $tag) implements ContentLanguage {
            /**
             * @param ArrayObject<int, array{string, mixed}> $journal
             */
            public function __construct(
                private readonly ArrayObject $journal,
                private readonly ?string $tag,
            ) {}

            #[Override]
            public function negotiate(LanguagePreferences $preferences): ?string
            {
                $this->journal[] = ['language', $preferences];

                return $this->tag;
            }
        };
    }

    /**
     * @param ArrayObject<int, array{string, mixed}> $journal
     */
    protected static function logger(ArrayObject $journal): LoggerInterface
    {
        return new class ($journal) extends AbstractLogger {
            /**
             * @param ArrayObject<int, array{string, mixed}> $journal
             */
            public function __construct(private readonly ArrayObject $journal) {}

            /**
             * @param mixed[] $context
             */
            #[Override]
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->journal[] = ['log', [$level, (string) $message, $context]];
            }
        };
    }

    /**
     * typed as the element of the mapper list
     *
     * @return ExceptionMapper<Throwable>
     */
    protected static function mapper(): ExceptionMapper
    {
        return new class implements ExceptionMapper {
            /**
             * @return class-string<DomainException>
             */
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
    }

    protected static function refusedAuthorization(
        Server $server,
        Route $route,
        ServerRequest $request,
    ): Response {
        $response = $server->authorize($route, $request);

        self::assertNotNull($response, 'the server let an attempt through that it refuses');

        return $response;
    }

    /**
     * @param ArrayObject<int, array{string, mixed}> $journal
     *
     * @return list<string>
     */
    protected static function steps(ArrayObject $journal): array
    {
        return array_map(
            static function (array $entry): string {
                return $entry[0];
            },
            array_values($journal->getArrayCopy()),
        );
    }

    /**
     * @param ArrayObject<int, array{string, mixed}> $journal
     */
    protected static function occurrences(ArrayObject $journal, string $name): int
    {
        return count(array_filter(self::steps($journal), static function (string $step) use ($name): bool {
            return $step === $name;
        }));
    }

    /**
     * @param ArrayObject<int, array{string, mixed}> $journal
     *
     * @return list<mixed>
     */
    protected static function payloads(ArrayObject $journal, string $name): array
    {
        $payloads = [];

        foreach ($journal as $entry) {
            if ($entry[0] === $name) {
                $payloads[] = $entry[1];
            }
        }

        return $payloads;
    }

    /**
     * @param ArrayObject<int, array{string, mixed}> $journal
     */
    protected static function attempted(ArrayObject $journal, string $level, int $position = 0): OperationAttempt
    {
        $attempt = self::payloads($journal, $level)[$position] ?? null;

        self::assertInstanceOf(OperationAttempt::class, $attempt);

        return $attempt;
    }

    /**
     * @param ArrayObject<int, array{string, mixed}> $journal
     */
    protected static function loaded(ArrayObject $journal, int $position = 0): LoadRequest
    {
        $load = self::payloads($journal, 'load')[$position] ?? null;

        self::assertInstanceOf(LoadRequest::class, $load);

        return $load;
    }

    /**
     * @param ArrayObject<int, array{string, mixed}> $journal
     *
     * @return array{Operation, ExecutionContext}
     */
    protected static function processed(ArrayObject $journal, int $position = 0): array
    {
        $entry = self::payloads($journal, 'process')[$position] ?? null;

        self::assertIsArray($entry);

        $operation = $entry[0] ?? null;
        $context = $entry[1] ?? null;

        self::assertInstanceOf(Operation::class, $operation);
        self::assertInstanceOf(ExecutionContext::class, $context);

        return [$operation, $context];
    }

    /**
     * @param ArrayObject<int, array{string, mixed}> $journal
     */
    protected static function preferences(ArrayObject $journal): LanguagePreferences
    {
        $preferences = self::payloads($journal, 'language')[0] ?? null;

        self::assertInstanceOf(LanguagePreferences::class, $preferences);

        return $preferences;
    }

    /**
     * @param ArrayObject<int, array{string, mixed}> $journal
     *
     * @return list<mixed>
     */
    protected static function loggedLevels(ArrayObject $journal): array
    {
        return array_map(
            static function (mixed $entry): mixed {
                self::assertIsArray($entry);

                return $entry[0] ?? null;
            },
            self::payloads($journal, 'log'),
        );
    }

    /**
     * @param ArrayObject<int, array{string, mixed}> $journal
     *
     * @return list<string>
     */
    protected static function loggedMessages(ArrayObject $journal): array
    {
        return array_map(
            static function (mixed $entry): string {
                self::assertIsArray($entry);

                $message = $entry[1] ?? null;

                self::assertIsString($message);

                return $message;
            },
            self::payloads($journal, 'log'),
        );
    }

    /**
     * @param ArrayObject<int, array{string, mixed}> $journal
     *
     * @return list<mixed>
     */
    protected static function loggedThrowables(ArrayObject $journal): array
    {
        return array_map(
            static function (mixed $entry): mixed {
                self::assertIsArray($entry);

                $context = $entry[2] ?? null;

                self::assertIsArray($context);

                return $context['exception'] ?? null;
            },
            self::payloads($journal, 'log'),
        );
    }

    /**
     * @param ArrayObject<int, array{string, mixed}> $journal
     */
    protected static function assertLogicExceptionWasLogged(ArrayObject $journal): void
    {
        $logged = self::loggedThrowables($journal);

        self::assertCount(1, $logged);
        self::assertInstanceOf(LogicException::class, $logged[0]);
    }

    /**
     * @return list<mixed>
     */
    protected static function codes(Response $response): array
    {
        return self::members($response, 'code');
    }

    /**
     * @return list<mixed>
     */
    protected static function details(Response $response): array
    {
        return self::members($response, 'detail');
    }

    /**
     * @return list<mixed>
     */
    protected static function sources(Response $response): array
    {
        return self::members($response, 'source');
    }

    /**
     * @return list<mixed>
     */
    protected static function headersOf(Response $response): array
    {
        return array_map(
            static function (mixed $source): mixed {
                self::assertIsArray($source);

                return $source['header'] ?? null;
            },
            self::sources($response),
        );
    }

    /**
     * a member of the `jsonapi` object: the extensions or the profiles the document applied
     *
     * @return list<mixed>
     */
    protected static function applied(Response $response, string $member): array
    {
        self::assertNotNull($response->document);

        $jsonApi = $response->document['jsonapi'] ?? null;

        self::assertIsArray($jsonApi);

        $applied = $jsonApi[$member] ?? [];

        self::assertIsArray($applied);

        return array_values($applied);
    }

    /**
     * @return list<mixed>
     */
    protected static function members(Response $response, string $member): array
    {
        self::assertNotNull($response->document);

        $errors = $response->document['errors'] ?? null;

        self::assertIsArray($errors);

        return array_map(
            static function (mixed $error) use ($member): mixed {
                self::assertIsArray($error);

                return $error[$member] ?? null;
            },
            array_values($errors),
        );
    }

    /**
     * @return list<string>
     */
    protected static function challengesOf(Response $response): array
    {
        return $response->headers->values('WWW-Authenticate');
    }

    protected static function selfLink(Response $response): mixed
    {
        self::assertNotNull($response->document);

        $links = $response->document['links'] ?? null;

        self::assertInstanceOf(stdClass::class, $links);

        return $links->self ?? null;
    }

    /**
     * @param list<ReflectionParameter> $parameters
     *
     * @return list<string>
     */
    protected static function types(array $parameters): array
    {
        return array_map(
            static function (ReflectionParameter $parameter): string {
                return (string) $parameter->getType();
            },
            $parameters,
        );
    }
}
