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
use Polunich\WpJsonApi\Error\JsonApiException;
use Polunich\WpJsonApi\Execution\Atomic\AtomicExecutor;
use Polunich\WpJsonApi\Execution\ExecutionContext;
use Polunich\WpJsonApi\Execution\Preconditions;
use Polunich\WpJsonApi\Http\Response;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Localization\ContentLanguage;
use Polunich\WpJsonApi\Metadata\AtomicRoute;
use Polunich\WpJsonApi\Parsing\AtomicParser;
use Polunich\WpJsonApi\Parsing\CursorProfile;
use Polunich\WpJsonApi\Parsing\LanguageReader;
use Polunich\WpJsonApi\Parsing\Negotiation;
use Polunich\WpJsonApi\Parsing\Negotiator;
use Polunich\WpJsonApi\Rendering\ResponseBuilder;
use Throwable;

/**
 * @internal
 */
final readonly class AtomicPipeline
{
    public function __construct(
        private Negotiator $negotiator,
        private AtomicParser $parser,
        private LanguageReader $languageReader,
        private ?ContentLanguage $contentLanguage,
        private Preconditions $preconditions,
        private AtomicExecutor $executor,
        private ResponseBuilder $responses,
    ) {}

    /**
     * @throws JsonApiException 406 when `Accept` admits no JSON:API representation,
     *                          415 when the content is in a media type the route does not read
     */
    public function negotiate(AtomicRoute $route, ServerRequest $request): Negotiation
    {
        return $this->negotiator->negotiateRoute($request, $route->routeKind);
    }

    /**
     * the order follows RFC 9110, section 13.2.1: the query, then `If-Match`,
     * then the document, "just before it would process the request content"
     *
     * @param Negotiation          $negotiation     what `negotiate()` returned
     * @param list<SecurityScheme> $securitySchemes those of the route, which a 401 challenges
     *
     * @throws JsonApiException every problem of the query or the document,
     *                          and the 412 of any If-Match: the route addresses no representation
     * @throws LogicException   when the API declares no transaction boundary
     * @throws Throwable        what project code raised
     */
    public function respond(
        AtomicRoute $route,
        ServerRequest $request,
        Negotiation $negotiation,
        array $securitySchemes,
    ): Response {
        $preferences = $this->languageReader->read($request);
        $contentLanguage = $this->contentLanguage?->negotiate($preferences);

        $fields = $this->parser->checkQuery($request);

        // `include` is never supplied:
        // `checkQuery()` refuses every parameter family but `fields` on this route
        $context = new ExecutionContext(
            $request,
            $negotiation->contentType,
            $contentLanguage,
            false,
            $negotiation->requestsProfile(CursorProfile::URI),
            $securitySchemes,
        );

        $this->preconditions->evaluateAtomicRoute($context);

        $operations = $this->parser->parse($request, $fields, $preferences, $route->metadata->types);
        // the context carries no position: the executor scopes each operation with `atPosition()`,
        // which makes `isAtomic()` true inside the transaction
        $result = $this->executor->execute($operations, $context);

        return $this->responses->response(
            $result,
            $route->target($request),
            $request,
            $negotiation->contentType,
            $contentLanguage,
        );
    }
}
