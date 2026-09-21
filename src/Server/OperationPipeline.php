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
use Polunich\WpJsonApi\Execution\ExecutionContext;
use Polunich\WpJsonApi\Execution\Executor;
use Polunich\WpJsonApi\Execution\Preconditions;
use Polunich\WpJsonApi\Http\Response;
use Polunich\WpJsonApi\Http\ServerRequest;
use Polunich\WpJsonApi\Localization\ContentLanguage;
use Polunich\WpJsonApi\Metadata\OperationRoute;
use Polunich\WpJsonApi\Operation\ConditionalWrite;
use Polunich\WpJsonApi\Operation\PendingWrite;
use Polunich\WpJsonApi\Parsing\CursorProfile;
use Polunich\WpJsonApi\Parsing\LanguageReader;
use Polunich\WpJsonApi\Parsing\Negotiation;
use Polunich\WpJsonApi\Parsing\Negotiator;
use Polunich\WpJsonApi\Parsing\OperationParser;
use Polunich\WpJsonApi\Query\ParameterFamily;
use Polunich\WpJsonApi\Rendering\ResponseBuilder;
use Throwable;

/**
 * @internal
 */
final readonly class OperationPipeline
{
    public function __construct(
        private Negotiator $negotiator,
        private OperationParser $parser,
        private LanguageReader $languageReader,
        private ?ContentLanguage $contentLanguage,
        private Preconditions $preconditions,
        private Executor $executor,
        private ResponseBuilder $responses,
    ) {}

    /**
     * @throws JsonApiException 406 when `Accept` admits no JSON:API representation,
     *                          415 when the content is in a media type the route does not read
     */
    public function negotiate(OperationRoute $route, ServerRequest $request): Negotiation
    {
        return $this->negotiator->negotiateRoute($request, $route->routeKind);
    }

    /**
     * the order follows RFC 9110, section 13.2.1:
     * the query, the load with its 404 and the level-2 check come before the preconditions,
     * since "failures that can be detected before significant processing occurs
     * take precedence over the evaluation of preconditions",
     * and the content is read after them, "just before it would process the request content"
     *
     * @param Negotiation          $negotiation     what `negotiate()` returned
     * @param list<SecurityScheme> $securitySchemes those of the route, which a 401 challenges
     *
     * @throws JsonApiException every failure of the request
     * @throws LogicException   when the adapter dispatched a request its pattern does not capture
     * @throws Throwable        what project code raised
     */
    public function respond(
        OperationRoute $route,
        ServerRequest $request,
        Negotiation $negotiation,
        array $securitySchemes,
    ): Response {
        $kind = $route->operationKind;
        $preferences = $this->languageReader->read($request);
        // not in `Server::authorize()`, which would apply a locale for a request it then denies.
        // every kind resolves it, a delete included:
        // the entity tag the preconditions compare mixes the language
        $contentLanguage = $this->contentLanguage?->negotiate($preferences);
        $target = $route->target($request);
        $context = new ExecutionContext(
            $request,
            $negotiation->contentType,
            $contentLanguage,
            // presence alone: an empty `include` counts as supplied
            ParameterFamily::Include->isIn($request->query),
            $negotiation->requestsProfile(CursorProfile::URI),
            $securitySchemes,
        );
        // a content write comes back as a `PendingWrite`:
        // its content is read only after the preconditions hold
        $parsed = $this->parser->parse($request, $target, $negotiation, $preferences);
        $record = null;

        if ($kind->actsOnExistingResource()) {
            $record = $this->executor->loadTarget(
                $this->executor->targetRequest($parsed),
                $kind,
                $route->relationship,
                $context,
            );
            $context = $context->withRecord($record);
        }

        $notModified = $this->preconditions->evaluate($kind, $route->type, $record, $context);

        if ($notModified !== null) {
            return $this->responses->response(
                $notModified,
                $target,
                $request,
                $negotiation->contentType,
                $contentLanguage,
            );
        }

        $operation = $parsed instanceof PendingWrite ? $this->parser->parseContent($request, $parsed) : $parsed;

        if ($operation instanceof ConditionalWrite) {
            $precondition = $this->preconditions->precondition($context);

            if ($precondition !== null) {
                $operation = $operation->withPrecondition($precondition);
            }
        }

        $result = $this->executor->execute($operation, $context);

        return $this->responses->response(
            $result,
            $target,
            $request,
            $negotiation->contentType,
            $contentLanguage,
        );
    }
}
