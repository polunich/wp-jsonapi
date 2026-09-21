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

use LogicException;
use Polunich\WpJsonApi\Authentication\SecurityScheme;
use Polunich\WpJsonApi\Endpoint\Request;
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Execution\EndpointExecutor;
use Polunich\WpJsonApi\Execution\ExecutionContext;
use Polunich\WpJsonApi\Execution\Preconditions;
use Polunich\WpJsonApi\Http\Response;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Localization\ContentLanguage;
use Polunich\WpJsonApi\Metadata\EndpointRoute;
use Polunich\WpJsonApi\Parsing\CursorProfile;
use Polunich\WpJsonApi\Parsing\EndpointParser;
use Polunich\WpJsonApi\Parsing\LanguageReader;
use Polunich\WpJsonApi\Parsing\Negotiation;
use Polunich\WpJsonApi\Query\ParameterFamily;
use Polunich\WpJsonApi\Rendering\ResponseBuilder;
use Throwable;

/**
 * @internal
 */
final readonly class EndpointPipeline
{
    public function __construct(
        private EndpointParser $parser,
        private LanguageReader $languageReader,
        private ?ContentLanguage $contentLanguage,
        private Preconditions $preconditions,
        private EndpointExecutor $executor,
        private ResponseBuilder $responses,
    ) {}

    /**
     * @throws JsonApiException 406 when `Accept` admits no representation,
     *                          415 for content the endpoint does not read
     */
    public function negotiate(EndpointRoute $route, ServerRequest $request): Negotiation
    {
        return $this->parser->negotiate($request, $route->endpoint);
    }

    /**
     * the steps are ordered by RFC 9110, section 13.2.1, as for a single operation:
     * the path and the query, the bound records with their 404 and level 2 of the permission,
     * then the preconditions of the precondition record,
     * and the body "just before it would process the request content";
     * the handler runs last
     *
     * @param Negotiation          $negotiation     what `negotiate()` returned
     * @param list<SecurityScheme> $securitySchemes those of the route, which a 401 challenges
     *
     * @throws JsonApiException every failure of the request
     * @throws LogicException   when the handler returns what the endpoint does not declare
     * @throws Throwable        what project code raised
     */
    public function respond(
        EndpointRoute $route,
        ServerRequest $request,
        Negotiation $negotiation,
        array $securitySchemes,
    ): Response {
        $endpoint = $route->endpoint;
        $preferences = $this->languageReader->read($request);
        $contentLanguage = $this->contentLanguage?->negotiate($preferences);
        $path = $this->parser->pathValues($endpoint, $request);
        $query = $this->parser->query($endpoint, $request, $negotiation);
        $documentType = $negotiation->documentContentType();
        $context = new ExecutionContext(
            $request,
            $documentType,
            $contentLanguage,
            ParameterFamily::Include->isIn($request->query),
            $negotiation->requestsProfile(CursorProfile::URI),
            $securitySchemes,
        );
        $records = $this->executor->loadRecords($endpoint, $path, $preferences, $query->queryParameters, $context);
        // a read that picked a media response checks no precondition against the record:
        // the record's tag belongs to its JSON:API representation
        $parameter = $endpoint->isSafe() && !$negotiation->contentType->isJsonApi()
            ? null
            : $endpoint->preconditionParameter;

        $notModified = $this->preconditions->evaluateEndpoint(
            $endpoint,
            $parameter === null ? null : $endpoint->bindings[$parameter],
            $parameter === null ? null : $records[$parameter],
            $context,
        );

        if ($notModified !== null) {
            return $this->responses->endpointResponse(
                $notModified,
                $endpoint,
                $request,
                $documentType,
                $contentLanguage,
            );
        }

        $body = $this->parser->body($endpoint, $request, $path);
        $precondition = $endpoint->writesPreconditionRecord() ? $this->preconditions->precondition($context) : null;

        $result = $this->executor->run(
            $endpoint,
            new Request(
                $endpoint->name,
                $path,
                $records,
                $query->queryParameters,
                $this->executor->collectionQuery(
                    $endpoint,
                    $query->reading,
                    $query->fields,
                    $preferences,
                    $query->queryParameters,
                ),
                $body->value,
                $preferences,
                $precondition,
                $request,
                $negotiation->representation()->toHeaderValue(),
                $body->mediaType,
            ),
            $query->include,
            $query->fields,
            $query->reading,
            $context,
        );

        return $this->responses->endpointResponse($result, $endpoint, $request, $documentType, $contentLanguage);
    }
}
