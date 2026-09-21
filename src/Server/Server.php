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

namespace Polunich\WpJsonApi\Server;

use InvalidArgumentException;
use JsonException;
use LogicException;
use Polunich\WpJsonApi\Access\DenialPolicy;
use Polunich\WpJsonApi\Access\EndpointAttempt;
use Polunich\WpJsonApi\Access\Gate;
use Polunich\WpJsonApi\Access\OperationAttempt;
use Polunich\WpJsonApi\Authentication\ChallengeProvider;
use Polunich\WpJsonApi\Authentication\Identity;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Document\DocumentEncoder;
use Polunich\WpJsonApi\Error\DefaultExceptionMapper;
use Polunich\WpJsonApi\Error\ErrorCatalog;
use Polunich\WpJsonApi\Error\ErrorCode;
use Polunich\WpJsonApi\Error\ErrorDescription;
use Polunich\WpJsonApi\Error\ErrorFactory;
use Polunich\WpJsonApi\Error\ExceptionMapper;
use Polunich\WpJsonApi\Error\ExceptionMappers;
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Error\LibraryErrorCatalog;
use Polunich\WpJsonApi\Error\StatusSelector;
use Polunich\WpJsonApi\Execution\Atomic\AtomicExecutor;
use Polunich\WpJsonApi\Execution\Atomic\OperationFailedException;
use Polunich\WpJsonApi\Execution\EndpointExecutor;
use Polunich\WpJsonApi\Execution\Executor;
use Polunich\WpJsonApi\Execution\IncludeLoader;
use Polunich\WpJsonApi\Execution\Paginator;
use Polunich\WpJsonApi\Execution\Preconditions;
use Polunich\WpJsonApi\Execution\Processor\AcceptedWrites;
use Polunich\WpJsonApi\Execution\Processor\CreateResourceProcessor;
use Polunich\WpJsonApi\Execution\Processor\DeleteResourceProcessor;
use Polunich\WpJsonApi\Execution\Processor\FetchCollectionProcessor;
use Polunich\WpJsonApi\Execution\Processor\FetchRelatedProcessor;
use Polunich\WpJsonApi\Execution\Processor\FetchRelationshipProcessor;
use Polunich\WpJsonApi\Execution\Processor\FetchResourceProcessor;
use Polunich\WpJsonApi\Execution\Processor\RelationshipWriteProcessor;
use Polunich\WpJsonApi\Execution\Processor\UpdateResourceProcessor;
use Polunich\WpJsonApi\Execution\StoreGateway;
use Polunich\WpJsonApi\Http\MediaType;
use Polunich\WpJsonApi\Http\Response;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Localization\AcceptLanguageParser;
use Polunich\WpJsonApi\Localization\ContentLanguage;
use Polunich\WpJsonApi\Metadata\ApiMetadata;
use Polunich\WpJsonApi\Metadata\AtomicRoute;
use Polunich\WpJsonApi\Metadata\EndpointRoute;
use Polunich\WpJsonApi\Metadata\ImplementationResolver;
use Polunich\WpJsonApi\Metadata\OperationRoute;
use Polunich\WpJsonApi\Metadata\Route;
use Polunich\WpJsonApi\Metadata\RouteTable;
use Polunich\WpJsonApi\OpenApi\OpenApiBuilder;
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
use Polunich\WpJsonApi\Rendering\DocumentBuilder;
use Polunich\WpJsonApi\Rendering\ErrorResponseBuilder;
use Polunich\WpJsonApi\Rendering\LinkQuery;
use Polunich\WpJsonApi\Rendering\PaginationWriter;
use Polunich\WpJsonApi\Rendering\ResourceSerializer;
use Polunich\WpJsonApi\Rendering\ResponseBuilder;
use Polunich\WpJsonApi\Rendering\RouteUrls;
use Polunich\WpJsonApi\Routing\UrlGenerator;
use Polunich\WpJsonApi\Text\Printable;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * the entry an adapter calls; `handle()` passes a request to the pipeline of its route,
 * which orders the steps.
 *
 * each check belongs to the class that makes it, and no step runs twice per request:
 * one negotiation, one read of the language of the request, one content language,
 * one load of the target, one check at each permission level.
 *
 * every error it responds with passes `Access\DenialPolicy::rewriteUnauthorized()` exactly once,
 * so no 401 leaves without a challenge, whichever class raised it (RFC 9110, section 15.5.2).
 *
 * it is the one core class that logs, and it logs a 5xx alone:
 * a 4xx is a response to a client, and logging each one would let a scan of the API flood the log
 *
 * @internal
 */
final readonly class Server
{
    public function __construct(
        private ApiMetadata $api,
        private Gate $gate,
        private OperationPipeline $operations,
        private AtomicPipeline $atomic,
        private EndpointPipeline $endpoints,
        private ErrorResponseBuilder $errorResponses,
        private ExceptionMappers $mappers,
        private DenialPolicy $denials,
        private OpenApiBuilder $openApi,
        private LoggerInterface $logger,
    ) {}

    /**
     * the whole object graph over a declaration, from the ports a composition root supplies.
     * the graph is the same whatever those ports are, so doubles run the production wiring
     *
     * @param list<ExceptionMapper<Throwable>> $exceptionMappers
     *
     * @throws InvalidArgumentException when `Error\ExceptionMappers` refuses a mapper
     */
    public static function compose(
        ApiMetadata $api,
        ImplementationResolver $implementations,
        UrlGenerator $urls,
        Identity $identity,
        ChallengeProvider $challenges,
        ?ContentLanguage $contentLanguage,
        ?ErrorCatalog $errorCatalog,
        array $exceptionMappers,
        bool $debugOutput,
        LoggerInterface $logger,
    ): self {
        $resources = $api->resources;
        $denials = new DenialPolicy($identity, $challenges);
        $gate = new Gate($resources, $implementations, $denials);
        $preconditions = new Preconditions($resources);
        $store = new StoreGateway($resources, $implementations);
        $paginator = new Paginator($resources, $store);
        $includes = new IncludeLoader($resources, $store, $paginator);
        $accepted = new AcceptedWrites($resources, $includes);
        $relationshipFetch = new FetchRelationshipProcessor($resources, $store, $paginator, $includes, $preconditions);
        $relationshipWrite = new RelationshipWriteProcessor($resources, $store, $accepted, $relationshipFetch);
        $executor = new Executor($resources, $store, $gate, [
            OperationKind::FetchCollection->value => new FetchCollectionProcessor(
                $resources,
                $store,
                $paginator,
                $includes,
            ),
            OperationKind::FetchResource->value => new FetchResourceProcessor($resources, $includes, $preconditions),
            OperationKind::FetchRelated->value => new FetchRelatedProcessor($resources, $store, $paginator, $includes),
            OperationKind::FetchRelationship->value => $relationshipFetch,
            OperationKind::CreateResource->value => new CreateResourceProcessor(
                $resources,
                $store,
                $includes,
                $preconditions,
                $accepted,
            ),
            OperationKind::UpdateResource->value => new UpdateResourceProcessor(
                $resources,
                $store,
                $includes,
                $preconditions,
                $accepted,
            ),
            OperationKind::DeleteResource->value => new DeleteResourceProcessor($store, $accepted),
            OperationKind::ReplaceRelationship->value => $relationshipWrite,
            OperationKind::AddToRelationship->value => $relationshipWrite,
            OperationKind::RemoveFromRelationship->value => $relationshipWrite,
        ]);
        $routeUrls = new RouteUrls($api->routes, $urls);
        $paginationWriter = new PaginationWriter($routeUrls, $api->naming);
        $negotiator = new Negotiator();
        $guard = new QueryParameterGuard($api->languageSources->queryParameters());
        $includeParser = new IncludeParser($resources);
        $fieldsets = new FieldsetParser($resources);
        $json = new JsonBodyDecoder();
        $documents = new DocumentParser($resources);
        $pages = new PageParser($api->naming);
        $collections = new CollectionReadingParser(
            new FilterParser($api),
            new SortParser($resources),
            $pages,
            new CursorConditions($resources, $api->naming),
        );
        $statuses = new StatusSelector();
        $encoder = new DocumentEncoder();

        $languageReader = new LanguageReader($api->languageSources, new AcceptLanguageParser());
        $responses = new ResponseBuilder(
            new DocumentBuilder(
                new ResourceSerializer($resources, $api->routes, $routeUrls, $paginationWriter),
                $paginationWriter,
                $routeUrls,
                $api->routes,
                new LinkQuery($resources, $api->languageSources),
            ),
            $encoder,
            $api->languageSources,
        );

        return new self(
            $api,
            $gate,
            new OperationPipeline(
                $negotiator,
                new OperationParser(
                    $resources,
                    $guard,
                    $collections,
                    $pages,
                    $includeParser,
                    $fieldsets,
                    $json,
                    $documents,
                    $statuses,
                ),
                $languageReader,
                $contentLanguage,
                $preconditions,
                $executor,
                $responses,
            ),
            new AtomicPipeline(
                $negotiator,
                new AtomicParser($resources, $guard, $fieldsets, $json, $documents, $statuses),
                $languageReader,
                $contentLanguage,
                $preconditions,
                new AtomicExecutor($api, $implementations, $gate, $preconditions, $executor),
                $responses,
            ),
            new EndpointPipeline(
                new EndpointParser(
                    $resources,
                    $negotiator,
                    $guard,
                    $includeParser,
                    $fieldsets,
                    $collections,
                    $json,
                    $documents,
                    $statuses,
                ),
                $languageReader,
                $contentLanguage,
                $preconditions,
                new EndpointExecutor(
                    $resources,
                    $store,
                    $gate,
                    $implementations,
                    $includes,
                    $preconditions,
                    $paginator,
                ),
                $responses,
            ),
            new ErrorResponseBuilder(
                new ErrorFactory(new LibraryErrorCatalog(), $errorCatalog),
                $encoder,
                $api->languageSources,
            ),
            new ExceptionMappers($exceptionMappers, new DefaultExceptionMapper($debugOutput)),
            $denials,
            new OpenApiBuilder($api),
            $logger,
        );
    }

    /**
     * @return list<Route>
     */
    public function routes(): array
    {
        return $this->api->routes->all();
    }

    /**
     * level 1 of the permission, before anything of the request is parsed:
     * it reads neither the query nor the body and asks no store.
     *
     * an atomic route passes unchecked: its kinds stand in its document,
     * and `Execution\Atomic\AtomicExecutor` checks each one before the transaction begins.
     *
     * a refusal at this level may say that the kind is not offered or not permitted,
     * never whether the id names a resource: the load of the target tells that later,
     * with the same 404 for a missing record and for one the client may not see.
     *
     * a refusal is returned, not thrown, so the adapter can send the rendered document
     * in place of the error shape of its platform
     *
     * @return ?Response null when level 1 lets the request through, else the error response
     *
     * @throws Throwable what logging, the challenge provider or the error rendering raised:
     *                   no response can carry it
     */
    public function authorize(Route $route, ServerRequest $request): ?Response
    {
        $schemes = $this->api->securitySchemesOf($route);

        try {
            if ($route instanceof EndpointRoute) {
                $this->gate->checkEndpoint(
                    $route->endpoint,
                    EndpointAttempt::forEndpoint($route->endpoint->name, $request),
                    $schemes,
                );
            } elseif ($route instanceof OperationRoute) {
                $this->gate->check(OperationAttempt::forKind(
                    $route->operationKind,
                    $route->type,
                    $route->relationship,
                    $request,
                ), $schemes);
            }
        } catch (Throwable $failure) {
            // a project's permission implementation may throw anything,
            // and the adapter still needs a response
            return $this->routeError($this->asError($failure, $request, $schemes), $request, $route);
        }

        return null;
    }

    /**
     * the response to a request `authorize()` let through;
     * every throwable becomes an error document
     *
     * @throws Throwable what the error rendering raised: no response can carry it
     */
    public function handle(Route $route, ServerRequest $request): Response
    {
        $contentType = null;
        $schemes = $this->api->securitySchemesOf($route);

        try {
            if (!$route->respondsTo($request->method)) {
                // the parser derives the kind from the method,
                // so a method the route does not respond to would run a kind never authorized.
                // the adapter picked the wrong route, so this is a 500:
                // a 405 could list the refused method, as `Allow` names every method of the path.
                // checked before the negotiation, so no 406 keeps the fault out of the log
                throw new LogicException(sprintf(
                    'this %s responds to %s and was dispatched a %s request, which is a'
                    . ' fault of the adapter and of no client',
                    $route->subject(),
                    implode(', ', $route->methods),
                    Printable::of($request->method),
                ));
            }

            if ($route instanceof EndpointRoute) {
                $negotiation = $this->endpoints->negotiate($route, $request);
                $contentType = $negotiation->documentContentType();

                return $this->endpoints->respond($route, $request, $negotiation, $schemes);
            }

            if ($route instanceof AtomicRoute) {
                $negotiation = $this->atomic->negotiate($route, $request);
                $contentType = $negotiation->documentContentType();

                return $this->atomic->respond($route, $request, $negotiation, $schemes);
            }

            $negotiation = $this->operations->negotiate($route, $request);
            $contentType = $negotiation->documentContentType();

            return $this->operations->respond($route, $request, $negotiation, $schemes);
        } catch (Throwable $failure) {
            return $this->failure($failure, $request, $contentType, $route);
        }
    }

    /**
     * an error the platform wrote for a request on the namespace of the API,
     * rewritten as a JSON:API error document.
     *
     * no throwable is involved, so nothing is mapped or logged; the 401 rule still applies
     *
     * @param int           $status  400 or above; one above 599 becomes 500
     * @param string        $code    kept as the error code;
     *                               the empty one takes the generic code of the status
     * @param string        $message becomes the detail; the empty one writes none
     * @param ServerRequest $request the challenge provider reads it
     * @param ?Route        $route   any route on the path the request addressed;
     *                               its path gives a 405 its `Allow`.
     *                               a 401 challenges the schemes of the route
     *                               where it responds to the method, else those of the API,
     *                               so an adapter prefers the route of the method.
     *                               null writes an empty `Allow`, which claims no method,
     *                               so an adapter passes one wherever its patterns matched
     *
     * @throws InvalidArgumentException when the status is below 400, which is no error
     */
    public function convertForeignError(
        int $status,
        string $code,
        string $message,
        ServerRequest $request,
        ?Route $route = null,
    ): Response {
        if ($status < 400) {
            throw new InvalidArgumentException(sprintf(
                'a foreign response is converted where it is an error, which is the status 400 and above,'
                . ' got %d',
                $status,
            ));
        }

        // RFC 9110 section 15 calls a status above 599 invalid, to be processed as a 5xx,
        // and `Error\JsonApiException` refuses it
        if ($status > 599) {
            $status = 500;
        }

        $description = new ErrorDescription(
            $code === '' ? ErrorCode::ofStatus($status) : $code,
            detail: $message === '' ? null : $message,
        );
        return $this->routeError(
            $this->denials->rewriteUnauthorized(
                $request,
                $this->api->securitySchemesOf($route?->respondsTo($request->method) === true ? $route : null),
                JsonApiException::ofStatus($status, [$description]),
            ),
            $request,
            $route,
        );
    }

    /**
     * @throws JsonException when a string of the declaration is no valid UTF-8
     */
    public function openApiJson(): string
    {
        return $this->openApi->json();
    }

    /**
     * the 401 rewrite comes after the mappers, since a project's mapper may return a 401,
     * and last, since nothing after it changes the status
     *
     * @param list<SecurityScheme> $schemes those of the route, which a 401 challenges
     */
    private function asError(Throwable $failure, ServerRequest $request, array $schemes): JsonApiException
    {
        $error = $this->mappers->map($failure);

        if ($error->status() >= 500) {
            // `error` is the PSR-3 level for runtime errors that need no immediate action.
            // the message is the mapped exception's, which is written for logs,
            // and PSR-3 wants the throwable itself under the `exception` key
            $this->logger->error($error->getMessage(), ['exception' => $failure]);
        }

        return $this->denials->rewriteUnauthorized($request, $schemes, $error);
    }

    /**
     * the error response where nothing was negotiated, in the media type of the route:
     * an error of an atomic route carries the atomic extension,
     * like the errors the library raises there itself
     *
     * @throws Throwable what the error rendering raised
     */
    private function routeError(JsonApiException $error, ServerRequest $request, ?Route $route): Response
    {
        return $this->errorResponses->response(
            $error,
            $request,
            $route === null
                ? null
                : MediaType::jsonApi(self::extensionsOn($route)),
            null,
            $error->status() === 405 ? $this->api->routes->allowedMethods($route) : [],
            $error->status() === 415 && $route !== null ? self::readableOn($route) : [],
        );
    }

    /**
     * the media types of the content a route reads, which a 415 lists in `Accept`
     *
     * @return list<MediaType>
     */
    private static function readableOn(Route $route): array
    {
        if ($route instanceof EndpointRoute) {
            return $route->endpoint->readableMediaTypes();
        }

        return Negotiator::readableOn($route->routeKind);
    }

    /**
     * @return list<string>
     */
    private static function extensionsOn(Route $route): array
    {
        if ($route instanceof EndpointRoute) {
            return [];
        }

        return Negotiator::extensionsOf($route->routeKind);
    }

    /**
     * the rendering is not guarded: a fallback document written by hand would bypass the catalog
     * and the encoder, and it is exactly those that failed
     */
    private function failure(
        Throwable $failure,
        ServerRequest $request,
        ?MediaType $contentType,
        Route $route,
    ): Response {
        $position = null;

        if ($failure instanceof OperationFailedException) {
            // unwrapped, never mapped: the internal carrier would map to a 500.
            // its position turns the relative pointer of the failure into `/atomic:operations/<n>`
            $position = $failure->position;
            $failure = $failure->failure;
        }

        $error = $this->asError($failure, $request, $this->api->securitySchemesOf($route));

        return $this->errorResponses->response(
            $error,
            $request,
            $contentType,
            $position,
            // the library raises no 405, but project code may throw one or map to one
            $error->status() === 405 ? $this->api->routes->allowedMethods($route) : [],
            $error->status() === 415 ? self::readableOn($route) : [],
        );
    }
}
